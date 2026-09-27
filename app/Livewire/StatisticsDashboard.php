<?php

namespace App\Livewire;

use App\Models\Meet;
use App\Services\MultiYearStatisticsService;
use App\Services\StatisticsService;
use App\Support\ReportConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * StatisticsDashboard
 *
 * Interaktives Statistik-Dashboard (Spec Phase 12): Jahr und Veranstaltungen
 * auswählen, Kennzahlen und Tabellen anzeigen.
 *
 * Die Komponente rechnet selbst nichts aus — sie baut nur die
 * ReportConfiguration und gibt das Ergebnis des StatisticsService aus. Es
 * werden ausschließlich die Abschnitte angefordert, die das Dashboard auch
 * darstellt; die übrigen (z.B. Cup und Sportklassen) bleiben dem Jahresbericht
 * vorbehalten und werden hier gar nicht erst berechnet.
 *
 * Lesender Zugriff für alle angemeldeten Nutzer, analog zur Cup-Gesamtwertung
 * und den Richtzeitenlisten.
 */
class StatisticsDashboard extends Component
{
    /** Anzahl der Zeilen in den "Top"-Tabellen (Vereine, Sportler, Nationen). */
    public const int TOP_ROWS = 10;

    /**
     * Beschriftung der Abschnitte für die Auswahl des Jahresberichts.
     * Die Schlüssel entsprechen ReportConfiguration::SECTION_KEYS.
     *
     * @var array<string, string>
     */
    public const array REPORT_SECTION_LABELS = [
        'overview' => 'Allgemeiner Überblick',
        'multi_year' => '5-Jahres-Vergleich',
        'meets' => 'Teilnehmer und Starts',
        'status_by_meet' => 'Status je Veranstaltung',
        'participants' => 'Altersgruppen und Geschlecht',
        'clubs' => 'Vereinsstatistik',
        'athletes' => 'Sportlerstatistik',
        'nations' => 'Ausländische Teilnehmer',
        'sport_classes' => 'Behinderungsgruppen und Sportklassen',
        'records' => 'Rekorde',
        'cup' => 'ÖBSV Cup',
        'oebm' => 'ÖBM',
        'oejm' => 'ÖJM',
    ];

    /**
     * Abschnitte, die das Dashboard tatsächlich darstellt. Alle übrigen
     * Abschnitte aus ReportConfiguration::SECTION_KEYS werden deaktiviert und
     * damit gar nicht erst berechnet.
     *
     * Bewusst als Positivliste: Kommt später ein Abschnitt hinzu, bleibt er
     * für das Dashboard aus, bis er hier ergänzt und angezeigt wird.
     *
     * @var list<string>
     */
    private const array DISPLAYED_SECTIONS = [
        'overview',
        'meets',
        'status_by_meet',
        'clubs',
        'athletes',
        'nations',
        'records',
    ];

    public int $year;

    /** @var list<int> */
    public array $meetIds = [];

    public function mount(): void
    {
        $this->year = $this->availableYears->first() ?? now()->year;
    }

    /**
     * Jahre, für die überhaupt Veranstaltungen vorliegen — absteigend.
     *
     * @return Collection<int, int>
     */
    #[Computed]
    public function availableYears(): Collection
    {
        return Meet::yearsWithMeets();
    }

    /**
     * Veranstaltungen des gewählten Jahres.
     *
     * @return EloquentCollection<int, Meet>
     */
    #[Computed]
    public function availableMeets(): EloquentCollection
    {
        return Meet::query()
            ->whereDate('start_date', '>=', "$this->year-01-01")
            ->whereDate('start_date', '<=', "$this->year-12-31")
            ->oldest('start_date')
            ->orderBy('name')
            ->get(['id', 'name', 'start_date']);
    }

    /**
     * Die vom Dashboard dargestellten Auswertungen.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function statistics(): array
    {
        return app(StatisticsService::class)->generate($this->configuration());
    }

    /**
     * Mehrjahres-Zeitreihe für die 5-Jahres-Vergleichsgrafiken. Bewusst
     * unabhängig von statistics()/den Abschnitten und von der Meet-Auswahl:
     * Der Vergleich zeigt immer volle Kalenderjahre, verankert am gewählten
     * Jahr (dieses + die 4 Vorjahre), und aktualisiert sich bei einem
     * Jahreswechsel automatisch mit.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function multiYearStatistics(): array
    {
        return app(MultiYearStatisticsService::class)->series($this->year);
    }

    /** Wechselt das Jahr, verwirft dabei die Auswahl von Veranstaltungen des Vorjahres. */
    public function updatedYear(): void
    {
        $this->meetIds = [];
    }

    /**
     * Whitelist-Methode für den Jahres-Filter im Header, wie bei den WPS-Auswertungen (siehe
     * resources/js/wps-livewire-filters.js): `flux:select variant="listbox"` feuert sein
     * internes "change"-Event mit `bubbles:false`, `wire:model` verlässt sich aber auf
     * Event-Bubbling und bekommt es nicht zuverlässig mit — deshalb hier `x-model` +
     * `$wire.call('setYear', 'year', ...)` statt eines direkten `wire:model.live="year"` auf
     * dem Listbox-Select (Design-Feedback Erik, 15.09.2026: Jahr-Auswahl "als Fluxdropdown").
     * Zwei Parameter, obwohl nur ein Feld: `wpsLivewireFilters` ruft grundsätzlich
     * `methode(feld, wert)` auf, damit dieselbe Methode auch mehrere Felder bedienen könnte.
     * `updatedYear()` feuert nur bei über wire:model synchronisierten Änderungen, nicht bei
     * einer internen Zuweisung wie hier — deshalb explizit mit aufgerufen.
     */
    public function setYear(string $feld, string $wert): void
    {
        match ($feld) {
            'year' => $this->year = (int) $wert,
            default => null,
        };

        $this->updatedYear();
    }

    public function resetMeetSelection(): void
    {
        $this->meetIds = [];
    }

    /**
     * Formatiert ein Datum aus der Auswertung für die Anzeige. Die Services
     * liefern reine Datumsstrings (Y-m-d); die Aufbereitung gehört in die
     * Darstellungsschicht, nicht in die Auswertung.
     */
    public function formatDate(?string $date): string
    {
        return $date === null ? '—' : CarbonImmutable::parse($date)->format('d.m.Y');
    }

    public function render(): View
    {
        return view('livewire.statistics-dashboard', [
            'topRows' => self::TOP_ROWS,
            'reportSections' => self::REPORT_SECTION_LABELS,
        ]);
    }

    /**
     * Baut die Konfiguration für den gewählten Zeitraum. Aktiviert nur die
     * Abschnitte, die das Dashboard tatsächlich anzeigt.
     */
    private function configuration(): ReportConfiguration
    {
        return ReportConfiguration::fromArray([
            'year' => $this->year,
            'meet_ids' => $this->meetIds,
            'sections' => collect(ReportConfiguration::SECTION_KEYS)
                ->mapWithKeys(fn (string $key): array => [
                    $key => in_array($key, self::DISPLAYED_SECTIONS, true),
                ])
                ->all(),
        ]);
    }
}
