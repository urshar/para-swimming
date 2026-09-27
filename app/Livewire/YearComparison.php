<?php

namespace App\Livewire;

use App\Models\Meet;
use App\Services\MultiYearStatisticsService;
use App\Services\ParticipationStatisticsService;
use App\Support\YearComparisonCharts;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * YearComparison
 *
 * Jahresvergleich-Seite: mehrere Kennzahlen über einen wählbaren Zeitraum
 * (Anker-Jahr + Anzahl Jahre) nebeneinander als Verlauf. Rechnet selbst nichts —
 * die Chart-Definitionen kommen aus YearComparisonCharts (gemeinsam mit dem
 * PDF-Export, damit beide identisch bleiben).
 *
 * Nur Admin, wie das übrige Statistikmodul (Route-Middleware RequireAdmin).
 */
class YearComparison extends Component
{
    /** Kleinste/größte wählbare Anzahl Jahre für den Vergleich. */
    public const int MIN_SPAN = 2;

    public const int MAX_SPAN = 15;

    /**
     * Status-Zeilen des Veranstaltungs-Vergleichs (Schlüssel ⇒ Anzeigename), in
     * derselben Reihenfolge wie statusByMeet()/statusBreakdown().
     */
    public const array STATUS_LABELS = [
        'regular' => 'Regulär',
        'EXH' => 'EXH',
        'DSQ' => 'DSQ',
        'DNS' => 'DNS',
        'DNF' => 'DNF',
        'SICK' => 'SICK',
        'WDR' => 'WDR',
    ];

    /** Farb-Slots für die Balken je Veranstaltung (Pendant zu YearComparisonCharts). */
    private const array COLOR_SLOTS = ['blue', 'pink', 'amber', 'violet', 'emerald', 'sky', 'zinc', 'red', 'orange', 'rose'];

    /** Monatsnamen für die Filterauswahl (Index 1–12). */
    private const array MONTH_LABELS = [
        1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April', 5 => 'Mai', 6 => 'Juni',
        7 => 'Juli', 8 => 'August', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember',
    ];

    public int $year;

    public int $span = MultiYearStatisticsService::DEFAULT_SPAN;

    /**
     * Reguläre Ergebnisse im Status-Diagramm mitzeichnen. Standardmäßig aus:
     * sie sind um ein Vielfaches häufiger als DSQ/DNS/… und drücken diese sonst
     * auf der gemeinsamen Skala auf die Nulllinie.
     */
    public bool $showRegularStatus = false;

    /**
     * Manuell für den Veranstaltungs-Vergleich gewählte Meet-IDs (jahres-
     * übergreifend). Bewusst manuell statt über Namensabgleich — Namen variieren
     * je Jahr.
     *
     * @var array<int>
     */
    public array $selectedMeetIds = [];

    /** Filter für die Veranstaltungsauswahl: Jahr und Monat (0 = alle Monate). */
    public int $cmpYear;

    public int $cmpMonth = 0;

    public function mount(): void
    {
        $this->year = $this->availableYears->first() ?? now()->year;
        $this->cmpYear = $this->year;
    }

    /**
     * Jahre mit Veranstaltungen, absteigend (Auswahl des Anker-Jahres).
     *
     * @return Collection<int, int>
     */
    #[Computed]
    public function availableYears(): Collection
    {
        return Meet::yearsWithMeets();
    }

    /**
     * Wählbare Werte für die Anzahl Jahre.
     *
     * @return list<int>
     */
    #[Computed]
    public function spanOptions(): array
    {
        return range(self::MIN_SPAN, self::MAX_SPAN);
    }

    /**
     * Chart-Definitionen für den gewählten Zeitraum.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function charts(): array
    {
        return app(YearComparisonCharts::class)->charts($this->year, $this->span, $this->showRegularStatus);
    }

    /**
     * Monatsoptionen für den Filter: „Alle Monate“ (0) plus die zwölf Monate.
     * Bewusst statisch (nicht nur belegte Monate): die Optionen ändern sich beim
     * Jahreswechsel nicht, wodurch die Alpine-x-model-Auswahl nicht mit dem
     * Server auseinanderläuft (wpsLivewireFilters synchronisiert nicht zurück).
     * Ein leerer Monat zeigt schlicht „keine Veranstaltungen“.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function comparisonMonths(): array
    {
        return [0 => 'Alle Monate'] + self::MONTH_LABELS;
    }

    /**
     * Auswählbare Veranstaltungen im aktuellen Filter (Jahr + optional Monat),
     * chronologisch. Die eigentliche Auswahl (selectedMeetIds) sammelt sich
     * darüber hinweg an und bleibt beim Filterwechsel erhalten.
     *
     * @return Collection<int, Meet>
     */
    #[Computed]
    public function pickableMeets(): Collection
    {
        $meets = $this->meetsInYear($this->cmpYear);

        if ($this->cmpMonth > 0) {
            $meets = $meets->filter(
                fn (Meet $meet): bool => (int) $meet->start_date->format('n') === $this->cmpMonth
            )->values();
        }

        return $meets;
    }

    /**
     * Die aktuell gewählten Veranstaltungen (für die Übersicht der Auswahl,
     * unabhängig vom aktiven Filter), chronologisch.
     *
     * @return Collection<int, Meet>
     */
    #[Computed]
    public function selectedMeets(): Collection
    {
        if ($this->selectedMeetIds === []) {
            return collect();
        }

        return Meet::query()
            ->whereIn('id', $this->selectedMeetIds)
            ->oldest('start_date')
            ->orderBy('name')
            ->get(['id', 'name', 'start_date']);
    }

    /**
     * Status-Aufschlüsselung der gewählten Veranstaltungen (Spalten des
     * Vergleichs), chronologisch. Leere Auswahl ⇒ leere Collection.
     *
     * @return Collection<int, array{meet_id: int, meet: string, start_date: ?string, statuses: array<string, int>, total: int}>
     */
    #[Computed]
    public function comparisonMeets(): Collection
    {
        return app(ParticipationStatisticsService::class)->statusForMeets($this->selectedMeetIds);
    }

    /**
     * Balkendiagramm des Veranstaltungs-Vergleichs: x-Achse = Sonderstatus
     * (EXH/DSQ/DNS/DNF/SICK/WDR), je gewählter Veranstaltung eine Balkenreihe.
     * Regulär bleibt bewusst außen vor (dominiert die Skala) — die vollständigen
     * Zahlen inkl. Regulär stehen in der Tabelle.
     *
     * @return array{hasData: bool, points: list<array<string, mixed>>, series: list<array{key: string, label: string, color: string}>}
     */
    #[Computed]
    public function comparisonChart(): array
    {
        $meets = $this->comparisonMeets;

        if ($meets->isEmpty()) {
            return ['hasData' => false, 'points' => [], 'series' => []];
        }

        $series = $meets->values()
            ->map(fn (array $row, int $i): array => [
                'key' => 'm'.$row['meet_id'],
                'label' => $row['meet'],
                'color' => self::COLOR_SLOTS[$i % count(self::COLOR_SLOTS)],
            ])
            ->all();

        $specialStatuses = array_diff(array_keys(self::STATUS_LABELS), ['regular']);

        $points = [];
        foreach ($specialStatuses as $status) {
            $point = ['status' => self::STATUS_LABELS[$status]];
            foreach ($meets as $row) {
                $point['m'.$row['meet_id']] = $row['statuses'][$status];
            }
            $points[] = $point;
        }

        return ['hasData' => true, 'points' => $points, 'series' => $series];
    }

    /**
     * Whitelist-Setter für die Filterzeile (flux:select + x-model + $watch, siehe
     * resources/js/wps-livewire-filters.js). Ein einziger Einstieg für beide Felder.
     */
    public function setFilter(string $field, string $value): void
    {
        match ($field) {
            'year' => $this->year = (int) $value,
            'span' => $this->span = $this->clampSpan((int) $value),
            default => null,
        };
    }

    /**
     * Whitelist-Setter für die Jahr/Monat-Filter der Veranstaltungsauswahl
     * (flux:select + x-model + $watch, siehe resources/js/wps-livewire-filters.js).
     */
    public function setComparisonFilter(string $field, string $value): void
    {
        if ($field === 'cmpYear') {
            $this->cmpYear = (int) $value;
        } elseif ($field === 'cmpMonth') {
            $this->cmpMonth = (int) $value;
        }
    }

    /** Eine einzelne Veranstaltung aus der Auswahl entfernen (Chip-Übersicht). */
    public function removeMeet(int $meetId): void
    {
        $this->selectedMeetIds = array_values(
            array_filter($this->selectedMeetIds, fn ($id): bool => (int) $id !== $meetId)
        );
    }

    /** Auswahl des Veranstaltungs-Vergleichs zurücksetzen. */
    public function resetMeetComparison(): void
    {
        $this->selectedMeetIds = [];
    }

    public function render(): View
    {
        return view('livewire.year-comparison');
    }

    /**
     * Veranstaltungen eines Jahres, chronologisch. Datumsfilter über whereDate()
     * (portabel MySQL/SQLite, kein YEAR()).
     *
     * @return Collection<int, Meet>
     */
    private function meetsInYear(int $year): Collection
    {
        return Meet::query()
            ->whereDate('start_date', '>=', "$year-01-01")
            ->whereDate('start_date', '<=', "$year-12-31")
            ->oldest('start_date')
            ->orderBy('name')
            ->get(['id', 'name', 'start_date']);
    }

    private function clampSpan(int $span): int
    {
        return max(self::MIN_SPAN, min(self::MAX_SPAN, $span));
    }
}
