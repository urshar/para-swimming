<?php

namespace App\Livewire;

use App\Models\Meet;
use App\Models\StrokeType;
use App\Services\WpsClubRankingService;
use App\Support\WpsClubRankingConfiguration;
use App\Support\WpsClubRankingEntry;
use App\Support\WpsRankingFilter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * WpsClubRanking
 *
 * Vereinsauswertung (Spec "WPS Rankings" §9) — ein Analysewerkzeug, keine offizielle
 * ÖBSV-Wertung.
 *
 * Anders als Ranglisten und Athletenanalyse ist diese Ansicht **nicht** verbandsweit:
 * Vereinsnutzer sehen nur den eigenen Verein (**[R2]**).
 */
class WpsClubRanking extends Component
{
    public string $year = '';

    public string $course = WpsRankingFilter::COURSE_SCM;

    public string $strokeTypeId = '';

    public string $gender = '';

    public string $method = WpsClubRankingConfiguration::METHOD_SUM;

    public string $countedPerAthlete = '1';

    public string $threshold = '600';

    public string $minEntriesPerClub = '1';

    /** @var array<int, int> aufgeklappte Vereine */
    public array $expanded = [];

    private ?WpsClubRankingService $service = null;

    public function mount(): void
    {
        $this->year = (string) $this->availableYears()[0];
        $this->threshold = (string) WpsClubRankingConfiguration::DEFAULT_THRESHOLD;
    }

    public function setInput(string $feld, string $wert): void
    {
        match ($feld) {
            'year' => $this->year = $wert,
            'course' => $this->course = $wert,
            'strokeTypeId' => $this->strokeTypeId = $wert,
            'gender' => $this->gender = $wert,
            'method' => $this->method = $wert,
            'countedPerAthlete' => $this->countedPerAthlete = $wert,
            'threshold' => $this->threshold = $wert,
            'minEntriesPerClub' => $this->minEntriesPerClub = $wert,
            default => null,
        };

        // Aufgeklappte Vereine zurücksetzen: Nach einer Änderung zeigen sie auf eine
        // Aufschlüsselung, die es so nicht mehr gibt.
        $this->expanded = [];

        unset($this->ranking, $this->filter, $this->configuration);
    }

    public function toggle(int $clubId): void
    {
        $this->expanded = in_array($clubId, $this->expanded, true)
            ? array_values(array_diff($this->expanded, [$clubId]))
            : [...$this->expanded, $clubId];
    }

    public function isExpanded(int $clubId): bool
    {
        return in_array($clubId, $this->expanded, true);
    }

    #[Computed]
    public function filter(): WpsRankingFilter
    {
        return new WpsRankingFilter(
            year: $this->intOrNull($this->year),
            strokeTypeId: $this->intOrNull($this->strokeTypeId),
            gender: $this->gender,
            course: $this->course,
        );
    }

    #[Computed]
    public function configuration(): WpsClubRankingConfiguration
    {
        return new WpsClubRankingConfiguration(
            in_array($this->method, WpsClubRankingConfiguration::METHODS, true)
                ? $this->method
                : WpsClubRankingConfiguration::METHOD_SUM,
            max(1, (int) $this->countedPerAthlete),
            max(1, (int) $this->threshold),
            max(1, (int) $this->minEntriesPerClub),
        );
    }

    /** @return Collection<int, WpsClubRankingEntry> */
    #[Computed]
    public function ranking(): Collection
    {
        return $this->service()->ranking($this->filter(), $this->configuration(), $this->clubFilter());
    }

    /** @return Collection<int, WpsClubRankingEntry> */
    public function ranked(): Collection
    {
        return $this->ranking()->reject(
            static fn (WpsClubRankingEntry $e): bool => $e->isBelowMinimum()
        )->values();
    }

    /**
     * Vereine unterhalb der Mindestzahl — sie erscheinen unterhalb der Liste.
     *
     * Ein Verein mit einem einzigen starken Athleten soll sichtbar bleiben, statt still zu
     * verschwinden.
     *
     * @return Collection<int, WpsClubRankingEntry>
     */
    public function belowMinimum(): Collection
    {
        return $this->ranking()->filter(
            static fn (WpsClubRankingEntry $e): bool => $e->isBelowMinimum()
        )->values();
    }

    /** @return Collection<int, StrokeType> */
    #[Computed]
    public function strokeTypes(): Collection
    {
        return StrokeType::query()->orderBy('id')->get();
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

    public function pdfUrl(): string
    {
        $parameter = array_merge(
            $this->filter()->toQuery(),
            $this->configuration()->toQuery(),
        );

        return route('wps.clubs.pdf').($parameter === [] ? '' : '?'.http_build_query($parameter));
    }

    public function render(): View
    {
        return view('livewire.wps-club-ranking');
    }

    /** Vereinsnutzer sehen nur den eigenen Verein; Admins alle (**[R2]**). */
    private function clubFilter(): ?int
    {
        $nutzer = auth()->user();

        return $nutzer?->is_admin === true ? null : $nutzer?->club_id;
    }

    private function intOrNull(string $wert): ?int
    {
        $bereinigt = trim($wert);

        return $bereinigt === '' || ! is_numeric($bereinigt) ? null : (int) $bereinigt;
    }

    private function service(): WpsClubRankingService
    {
        return $this->service ??= app(WpsClubRankingService::class);
    }
}
