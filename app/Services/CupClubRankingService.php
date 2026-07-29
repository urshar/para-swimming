<?php

namespace App\Services;

use App\Models\Cup;
use App\Support\ClubRankingConfiguration;
use App\Support\PerformanceClubRankingResult;
use App\Support\StartClubRankingResult;
use Illuminate\Support\Collection;

/**
 * CupClubRankingService
 *
 * Fassade der ÖBSV-Cup-Vereinswertung (Spec §10). Bündelt die beiden
 * Wertungssysteme und enthält selbst keine Wertungs- oder Punktelogik — diese
 * liegt vollständig in den delegierten Fach-Services.
 *
 *   - calculateStartRanking()        → klassische Startwertung (System A)
 *   - calculatePerformanceRanking()  → leistungsorientierte Wertung (System B)
 */
final readonly class CupClubRankingService
{
    public function __construct(
        private StartBasedClubRankingService $startBasedRanking,
        private PerformanceBasedClubRankingService $performanceBasedRanking,
    ) {}

    /**
     * Klassische Startwertung (System A).
     *
     * @param  bool|null  $includeForeignClubs  null = Konfigurationswert
     * @return Collection<int, StartClubRankingResult>
     */
    public function calculateStartRanking(Cup $cup, ?bool $includeForeignClubs = null): Collection
    {
        return $this->startBasedRanking->getRanking($cup, $includeForeignClubs);
    }

    /**
     * Leistungsorientierte Vereinswertung (System B).
     *
     * @param  ClubRankingConfiguration|null  $config  null = Konfiguration aus config()
     * @return Collection<int, PerformanceClubRankingResult>
     */
    public function calculatePerformanceRanking(Cup $cup, ?ClubRankingConfiguration $config = null): Collection
    {
        return $this->performanceBasedRanking->getRanking($cup, $config);
    }
}
