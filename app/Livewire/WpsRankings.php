<?php

namespace App\Livewire;

use App\Models\Club;
use App\Models\KaderType;
use App\Models\Meet;
use App\Models\Result;
use App\Models\StrokeType;
use App\Services\AthleteKaderResolver;
use App\Services\WpsRankingService;
use App\Support\SportClassSorter;
use App\Support\WpsRankingEntry;
use App\Support\WpsRankingFilter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * WpsRankings
 *
 * Ranglistenansicht (Spec "WPS Rankings" §12). Vorbild: `StatisticsDashboard`.
 *
 * Die Filterfelder liegen als einzelne öffentliche Eigenschaften vor, weil Livewire nur
 * skalare Werte serialisiert; zusammengesetzt werden sie in `filter()` zum unveränderlichen
 * `WpsRankingFilter`, den Service und PDF gemeinsam verwenden.
 *
 * Rein lesend — die Ansicht steht allen Angemeldeten offen (**[R2]**); eine Einschränkung auf
 * den eigenen Verein gibt es hier bewusst nicht, Ranglisten sind verbandsweit.
 */
class WpsRankings extends Component
{
    use WithPagination;

    private const int PER_PAGE = 25;

    public string $type = WpsRankingFilter::TYPE_SEASON;

    public string $year = '';

    public string $meetId = '';

    public string $strokeTypeId = '';

    public string $distance = '';

    public string $gender = '';

    public string $sportClass = '';

    public string $course = WpsRankingFilter::COURSE_SCM;

    public string $clubId = '';

    public string $minPoints = '';

    public string $maxAge = '';

    public bool $includeExhibition = false;

    public string $calculationType = '';

    public string $kaderMode = WpsRankingFilter::KADER_ALL;

    /** @var array<int, int> gewählte Kaderarten; 0 steht für "ohne Zuordnung" */
    public array $kaderIds = [];

    private ?WpsRankingService $service = null;

    public function mount(): void
    {
        // Das jüngste Jahr MIT gewerteten Ergebnissen, nicht das laufende Kalenderjahr.
        // Sonst zeigt die Auswahlliste ihren ersten Eintrag (etwa 2024), während intern
        // das laufende Jahr gefiltert wird — die Liste bliebe leer, ohne dass erkennbar
        // wäre, warum.
        $this->year = (string) $this->availableYears()[0];
    }

    public function setFilter(string $feld, string $wert): void
    {
        // Whitelist statt dynamischer Zuweisung: Ohne sie ließe sich über den Feldnamen jede
        // öffentliche Eigenschaft der Komponente von außen setzen.
        match ($feld) {
            'type' => $this->type = $wert,
            'year' => $this->year = $wert,
            'meetId' => $this->meetId = $wert,
            'strokeTypeId' => $this->strokeTypeId = $wert,
            'distance' => $this->distance = $wert,
            'gender' => $this->gender = $wert,
            'sportClass' => $this->sportClass = $wert,
            'course' => $this->course = $wert,
            'clubId' => $this->clubId = $wert,
            'minPoints' => $this->minPoints = $wert,
            'maxAge' => $this->maxAge = $wert,
            'calculationType' => $this->calculationType = $wert,
            'kaderMode' => $this->kaderMode = $wert,
            default => null,
        };

        $this->afterFilterChange();
    }

    /**
     * Kaderart zur Auswahl hinzufügen oder daraus entfernen.
     *
     * Wird die letzte Kaderart abgewählt, fällt der Filter auf "alle" zurück: Ein gesetzter
     * Modus ohne Auswahl wäre wirkungslos und sähe trotzdem nach einer Einschränkung aus.
     */
    public function toggleKader(int $kaderTypeId): void
    {
        $this->kaderIds = in_array($kaderTypeId, $this->kaderIds, true)
            ? array_values(array_diff($this->kaderIds, [$kaderTypeId]))
            : [...$this->kaderIds, $kaderTypeId];

        if ($this->kaderIds === []) {
            $this->kaderMode = WpsRankingFilter::KADER_ALL;
        } elseif ($this->kaderMode === WpsRankingFilter::KADER_ALL) {
            // Wer eine Kaderart anhakt, will einschränken; ohne Modus bliebe die Auswahl
            // folgenlos.
            $this->kaderMode = WpsRankingFilter::KADER_EXCEPT;
        }

        $this->afterFilterChange();
    }

    public function isKaderSelected(int $kaderTypeId): bool
    {
        return in_array($kaderTypeId, $this->kaderIds, true);
    }

    public function toggleExhibition(): void
    {
        $this->includeExhibition = ! $this->includeExhibition;

        $this->afterFilterChange();
    }

    /** Jugendrangliste: setzt die Altersgrenze auf 18 oder hebt sie auf (§6.3). */
    public function toggleYouth(): void
    {
        $this->maxAge = $this->maxAge === '' ? '18' : '';

        $this->afterFilterChange();
    }

    public function resetFilters(): void
    {
        $this->type = WpsRankingFilter::TYPE_SEASON;
        $this->year = (string) $this->availableYears()[0];
        $this->meetId = '';
        $this->strokeTypeId = '';
        $this->distance = '';
        $this->gender = '';
        $this->sportClass = '';
        $this->course = WpsRankingFilter::COURSE_SCM;
        $this->clubId = '';
        $this->minPoints = '';
        $this->maxAge = '';
        $this->includeExhibition = false;
        $this->calculationType = '';
        $this->kaderMode = WpsRankingFilter::KADER_ALL;
        $this->kaderIds = [];

        $this->afterFilterChange();
    }

    #[Computed]
    public function filter(): WpsRankingFilter
    {
        return new WpsRankingFilter(
            in_array($this->type, WpsRankingFilter::TYPES, true) ? $this->type : WpsRankingFilter::TYPE_SEASON,
            $this->intOrNull($this->year),
            $this->intOrNull($this->meetId),
            $this->intOrNull($this->strokeTypeId),
            $this->intOrNull($this->distance),
            $this->gender,
            $this->sportClass,
            $this->course,
            $this->intOrNull($this->clubId),
            $this->intOrNull($this->minPoints),
            $this->intOrNull($this->maxAge),
            $this->includeExhibition,
            $this->calculationType,
            $this->kaderMode,
            array_map(intval(...), $this->kaderIds),
        );
    }

    /** @return Collection<int, WpsRankingEntry> */
    #[Computed]
    public function entries(): Collection
    {
        return $this->service()->ranking($this->filter());
    }

    /** @return LengthAwarePaginator<int, WpsRankingEntry> */
    #[Computed]
    public function page(): LengthAwarePaginator
    {
        $alle = $this->entries();
        $seite = Paginator::resolveCurrentPage();

        return new Paginator(
            $alle->forPage($seite, self::PER_PAGE)->values(),
            $alle->count(),
            self::PER_PAGE,
            $seite,
            ['path' => Paginator::resolveCurrentPath()],
        );
    }

    /**
     * Athleten ohne Geburtsdatum, die durch die Altersgrenze herausgefallen sind (§5).
     *
     * @return Collection<int, WpsRankingEntry>
     */
    #[Computed]
    public function withoutBirthDate(): Collection
    {
        return $this->service()->withoutBirthDate($this->filter());
    }

    /**
     * Verwendete WPS-Punkteversionen — im Kopfbereich ausgewiesen (**[R3]**).
     *
     * @return list<string>
     */
    #[Computed]
    public function usedVersions(): array
    {
        return $this->service()->usedVersions($this->entries());
    }

    /**
     * Veranstaltungen des gewählten Jahres.
     *
     * Nach Jahr eingeschränkt, weil die Liste sonst mit jeder Saison länger und
     * unübersichtlicher wird. Abgegrenzt über whereBetween mit Uhrzeit an der oberen Grenze
     * — YEAR() ist nicht DB-portabel, und ohne Uhrzeit fiele der 31. Dezember je nach
     * Treiber still heraus.
     *
     * @return Collection<int, Meet>
     */
    #[Computed]
    public function meets(): Collection
    {
        $jahr = $this->intOrNull($this->year) ?? (int) date('Y');

        return Meet::query()
            ->whereBetween('start_date', ["$jahr-01-01", "$jahr-12-31 23:59:59"])
            ->orderByDesc('start_date')
            ->get(['id', 'name', 'start_date', 'course']);
    }

    /**
     * Stichtag, auf den sich die Kaderzugehörigkeit bezieht.
     *
     * Wird unter dem Kaderfilter ausgewiesen: Bei einem vergangenen Auswertungsjahr ist es
     * dessen Ende, nicht der heutige Tag — sonst stünde bei einer Auswertung der Saison 2024
     * eine Kadereinteilung, die es damals nicht gab.
     */
    #[Computed]
    public function kaderReferenceDate(): string
    {
        return app(AthleteKaderResolver::class)
            ->referenceDateForYear($this->intOrNull($this->year) ?? (int) date('Y'));
    }

    /** @return Collection<int, KaderType> */
    #[Computed]
    public function kaderTypes(): Collection
    {
        return KaderType::query()->active()->orderBy('sort_order')->get();
    }

    /** @return Collection<int, StrokeType> */
    #[Computed]
    public function strokeTypes(): Collection
    {
        return StrokeType::query()->orderBy('id')->get();
    }

    /** @return Collection<int, Club> */
    #[Computed]
    public function clubs(): Collection
    {
        return Club::query()->orderBy('name')->get(['id', 'name']);
    }

    /**
     * Sportklassen, die in den Ergebnissen tatsächlich vorkommen.
     *
     * Nicht alle denkbaren Klassen: Ein Filter auf eine Klasse ohne einen einzigen Start
     * wäre nur irreführend. Sortiert über SportClassSorter, damit S2 vor S10 steht.
     *
     * @return list<string>
     */
    #[Computed]
    public function availableSportClasses(): array
    {
        return Result::query()
            ->whereNotNull('sport_class')
            ->whereNotNull('wps_points')
            ->distinct()
            ->pluck('sport_class')
            ->sortBy(static fn (string $klasse): string => SportClassSorter::key($klasse))
            ->values()
            ->all();
    }

    /**
     * Jahre, für die überhaupt gewertete Ergebnisse vorliegen.
     *
     * Ermittelt in PHP aus den Wettkampfdaten statt über YEAR() — die Funktion ist nicht
     * DB-portabel, und die Testsuite läuft auf SQLite.
     *
     * @return list<int>
     */
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

    public function render(): View
    {
        return view('livewire.wps-rankings');
    }

    /**
     * Nach jeder Filteränderung: zwischengespeicherte Werte verwerfen und auf Seite 1
     * zurückspringen.
     *
     * Ohne den Rücksprung landet man in der kleineren Treffermenge auf einer Seite, die es
     * nicht mehr gibt, und sieht eine leere Tabelle.
     */
    private function afterFilterChange(): void
    {
        $this->resetPage();

        unset(
            $this->filter,
            $this->entries,
            $this->page,
            $this->withoutBirthDate,
            $this->usedVersions,
            $this->meets,
            $this->kaderReferenceDate,
        );
    }

    private function intOrNull(string $wert): ?int
    {
        $bereinigt = trim($wert);

        return $bereinigt === '' || ! is_numeric($bereinigt) ? null : (int) $bereinigt;
    }

    private function service(): WpsRankingService
    {
        return $this->service ??= app(WpsRankingService::class);
    }
}
