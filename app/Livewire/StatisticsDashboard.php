<?php

namespace App\Livewire;

use App\Models\Meet;
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
     * Bewusst in PHP aus den Startdaten abgeleitet statt per YEAR()/strftime(),
     * damit die Abfrage auf MySQL und SQLite gleich funktioniert.
     *
     * @return Collection<int, int>
     */
    #[Computed]
    public function availableYears(): Collection
    {
        return Meet::query()
            ->whereNotNull('start_date')
            ->orderByDesc('start_date')
            ->pluck('start_date')
            ->map(fn ($date): int => (int) $date->year)
            ->unique()
            ->values();
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
            ->orderBy('start_date')
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

    /** Wechselt das Jahr, verwirft dabei die Auswahl von Veranstaltungen des Vorjahres. */
    public function updatedYear(): void
    {
        $this->meetIds = [];
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
        return view('statistics.dashboard', ['topRows' => self::TOP_ROWS]);
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
            'sections' => [
                'overview' => true,
                'meets' => true,
                'clubs' => true,
                'athletes' => true,
                'nations' => true,
                'records' => true,
                'participants' => false,
                'sport_classes' => false,
                'cup' => false,
            ],
        ]);
    }
}
