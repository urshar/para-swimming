<?php

namespace App\Support;

use Illuminate\Support\Collection;
use stdClass;

/**
 * EntryPointsProjection
 *
 * Ergebnis der Punkte-Prognose eines Prognose-Meets aus dessen Meldungen
 * (docs/specs/cup-club-ranking-forecast.md §5).
 *
 * - rows:    je Athlet die punktbeste Meldung im Format von
 *            PerformanceBasedClubRankingService::loadDailyResults() (athlete_id, club_id,
 *            meet_id, points), ergänzt um entry_id und off_course (Meldezeit stammt aus einer
 *            anderen Bahnlänge als der des Meets und wurde ohne Umrechnung gewertet)
 * - unrated: Meldungen ohne ermittelbare Punkte samt Grund
 */
final readonly class EntryPointsProjection
{
    /**
     * @param  Collection<int, stdClass>  $rows
     * @param  Collection<int, UnratedEntry>  $unrated
     */
    public function __construct(
        public Collection $rows,
        public Collection $unrated,
    ) {}
}
