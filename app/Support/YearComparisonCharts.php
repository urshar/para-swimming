<?php

namespace App\Support;

use App\Services\MultiYearStatisticsService;

/**
 * YearComparisonCharts
 *
 * Baut die Chart-Definitionen für die Jahresvergleich-Seite an genau einer
 * Stelle, damit Bildschirm (interaktives flux:chart) und PDF (statisches SVG)
 * dieselben Serien, Reihenfolgen und Farben zeigen.
 *
 * Farben werden bewusst nur als semantischer Slot-Name geliefert ('blue',
 * 'pink', …). Der Bildschirm bildet den Slot auf Tailwind-Klassen ab (dort im
 * Blade, damit Tailwind sie erkennt), das PDF auf einen Hex-Wert — so muss die
 * Zuordnung nur einmal je Renderer stehen.
 *
 * Jede Chart-Definition:
 *   [
 *     'key'    => 'individual_starts',
 *     'title'  => 'Einzelstarts nach Geschlecht',
 *     'years'  => [2022, …],
 *     'points' => [ ['year'=>2022, 'm'=>.., 'f'=>..], … ],  // :value für flux:chart
 *     'series' => [ ['key'=>'m', 'label'=>'Herren', 'color'=>'blue', 'values'=>[..]], … ],
 *     'hasData'=> bool,
 *   ]
 */
final readonly class YearComparisonCharts
{
    /** Geschlecht (Einzel) → Farb-Slot. */
    private const array GENDER_COLORS = ['M' => 'blue', 'F' => 'pink', 'N' => 'amber'];

    /** Staffel-Typ → Farb-Slot. */
    private const array RELAY_COLORS = ['M' => 'blue', 'F' => 'pink', 'X' => 'violet'];

    /** Ergebnis-Status → Farb-Slot. */
    private const array STATUS_COLORS = [
        'regular' => 'zinc', 'EXH' => 'sky', 'DSQ' => 'red',
        'DNS' => 'amber', 'DNF' => 'orange', 'SICK' => 'violet', 'WDR' => 'rose',
    ];

    public function __construct(
        private MultiYearStatisticsService $stats,
    ) {}

    /**
     * Alle Vergleichs-Charts für Anker-Jahr + Span.
     *
     * @param  bool  $includeRegularStatus  reguläre Ergebnisse im Status-Diagramm mitzeichnen?
     *                                      Standardmäßig aus, weil sie die übrigen Status
     *                                      (DSQ/DNS/…) auf der Skala erdrücken.
     * @return list<array{key: string, title: string, years: list<int>, points: list<array<string, int>>, series: list<array{key: string, label: string, color: string, values: list<int>}>, hasData: bool}>
     */
    public function charts(int $anchorYear, int $span, bool $includeRegularStatus = false): array
    {
        $series = $this->stats->series($anchorYear, $span);
        $years = $series['years'];
        $rows = $series['rows'];

        $records = $this->stats->recordsPerYear($anchorYear, $span);
        $meets = $this->stats->meetsPerYear($anchorYear, $span);
        $status = $this->stats->statusTrend($anchorYear, $span);

        return [
            $this->genderChart('individual_starts', 'Einzelstarts nach Geschlecht', $years, $rows,
                $series['individual_genders'], 'starts'),
            $this->genderChart('participants', 'Teilnehmer nach Geschlecht', $years, $rows,
                $series['individual_genders'], 'participants'),
            $this->relayChart($years, $rows, $series['relay_genders']),
            $this->singleChart('records', 'Rekorde pro Jahr', 'Rekorde', 'emerald', $records['years'],
                $records['values']),
            $this->singleChart('meets', 'Veranstaltungen pro Jahr', 'Veranstaltungen', 'sky', $meets['years'],
                $meets['values']),
            $this->statusChart($status['years'], $status['statuses'], $includeRegularStatus),
        ];
    }

    /**
     * @param  list<int>  $years
     * @param  list<array<string, int>>  $rows
     * @param  list<string>  $genders
     */
    private function genderChart(
        string $key,
        string $title,
        array $years,
        array $rows,
        array $genders,
        string $prefix
    ): array {
        $seriesDefs = array_map(fn (string $gender): array => [
            'key' => strtolower($gender),
            'label' => MultiYearStatisticsService::GENDER_LABELS[$gender],
            'color' => self::GENDER_COLORS[$gender] ?? 'zinc',
            'values' => array_map(fn (array $row): int => $row["{$prefix}_".strtolower($gender)] ?? 0, $rows),
        ], $genders);

        return $this->pack($key, $title, $years, $seriesDefs);
    }

    /**
     * @param  list<int>  $years
     * @param  list<array<string, int>>  $rows
     * @param  list<string>  $genders
     */
    private function relayChart(array $years, array $rows, array $genders): array
    {
        $seriesDefs = array_map(fn (string $gender): array => [
            'key' => strtolower($gender),
            'label' => MultiYearStatisticsService::RELAY_GENDER_LABELS[$gender],
            'color' => self::RELAY_COLORS[$gender] ?? 'zinc',
            'values' => array_map(fn (array $row): int => $row['relay_'.strtolower($gender)] ?? 0, $rows),
        ], $genders);

        return $this->pack('relay', 'Staffelstarts nach Typ', $years, $seriesDefs);
    }

    /**
     * @param  list<int>  $years
     * @param  list<int>  $values
     */
    private function singleChart(
        string $key,
        string $title,
        string $label,
        string $color,
        array $years,
        array $values
    ): array {
        return $this->pack($key, $title, $years, [
            ['key' => 'value', 'label' => $label, 'color' => $color, 'values' => $values],
        ]);
    }

    /**
     * @param  list<int>  $years
     * @param  array<string, list<int>>  $statuses
     * @param  bool  $includeRegular  reguläre Ergebnisse mitzeichnen (sonst erdrücken sie die Skala)
     */
    private function statusChart(array $years, array $statuses, bool $includeRegular): array
    {
        $seriesDefs = [];
        foreach ($statuses as $status => $values) {
            if ($status === 'regular' && ! $includeRegular) {
                continue;
            }

            $seriesDefs[] = [
                'key' => strtolower($status),
                'label' => MultiYearStatisticsService::STATUS_LABELS[$status] ?? $status,
                'color' => self::STATUS_COLORS[$status] ?? 'zinc',
                'values' => $values,
            ];
        }

        return $this->pack('status', 'Status pro Jahr (gesamt)', $years, $seriesDefs);
    }

    /**
     * Fügt Serien + abgeleitete Datenpunkte (für flux:chart) zu einer
     * Chart-Definition zusammen.
     *
     * @param  list<int>  $years
     * @param  list<array{key: string, label: string, color: string, values: list<int>}>  $series
     */
    private function pack(string $key, string $title, array $years, array $series): array
    {
        $points = [];
        foreach ($years as $index => $year) {
            $point = ['year' => $year];
            foreach ($series as $serie) {
                $point[$serie['key']] = $serie['values'][$index] ?? 0;
            }
            $points[] = $point;
        }

        $hasData = false;
        foreach ($series as $serie) {
            foreach ($serie['values'] as $value) {
                if ($value > 0) {
                    $hasData = true;
                    break 2;
                }
            }
        }

        return [
            'key' => $key,
            'title' => $title,
            'years' => $years,
            'points' => $points,
            'series' => $series,
            'hasData' => $hasData,
        ];
    }
}
