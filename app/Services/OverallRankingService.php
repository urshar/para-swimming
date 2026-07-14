<?php

namespace App\Services;

use App\Models\Cup;
use App\Models\CupDailyResult;
use App\Models\CupOverallResult;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * OverallRankingService
 *
 * Berechnet die Gesamtwertung (Punkt 10 der Spec) für ein komplettes Cup-Jahr
 * und persistiert sie als Snapshot in cup_overall_results (analog zur
 * Tageswertung: "Snapshot je Berechnungslauf", kein Live-Recompute). Ein
 * erneuter Aufruf von calculateForCup() ersetzt den bisherigen Snapshot
 * dieses Cups vollständig.
 *
 * Wertungskategorie = Geschlecht + Sportklassengruppe + Altersgruppe (Erik:
 * die Altersgruppe kommt NUR hier dazu, nicht bei der Tageswertung).
 *
 * Baut auf den bereits persistierten cup_daily_results auf (Punkt 9) — für
 * jeden Athleten werden je Wertungskategorie die besten cup.best_of_count
 * Tageswertungen aufsummiert (Punkt 10).
 */
readonly class OverallRankingService
{
    public function __construct(
        private GroupResolverService $groupResolver,
    ) {}

    /**
     * @return EloquentCollection<int, CupOverallResult>
     *
     * @throws Throwable bei einem Fehler innerhalb der Transaktion
     */
    public function calculateForCup(Cup $cup): EloquentCollection
    {
        DB::transaction(function () use ($cup) {
            CupOverallResult::where('cup_id', $cup->id)->delete();

            $calculatedAt = now();

            foreach ($this->groupDailyResultsByBucket($cup) as $bucketRows) {
                $this->createOverallResultForBucket($cup, $bucketRows, $calculatedAt);
            }
        });

        return CupOverallResult::where('cup_id', $cup->id)->get();
    }

    /**
     * Rangliste einer Wertungskategorie ("Damen PI Jugend" etc.), inklusive
     * Rang (Sportwertung: gleiche Punkte = gleicher Rang, nächster Rang
     * überspringt entsprechend). Der Rang wird beim Lesen aus den
     * gespeicherten Gesamtpunkten abgeleitet, nicht separat gespeichert.
     *
     * @return Collection<int, CupOverallResult>
     */
    public function rankedBracket(int $cupId, string $gender, int $sportClassGroupId, ?int $ageGroupId): Collection
    {
        $rows = CupOverallResult::forBracket($cupId, $gender, $sportClassGroupId, $ageGroupId)
            ->with(['athlete', 'club', 'sportClassGroup', 'ageGroup'])
            ->get();

        return $this->assignRanks($rows);
    }

    /**
     * Alle Tageswertungs-Zeilen des Cups gruppiert nach Athlet + Geschlecht +
     * Sportklassengruppe (die Bucket-Definition der Gesamtwertung).
     *
     * @return Collection<string, Collection<int, CupDailyResult>>
     */
    private function groupDailyResultsByBucket(Cup $cup): Collection
    {
        return CupDailyResult::where('cup_id', $cup->id)
            ->with('athlete')
            ->get()
            ->groupBy(fn (CupDailyResult $row) => "$row->athlete_id|$row->gender|$row->sport_class_group_id");
    }

    /**
     * @param  Collection<int, CupDailyResult>  $bucketRows  alle Tageswertungen eines Athleten in genau einer Bucket
     */
    private function createOverallResultForBucket(Cup $cup, Collection $bucketRows, CarbonInterface $calculatedAt): void
    {
        $first = $bucketRows->first();
        $athlete = $first->athlete;

        $counted = $bucketRows->sortByDesc('points')->take($cup->best_of_count)->values();

        // resolveAgeGroup() wertet nur das Jahr aus (31.12.-Stichtagsregel) — Monat/Tag sind irrelevant.
        $ageGroup = $this->groupResolver->resolveAgeGroup($athlete, "$cup->year-01-01");

        CupOverallResult::create([
            'cup_id' => $cup->id,
            'athlete_id' => $first->athlete_id,
            'club_id' => $counted->first()->club_id, // Verein des punktbesten gezählten Tages
            'sport_class_group_id' => $first->sport_class_group_id,
            'gender' => $first->gender,
            'age_group_id' => $ageGroup?->id,
            'total_points' => $counted->sum('points'),
            'rounds_counted' => $counted->count(),
            'counted_meet_ids' => $counted->pluck('meet_id')->all(),
            'calculated_at' => $calculatedAt,
        ]);
    }

    /**
     * @param  Collection<int, CupOverallResult>  $rowsSortedByPointsDesc  absteigend nach total_points sortiert
     * @return Collection<int, CupOverallResult> dieselben Zeilen, jeweils mit dynamischem rank-Attribut
     */
    private function assignRanks(Collection $rowsSortedByPointsDesc): Collection
    {
        $rank = 0;
        $position = 0;
        $previousPoints = null;

        return $rowsSortedByPointsDesc->map(function (CupOverallResult $row) use (&$rank, &$position, &$previousPoints) {
            $position++;

            if ($previousPoints === null || $row->total_points < $previousPoints) {
                $rank = $position;
            }

            $previousPoints = $row->total_points;
            $row->rank = $rank;

            return $row;
        });
    }
}
