<?php

namespace App\Livewire;

use App\Models\AgeGroup;
use App\Models\BaseTimeSportClass;
use App\Models\Club;
use App\Models\KaderType;
use App\Models\Meet;
use App\Models\StrokeType;
use App\Services\AthleteKaderResolver;
use App\Services\WpsRankingService;
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

    public bool $includeExhibition = false;

    public string $calculationType = '';

    public string $ageGroupId = '';

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
            'calculationType' => $this->calculationType = $wert,
            'ageGroupId' => $this->ageGroupId = $wert,
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
        $this->ageGroupId = '';
        $this->includeExhibition = false;
        $this->calculationType = '';
        $this->kaderMode = WpsRankingFilter::KADER_ALL;
        $this->kaderIds = [];

        $this->afterFilterChange();
    }

    #[Computed]
    public function filter(): WpsRankingFilter
    {
        // Benannte Argumente: Der Filter hat sechzehn Parameter; ein neuer in der Mitte
        // verschöbe bei Positionsangaben still alle folgenden.
        return new WpsRankingFilter(
            type: in_array($this->type, WpsRankingFilter::TYPES, true)
                ? $this->type
                : WpsRankingFilter::TYPE_SEASON,
            year: $this->intOrNull($this->year),
            meetId: $this->intOrNull($this->meetId),
            strokeTypeId: $this->intOrNull($this->strokeTypeId),
            distance: $this->intOrNull($this->distance),
            gender: $this->gender,
            sportClass: $this->sportClass,
            course: $this->course,
            clubId: $this->intOrNull($this->clubId),
            minPoints: $this->intOrNull($this->minPoints),
            // Die Altersgrenze wird über die Altersgruppe ausgedrückt; als eigenes Feld
            // gäbe es zwei Wege zur selben Einschränkung, die einander widersprechen können.
            maxAge: null,
            includeExhibition: $this->includeExhibition,
            calculationType: $this->calculationType,
            ageGroupId: $this->intOrNull($this->ageGroupId),
            ageGroupLabel: $this->ageGroupLabel(),
            kaderMode: $this->kaderMode,
            kaderIds: array_map(intval(...), $this->kaderIds),
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

    /** @return Collection<int, AgeGroup> */
    #[Computed]
    public function ageGroups(): Collection
    {
        return AgeGroup::query()->active()->orderBy('sort_order')->get();
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
        return Club::query()->orderBy('name')->get(['id', 'name', 'short_name']);
    }

    /**
     * Optionen für das Sportklassen-Dropdown: eine Option je in den Basiswerten gepflegter
     * Klassennummer, über S/SB/SM zusammengefasst (Wert "S{n},SB{n},SM{n}", Label
     * zweistellig gepolstert) — identisches Muster wie
     * RecordController::buildSportClassOptions() (Design-Feedback Erik, 2026-09-04: "wieder so
     * machen wie wir es in P10 gemacht haben"). Zeigt bewusst die vollständige, feste Liste,
     * nicht nur die in WPS-Ergebnissen tatsächlich vorkommenden Klassen — Konsistenz mit dem
     * Rekorde-Filter, siehe docs/specs/admin-ui-rework.md "Dreißigster Design-Feedback-Nachtrag
     * zu Phase 10".
     *
     * @return Collection<string, string>
     */
    #[Computed]
    public function sportClassOptions(): Collection
    {
        $numbers = BaseTimeSportClass::query()
            ->pluck('code')
            ->map(fn ($code) => (int) preg_replace('/\D+/', '', $code))
            ->unique()
            ->sort()
            ->values();

        return $numbers->mapWithKeys(fn ($n) => [
            "S$n,SB$n,SM$n" => sprintf('S%02d,SB%02d,SM%02d', $n, $n, $n),
        ]);
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

    /**
     * Vollständige Adresse der PDF-Ausgabe, mit dem aktuellen Filterstand.
     *
     * Im Code gebaut, nicht im Blade-Attribut: Dort wäre es eine Ternäroperation mit leerem
     * String, an der sich der Blade-Parser von PhpStorm verschluckt.
     */
    public function pdfUrl(): string
    {
        $parameter = $this->filter()->toQuery();

        return route('wps.rankings.pdf').($parameter === [] ? '' : '?'.http_build_query($parameter));
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
            $this->ageGroups,
        );
    }

    /** Bezeichnung der gewählten Altersgruppe — für die Beschreibung des Filterstands. */
    private function ageGroupLabel(): ?string
    {
        $id = $this->intOrNull($this->ageGroupId);

        return $id === null
            ? null
            : $this->ageGroups()->firstWhere('id', $id)?->name_de;
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
