<?php

namespace App\Services;

use App\Models\Meet;
use App\Models\Result;
use App\Models\WpsPointVersion;
use App\Support\WpsPointResult;
use Illuminate\Support\Carbon;

/**
 * Persistiert die WPS-Punkte.
 *
 * Trennung zum WpsPointCalculator: dort liegt die reine Berechnung, hier ausschließlich das
 * Laden, Speichern und Zusammenfassen. Damit können Ranglisten den Calculator verwenden, ohne
 * gespeicherte Werte zu berühren.
 *
 * results.points bleibt unangetastet — dieses Feld gehört den World-Aquatics-Punkten und wird
 * von der Cup-Wertung, den Richtzeiten und der Statistik gelesen.
 */
final readonly class WpsPointCalculationService
{
    public function __construct(
        private WpsPointCalculator $calculator,
        private WpsPointVersionResolver $versionResolver,
    ) {}

    /**
     * Berechnet die Punkte aller Ergebnisse eines Meets neu.
     *
     * $onlyMissing = true überspringt Ergebnisse, die bereits einen Wert tragen — damit lässt
     * sich ein abgebrochener Massenlauf fortsetzen, ohne alles neu zu rechnen.
     *
     * @return array{updated: int, skipped: int, skipped_reasons: array<string, int>, skipped_results: array<int, string>}
     */
    public function recalculateForMeet(
        Meet $meet,
        ?WpsPointVersion $version = null,
        bool $onlyMissing = false,
    ): array {
        $resolved = $this->versionResolver->resolveForMeet($meet, $version);

        $query = Result::query()
            ->with(['swimEvent.strokeType', 'athlete', 'meet'])
            ->where('meet_id', $meet->id);

        if ($onlyMissing) {
            $query->whereNull('wps_points');
        }

        $results = $query->get();

        if ($resolved === null) {
            return $this->summaryForMissingVersion($results->pluck('id')->all());
        }

        $updated = 0;
        $skippedReasons = [];
        $skippedResults = [];

        foreach ($results as $result) {
            $calculated = $this->calculator->calculate($result, $resolved);

            if (! $calculated->wasCalculated()) {
                $reason = (string) $calculated->skipReason;
                $skippedReasons[$reason] = ($skippedReasons[$reason] ?? 0) + 1;
                $skippedResults[$result->id] = $reason;

                continue;
            }

            $this->store($result, $calculated);
            $updated++;
        }

        return [
            'updated' => $updated,
            'skipped' => count($skippedResults),
            'skipped_reasons' => $skippedReasons,
            'skipped_results' => $skippedResults,
        ];
    }

    /**
     * Berechnet die Punkte aller Ergebnisse eines Jahres neu.
     *
     * Die Jahresabgrenzung läuft über whereBetween mit expliziten Datumsgrenzen statt über
     * YEAR() — MySQL-Funktionen sind in der auf SQLite laufenden Testsuite nicht verfügbar.
     *
     * Die obere Grenze trägt bewusst eine Uhrzeit: je nach Treiber wird ein date-Feld als
     * "2026-12-31" oder als "2026-12-31 00:00:00" abgelegt. Ohne die Uhrzeit fiele eine
     * Veranstaltung am 31. Dezember im zweiten Fall aus dem Vergleich.
     *
     * @return array{updated: int, skipped: int, skipped_reasons: array<string, int>, skipped_results: array<int, string>}
     */
    public function recalculateForYear(
        int $year,
        ?WpsPointVersion $version = null,
        bool $onlyMissing = false,
    ): array {
        $meets = Meet::query()
            ->whereBetween('start_date', ["$year-01-01", "$year-12-31 23:59:59"])
            ->get();

        $total = [
            'updated' => 0,
            'skipped' => 0,
            'skipped_reasons' => [],
            'skipped_results' => [],
        ];

        foreach ($meets as $meet) {
            $summary = $this->recalculateForMeet($meet, $version, $onlyMissing);

            $total['updated'] += $summary['updated'];
            $total['skipped'] += $summary['skipped'];
            $total['skipped_results'] += $summary['skipped_results'];

            foreach ($summary['skipped_reasons'] as $reason => $count) {
                $total['skipped_reasons'][$reason] = ($total['skipped_reasons'][$reason] ?? 0) + $count;
            }
        }

        return $total;
    }

    /**
     * Berechnet die Punkte eines einzelnen Ergebnisses neu und speichert sie.
     *
     * Ist das Ergebnis nicht berechenbar, werden vorhandene WPS-Werte gelöscht — sonst bliebe
     * nach einer nachträglichen Disqualifikation eine Punktzahl stehen.
     */
    public function recalculateForResult(Result $result, ?WpsPointVersion $version = null): WpsPointResult
    {
        $meet = $result->meet;

        if ($meet === null) {
            return WpsPointResult::skipped('keine Veranstaltung zugeordnet');
        }

        $resolved = $this->versionResolver->resolveForMeet($meet, $version);

        if ($resolved === null) {
            $this->clear($result);

            return WpsPointResult::skipped('keine gültige WPS-Version für das Wettkampfdatum');
        }

        $calculated = $this->calculator->calculate($result, $resolved);

        if ($calculated->wasCalculated()) {
            $this->store($result, $calculated);
        } else {
            $this->clear($result);
        }

        return $calculated;
    }

    private function store(Result $result, WpsPointResult $calculated): void
    {
        $result->update([
            'wps_points' => $calculated->points,
            'wps_point_version_id' => $calculated->version?->id,
            'wps_point_parameter_id' => $calculated->parameter?->id,
            'wps_calculation_type' => $calculated->calculationType,
            'wps_calculated_at' => Carbon::now(),
            // Nur bei umgerechneten Kurzbahnzeiten gesetzt; sonst bewusst auf null, damit
            // eine frühere Umrechnung nicht stehen bleibt.
            'wps_estimated_lcm_time' => $calculated->estimatedLcmTime,
            'wps_conversion_factor_id' => $calculated->conversionFactor?->id,
        ]);
    }

    private function clear(Result $result): void
    {
        if ($result->wps_points === null
            && $result->wps_calculated_at === null
            && $result->wps_estimated_lcm_time === null) {
            return;
        }

        $result->update([
            'wps_points' => null,
            'wps_point_version_id' => null,
            'wps_point_parameter_id' => null,
            'wps_calculation_type' => null,
            'wps_calculated_at' => null,
            'wps_estimated_lcm_time' => null,
            'wps_conversion_factor_id' => null,
        ]);
    }

    /**
     * @param  array<int, int>  $resultIds
     * @return array{updated: int, skipped: int, skipped_reasons: array<string, int>, skipped_results: array<int, string>}
     */
    private function summaryForMissingVersion(array $resultIds): array
    {
        $reason = 'keine gültige WPS-Version für das Wettkampfdatum';

        return [
            'updated' => 0,
            'skipped' => count($resultIds),
            'skipped_reasons' => $resultIds === [] ? [] : [$reason => count($resultIds)],
            'skipped_results' => array_fill_keys($resultIds, $reason),
        ];
    }
}
