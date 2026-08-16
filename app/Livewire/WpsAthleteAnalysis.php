<?php

namespace App\Livewire;

use App\Models\Athlete;
use App\Services\WpsAthleteAnalysisService;
use App\Support\WpsAthleteProfile;
use App\Support\WpsRankingFilter;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * WpsAthleteAnalysis
 *
 * Athletenprofil mit Leistungsentwicklung (Spec "WPS Rankings" §7).
 *
 * Zeigt standardmäßig die gesamte Historie; der Zeitraum lässt sich einschränken. Rein
 * lesend, verbandsweit wie die Ranglisten.
 */
class WpsAthleteAnalysis extends Component
{
    public Athlete $athlete;

    public string $fromYear = '';

    public string $toYear = '';

    public string $course = WpsRankingFilter::COURSE_MIXED;

    private ?WpsAthleteAnalysisService $service = null;

    public function mount(Athlete $athlete): void
    {
        $this->athlete = $athlete;
    }

    public function setInput(string $feld, string $wert): void
    {
        match ($feld) {
            'fromYear' => $this->fromYear = $wert,
            'toYear' => $this->toYear = $wert,
            'course' => $this->course = $wert,
            default => null,
        };

        // Ein Zeitraum, dessen Ende vor dem Beginn liegt, ergibt keine Auswertung; statt einer
        // Fehlermeldung wird die andere Grenze nachgezogen.
        $von = $this->intOrNull($this->fromYear);
        $bis = $this->intOrNull($this->toYear);

        if ($von !== null && $bis !== null && $bis < $von) {
            $feld === 'fromYear' ? $this->toYear = $this->fromYear : $this->fromYear = $this->toYear;
        }

        unset($this->profile);
    }

    public function resetPeriod(): void
    {
        $this->fromYear = '';
        $this->toYear = '';

        unset($this->profile);
    }

    #[Computed]
    public function profile(): WpsAthleteProfile
    {
        return $this->service()->profile(
            $this->athlete,
            $this->intOrNull($this->fromYear),
            $this->intOrNull($this->toYear),
            $this->course,
        );
    }

    /** @return list<int> */
    #[Computed]
    public function years(): array
    {
        return $this->service()->yearsWithResults($this->athlete);
    }

    public function pdfUrl(): string
    {
        $parameter = array_filter([
            'from' => $this->fromYear,
            'to' => $this->toYear,
            'course' => $this->course,
        ], static fn (string $wert): bool => $wert !== '');

        return route('wps.athletes.pdf', $this->athlete)
            .($parameter === [] ? '' : '?'.http_build_query($parameter));
    }

    public function render(): View
    {
        return view('livewire.wps-athlete-analysis');
    }

    private function intOrNull(string $wert): ?int
    {
        $bereinigt = trim($wert);

        return $bereinigt === '' || ! is_numeric($bereinigt) ? null : (int) $bereinigt;
    }

    private function service(): WpsAthleteAnalysisService
    {
        return $this->service ??= app(WpsAthleteAnalysisService::class);
    }
}
