<?php

namespace App\Livewire;

use App\Models\Meet;
use App\Services\MultiYearStatisticsService;
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

    public int $year;

    public int $span = MultiYearStatisticsService::DEFAULT_SPAN;

    /**
     * Reguläre Ergebnisse im Status-Diagramm mitzeichnen. Standardmäßig aus:
     * sie sind um ein Vielfaches häufiger als DSQ/DNS/… und drücken diese sonst
     * auf der gemeinsamen Skala auf die Nulllinie.
     */
    public bool $showRegularStatus = false;

    public function mount(): void
    {
        $this->year = $this->availableYears->first() ?? now()->year;
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

    public function render(): View
    {
        return view('livewire.year-comparison');
    }

    private function clampSpan(int $span): int
    {
        return max(self::MIN_SPAN, min(self::MAX_SPAN, $span));
    }
}
