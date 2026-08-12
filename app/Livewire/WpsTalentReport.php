<?php

namespace App\Livewire;

use App\Models\Championship;
use App\Models\Meet;
use App\Services\WpsTalentReportService;
use App\Support\WpsRankingEntry;
use App\Support\WpsRankingFilter;
use App\Support\WpsTalentEntry;
use App\Support\WpsTalentReportConfiguration;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * WpsTalentReport
 *
 * Förderauswertung (Spec "WPS Rankings" §6.6).
 *
 * Rein lesend; die Ansicht steht allen Angemeldeten offen. Die Eingaben liegen als einzelne
 * skalare Eigenschaften vor — Livewire serialisiert nur solche — und werden in `config()` zum
 * unveränderlichen Konfigurationsobjekt zusammengesetzt, das Service und PDF gemeinsam
 * verwenden.
 */
class WpsTalentReport extends Component
{
    public string $fromYear = '';

    public string $toYear = '';

    public string $referenceId = '';

    public string $youthThreshold = '';

    public string $generalThreshold = '';

    public string $course = WpsRankingFilter::COURSE_SCM;

    public string $normType = WpsTalentReportConfiguration::NORM_MQS;

    private ?WpsTalentReportService $service = null;

    public function mount(): void
    {
        $jahr = $this->latestYearWithResults();

        $this->fromYear = (string) $jahr;
        $this->toYear = (string) $jahr;
        $this->youthThreshold = (string) WpsTalentReportConfiguration::DEFAULT_YOUTH_THRESHOLD;
        $this->generalThreshold = (string) WpsTalentReportConfiguration::DEFAULT_GENERAL_THRESHOLD;
        $this->referenceId = (string) ($this->service()->defaultReference()?->getKey() ?? '');
    }

    public function setInput(string $feld, string $wert): void
    {
        match ($feld) {
            'fromYear' => $this->fromYear = $wert,
            'toYear' => $this->toYear = $wert,
            'referenceId' => $this->referenceId = $wert,
            'youthThreshold' => $this->youthThreshold = $wert,
            'generalThreshold' => $this->generalThreshold = $wert,
            'course' => $this->course = $wert,
            'normType' => $this->normType = $wert,
            default => null,
        };

        // Ein Zeitraum, dessen Ende vor dem Beginn liegt, ergibt keine Auswertung. Statt einer
        // Fehlermeldung wird die jeweils andere Grenze nachgezogen — das ist die Absicht, die
        // dahintersteckt.
        if ($this->intOrNull($this->toYear) < $this->intOrNull($this->fromYear)) {
            $feld === 'fromYear'
                ? $this->toYear = $this->fromYear
                : $this->fromYear = $this->toYear;
        }

        unset($this->config, $this->report, $this->withoutBirthDate);
    }

    /** Null, solange keine Referenznorm gewählt ist — dann gibt es nichts auszuwerten. */
    #[Computed]
    public function config(): ?WpsTalentReportConfiguration
    {
        $referenz = $this->reference();

        if ($referenz === null) {
            return null;
        }

        $von = $this->intOrNull($this->fromYear) ?? $this->latestYearWithResults();
        $bis = $this->intOrNull($this->toYear) ?? $von;

        return new WpsTalentReportConfiguration(
            fromYear: $von,
            toYear: max($von, $bis),
            reference: $referenz,
            youthThreshold: $this->floatOr($this->youthThreshold, WpsTalentReportConfiguration::DEFAULT_YOUTH_THRESHOLD),
            generalThreshold: $this->floatOr($this->generalThreshold, WpsTalentReportConfiguration::DEFAULT_GENERAL_THRESHOLD),
            course: $this->course,
            normType: in_array($this->normType, WpsTalentReportConfiguration::NORM_TYPES, true)
                ? $this->normType
                : WpsTalentReportConfiguration::NORM_MQS,
        );
    }

    /** @return Collection<string, Collection<int, WpsTalentEntry>> */
    #[Computed]
    public function report(): Collection
    {
        $config = $this->config();

        return $config === null ? collect() : $this->service()->report($config);
    }

    /** @return Collection<int, WpsRankingEntry> */
    #[Computed]
    public function withoutBirthDate(): Collection
    {
        $config = $this->config();

        return $config === null ? collect() : $this->service()->withoutBirthDate($config);
    }

    /** @return Collection<int, Championship> */
    #[Computed]
    public function references(): Collection
    {
        return Championship::query()
            ->active()
            ->has('standards')
            ->orderByDesc('qualification_end')
            ->get();
    }

    /** @return list<int> */
    #[Computed]
    public function availableYears(): array
    {
        $jahre = Meet::query()
            ->whereNotNull('start_date')
            ->orderByDesc('start_date')
            ->pluck('start_date')
            ->map(static fn ($datum): int => (int) $datum->format('Y'))
            ->unique()
            ->values()
            ->all();

        return $jahre === [] ? [(int) date('Y')] : $jahre;
    }

    /** Abfrageparameter für den PDF-Link. */
    public function pdfUrl(): string
    {
        $parameter = array_filter([
            'from' => $this->fromYear,
            'to' => $this->toYear,
            'reference' => $this->referenceId,
            'youth' => $this->youthThreshold,
            'general' => $this->generalThreshold,
            'course' => $this->course,
            'norm' => $this->normType,
        ], static fn (string $wert): bool => $wert !== '');

        return route('wps.talent-report.pdf').($parameter === [] ? '' : '?'.http_build_query($parameter));
    }

    public function render(): View
    {
        return view('livewire.wps-talent-report');
    }

    private function reference(): ?Championship
    {
        $id = $this->intOrNull($this->referenceId);

        return $id === null ? null : $this->references()->firstWhere('id', $id);
    }

    private function latestYearWithResults(): int
    {
        return $this->availableYears()[0];
    }

    private function intOrNull(string $wert): ?int
    {
        $bereinigt = trim($wert);

        return $bereinigt === '' || ! is_numeric($bereinigt) ? null : (int) $bereinigt;
    }

    private function floatOr(string $wert, float $standard): float
    {
        $bereinigt = str_replace(',', '.', trim($wert));

        // Ein Prozentsatz außerhalb von 0 bis 100 ergibt keine sinnvolle Schwelle; statt einer
        // Fehlermeldung greift der Vorschlagswert.
        if (! is_numeric($bereinigt)) {
            return $standard;
        }

        $zahl = (float) $bereinigt;

        return $zahl > 0 && $zahl <= 100 ? $zahl : $standard;
    }

    private function service(): WpsTalentReportService
    {
        return $this->service ??= app(WpsTalentReportService::class);
    }
}
