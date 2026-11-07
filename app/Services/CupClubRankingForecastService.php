<?php

namespace App\Services;

use App\Models\Cup;
use App\Models\Meet;
use App\Support\ClubForecastComparison;
use App\Support\ClubRankingConfiguration;
use App\Support\ClubRankingForecast;
use App\Support\PerformanceClubRankingResult;
use App\Support\StartClubRankingResult;
use Illuminate\Support\Collection;
use stdClass;

/**
 * CupClubRankingForecastService
 *
 * Prognose der ÖBSV-Cup-Vereinswertung (docs/specs/cup-club-ranking-forecast.md §6): Die echten
 * Daten aller übrigen Cup-Meets werden um die aus Meldungen abgeleiteten Zeilen der
 * Prognose-Meets ergänzt und durch dieselbe Wertungsberechnung geschickt wie die aktuelle
 * Wertung. Zusätzlich entsteht je Verein der Vergleich zur aktuellen Wertung.
 *
 * Die Prognose wird nicht persistiert. Veraltete Tageswertungen der übrigen Meets meldet
 * CupStalenessService::clubRankingStatus() unverändert; ein Prognose-Meet hat noch keine
 * Ergebnisse und löst dort deshalb keinen Hinweis aus.
 */
final readonly class CupClubRankingForecastService
{
    public function __construct(
        private StartBasedClubRankingService $startBasedRanking,
        private PerformanceBasedClubRankingService $performanceBasedRanking,
        private ClubRankingEntryProjectionService $projection,
    ) {}

    /**
     * Prognostizierte Startwertung (System A).
     *
     * @return ClubRankingForecast<StartClubRankingResult>
     */
    public function forecastStartRanking(Cup $cup, bool $includeForeignClubs): ClubRankingForecast
    {
        $forecastMeets = $this->projection->forecastMeets($cup);
        $forecastMeetIds = $forecastMeets->pluck('id');

        $realMeetIds = Meet::where('cup_id', $cup->id)->pluck('id')->diff($forecastMeetIds)->values();

        $starts = $this->startBasedRanking->distinctStarts($realMeetIds)
            ->concat($forecastMeets->flatMap(fn (Meet $meet): Collection => $this->projection->projectStarts($meet)));

        $ranking = $this->startBasedRanking->rankStarts($starts, $includeForeignClubs);
        $current = $this->startBasedRanking->getRanking($cup, $includeForeignClubs);

        return new ClubRankingForecast(
            ranking: $ranking,
            comparisons: $this->compare(
                $ranking->map(fn (StartClubRankingResult $r): array => [$r->clubId, $r->rank, (float) $r->starts]),
                $current->map(fn (StartClubRankingResult $r): array => [$r->clubId, $r->rank, (float) $r->starts]),
            ),
            forecastMeets: $forecastMeets,
            unratedEntries: collect(),
            offCourseEntryAthletes: [],
        );
    }

    /**
     * Prognostizierte Leistungswertung (System B).
     *
     * @return ClubRankingForecast<PerformanceClubRankingResult>
     */
    public function forecastPerformanceRanking(Cup $cup, ClubRankingConfiguration $config): ClubRankingForecast
    {
        $forecastMeets = $this->projection->forecastMeets($cup);
        $forecastMeetIds = $forecastMeets->pluck('id')->all();

        // Ein Prognose-Meet hat keine Ergebnisse und damit keine Tageswertung; der Filter sichert
        // nur gegen eine verwaiste Tageswertung ab, damit ein Meet nie doppelt einfließt.
        $rows = $this->performanceBasedRanking->loadDailyResults($cup)
            ->reject(fn (stdClass $row): bool => in_array((int) $row->meet_id, $forecastMeetIds, true))
            ->values();

        $unrated = collect();
        $offCourse = [];

        foreach ($forecastMeets as $meet) {
            $projected = $this->projection->projectDailyResults($meet, $cup);

            $rows = $rows->concat($projected->rows);
            $unrated = $unrated->concat($projected->unrated);
            $offCourse[$meet->id] = $projected->rows
                ->filter(fn (stdClass $row): bool => $row->off_course)
                ->map(fn (stdClass $row): int => $row->athlete_id)
                ->values()
                ->all();
        }

        $ranking = $this->performanceBasedRanking->rankDailyResults($cup, $rows, $config);
        $current = $this->performanceBasedRanking->getRanking($cup, $config);

        return new ClubRankingForecast(
            ranking: $ranking,
            comparisons: $this->compare(
                $ranking->map(fn (PerformanceClubRankingResult $r): array => [$r->clubId, $r->rank, $r->totalPoints]),
                $current->map(fn (PerformanceClubRankingResult $r): array => [$r->clubId, $r->rank, $r->totalPoints]),
            ),
            forecastMeets: $forecastMeets,
            unratedEntries: $unrated->values(),
            offCourseEntryAthletes: array_filter($offCourse),
        );
    }

    /**
     * Vergleich je Verein der Prognose mit der aktuellen Wertung. Vereine, die nur in der
     * aktuellen Wertung vorkommen, gibt es nicht: Die Prognose enthält alle echten Daten.
     *
     * @param  Collection<int, array{int, int, float}>  $forecast  je Verein [club_id, Rang, Wert]
     * @param  Collection<int, array{int, int, float}>  $current  je Verein [club_id, Rang, Wert]
     * @return Collection<int, ClubForecastComparison> nach club_id
     */
    private function compare(Collection $forecast, Collection $current): Collection
    {
        $currentByClub = $current->mapWithKeys(fn (array $row): array => [$row[0] => $row])->all();

        return $forecast
            ->mapWithKeys(function (array $row) use ($currentByClub): array {
                [$clubId, $forecastRank, $forecastValue] = $row;
                $currentRow = $currentByClub[$clubId] ?? null;
                $currentRank = $currentRow[1] ?? null;
                $currentValue = $currentRow[2] ?? 0.0;

                return [$clubId => new ClubForecastComparison(
                    clubId: $clubId,
                    currentRank: $currentRank,
                    currentValue: $currentValue,
                    forecastRank: $forecastRank,
                    forecastValue: $forecastValue,
                    rankChange: $currentRank === null ? null : $currentRank - $forecastRank,
                    valueDelta: round($forecastValue - $currentValue, 2),
                )];
            });
    }
}
