<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Collection;

/**
 * Weist einer nach Punkten absteigend sortierten Reihe einen Sportwertungs-Rang zu: gleiche Punkte
 * = gleicher Rang, der nächste Rang überspringt entsprechend (z. B. 1, 2, 2, 4).
 *
 * Extrahiert aus OverallRankingService::assignRanks() (Cup-Gesamtwertung), damit AnnualBestService
 * (Phase 7, Jahresbestleistungen) dieselbe Tie-Break-Logik nutzt, statt sie erneut auszuprogrammieren.
 */
class SportRankAssigner
{
    /**
     * @param  Collection  $rowsSortedByPointsDesc  absteigend nach Punkten sortiert
     * @param  Closure  $points  liefert die Punktzahl einer Zeile
     * @return Collection dieselben Zeilen, jeweils mit dynamischem rank-Attribut
     */
    public static function assign(Collection $rowsSortedByPointsDesc, Closure $points): Collection
    {
        $rank = 0;
        $position = 0;
        $previousPoints = null;

        return $rowsSortedByPointsDesc->map(function ($row) use ($points, &$rank, &$position, &$previousPoints) {
            $position++;
            $rowPoints = $points($row);

            if ($previousPoints === null || $rowPoints < $previousPoints) {
                $rank = $position;
            }

            $previousPoints = $rowPoints;
            $row->rank = $rank;

            return $row;
        });
    }
}
