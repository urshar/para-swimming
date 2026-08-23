<?php

namespace App\Services;

use App\Models\AgeGroup;
use App\Models\Cup;
use App\Models\CupDailyResult;
use App\Models\CupOverallResult;
use App\Models\Meet;
use App\Models\SportClassGroup;
use App\Support\SportRankAssigner;
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
     * $gender = null bedeutet: Damen und Herren gemeinsam gewertet (Erik) —
     * eine gemeinsame Rangliste statt zweier getrennter.
     *
     * @return Collection<int, CupOverallResult>
     */
    public function rankedBracket(int $cupId, ?string $gender, int $sportClassGroupId, ?int $ageGroupId): Collection
    {
        $rows = CupOverallResult::forBracket($cupId, $gender, $sportClassGroupId, $ageGroupId)
            ->with(['athlete', 'club', 'sportClassGroup', 'ageGroup'])
            ->get();

        return $this->assignRanks($rows);
    }

    /**
     * Alle echten Wertungskategorien eines Cups, je mit gerankter Athletenliste inklusive
     * Runden-Aufschlüsselung (attachRoundBreakdown()) — von CupOverallRankingController (intern)
     * und Public\CupRankingController (öffentlich, baut hierauf zusätzlich die Sammel-Varianten
     * "Alle Klassen"/"Damen & Herren" auf, siehe rankedAcrossGroups()) gemeinsam genutzt, damit
     * diese Zusammenstellung nicht doppelt ausprogrammiert ist.
     *
     * @param  EloquentCollection<int, Meet>  $meets  siehe cupMeets(), einmal pro Cup ermittelt
     * @return Collection<int, array{gender: ?string, group: SportClassGroup, ageGroup: ?AgeGroup, results: Collection<int, CupOverallResult>}>
     */
    public function rankedBrackets(Cup $cup, EloquentCollection $meets): Collection
    {
        return $this->brackets($cup)
            ->map(function (array $bracket) use ($cup, $meets) {
                $results = $this->rankedBracket(
                    $cup->id, $bracket['gender'], $bracket['group']->id, $bracket['ageGroup']?->id
                );

                return [
                    'gender' => $bracket['gender'],
                    'group' => $bracket['group'],
                    'ageGroup' => $bracket['ageGroup'],
                    'results' => $this->attachRoundBreakdown($results, $cup, $meets),
                ];
            })
            ->values();
    }

    /**
     * Wie rankedBracket(), aber ohne Einschränkung auf eine einzelne Sportklassengruppe — für die
     * öffentliche Sammel-Ansicht "Alle Klassen" (Rückmeldung: "ich meinte, dass alle gemeinsam
     * über die Punkte gewertet werden"). Da ÖBSV-/WPS-Punkte klassenübergreifend vergleichbar
     * sind (genau dafür wurden sie eingeführt), ist ein nachträgliches Zusammenlegen bereits
     * berechneter cup_overall_results-Zeilen mehrerer Sportklassengruppen und Neu vergeben des
     * Rangs (SportRankAssigner, dieselbe Tie-Break-Regel) gleichwertig zu einer von vornherein
     * klassenübergreifend gewerteten Bucket-Berechnung — die Punkte selbst hängen nicht von der
     * Bucket-Zuordnung ab, nur die Rang-Konkurrenz.
     *
     * $gender = null bedeutet — wie bei rankedBracket() — kein Geschlechtsfilter (Damen und
     * Herren gemeinsam).
     *
     * @return Collection<int, CupOverallResult>
     */
    public function rankedAcrossGroups(int $cupId, ?string $gender, ?int $ageGroupId): Collection
    {
        $rows = CupOverallResult::where('cup_id', $cupId)
            ->when($gender !== null, fn ($q) => $q->where('gender', $gender))
            ->when(
                $ageGroupId === null,
                fn ($q) => $q->whereNull('age_group_id'),
                fn ($q) => $q->where('age_group_id', $ageGroupId)
            )
            ->with(['athlete', 'club', 'sportClassGroup', 'ageGroup'])
            ->orderByDesc('total_points')
            ->get();

        return $this->assignRanks($rows);
    }

    /**
     * Ermittelt die Wertungskategorien (Brackets) eines Cups dynamisch aus dem
     * vorhandenen Snapshot: Sportklassengruppe × Altersgruppe × Geschlecht.
     *
     * Es werden ausschließlich Kombinationen geliefert, für die tatsächlich
     * Gesamtwertungszeilen existieren. Ist für eine Sportklassengruppe die
     * gemeinsame Damen-/Herren-Wertung aktiviert (Cup::isGenderCombined), wird
     * daraus ein einziges Bracket mit gender = null.
     *
     * Sortierung: Sportklassengruppe (sort_order), dann Geschlecht, dann
     * Altersgruppe (sort_order); Zeilen ohne Altersgruppe zuletzt.
     *
     * @return Collection<int, array{gender: ?string, group: SportClassGroup, ageGroup: ?AgeGroup}>
     */
    public function brackets(Cup $cup): Collection
    {
        $rows = CupOverallResult::where('cup_id', $cup->id)
            ->with(['sportClassGroup', 'ageGroup'])
            ->get(['gender', 'sport_class_group_id', 'age_group_id']);

        $brackets = collect();

        foreach ($rows->groupBy(fn (CupOverallResult $row
        ) => "$row->sport_class_group_id|$row->age_group_id") as $groupRows) {
            $first = $groupRows->first();
            $group = $first->sportClassGroup;
            $ageGroup = $first->ageGroup;

            if ($cup->isGenderCombined($group)) {
                $brackets->push(['gender' => null, 'group' => $group, 'ageGroup' => $ageGroup]);

                continue;
            }

            foreach ($groupRows->pluck('gender')->unique() as $gender) {
                $brackets->push(['gender' => $gender, 'group' => $group, 'ageGroup' => $ageGroup]);
            }
        }

        return $brackets
            ->sortBy(fn (array $bracket) => sprintf(
                '%03d-%s-%03d',
                $bracket['group']->sort_order,
                $bracket['gender'] ?? '',
                $bracket['ageGroup']?->sort_order ?? 999
            ))
            ->values();
    }

    /**
     * Alle Meets dieses Cups in zeitlicher Reihenfolge — die "Runden" der
     * Gesamtwertungstabelle. Öffentlich, da sowohl die interne
     * (CupOverallRankingController) als auch die öffentliche
     * (Public\CupRankingController) Gesamtwertungsansicht dieselbe
     * Runden-Aufschlüsselung zeigen (attachRoundBreakdown()).
     *
     * @return EloquentCollection<int, Meet>
     */
    public function cupMeets(Cup $cup): EloquentCollection
    {
        return Meet::where('cup_id', $cup->id)->oldest('start_date')->get(['id', 'name', 'start_date']);
    }

    /**
     * Ergänzt jede Gesamtwertungszeile um eine "rounds"-Aufschlüsselung (eine
     * pro Meet des Cups), damit Nutzer die Punkte je Runde nachvollziehen
     * können. Nutzt counted_meet_ids, um zu markieren, welche Runden
     * tatsächlich in die Gesamtpunkte eingeflossen sind (beste X, Punkt 10).
     * Bewusst über meet_id statt über cup_daily_results.id verglichen — Letztere
     * werden bei jeder Neuberechnung der Tageswertung neu vergeben (Zeilen
     * werden gelöscht und neu angelegt), meet_id bleibt dagegen stabil.
     *
     * @param  Collection<int, CupOverallResult>  $rankedResults
     * @param  EloquentCollection<int, Meet>  $meets  siehe cupMeets(), einmal pro Cup ermittelt
     * @return Collection<int, CupOverallResult>
     */
    public function attachRoundBreakdown(Collection $rankedResults, Cup $cup, EloquentCollection $meets): Collection
    {
        if ($rankedResults->isEmpty()) {
            return $rankedResults;
        }

        $dailyByAthlete = CupDailyResult::where('cup_id', $cup->id)
            ->whereIn('athlete_id', $rankedResults->pluck('athlete_id'))
            ->whereIn('meet_id', $meets->pluck('id'))
            ->with('result:id,sport_class')
            ->get(['id', 'meet_id', 'athlete_id', 'points', 'result_id'])
            ->groupBy('athlete_id');

        return $rankedResults->map(function (CupOverallResult $row) use ($meets, $dailyByAthlete) {
            $countedMeetIds = collect($row->counted_meet_ids ?? []);
            $athleteDailyByMeet = ($dailyByAthlete[$row->athlete_id] ?? collect())->keyBy('meet_id');

            $row->rounds = $meets->map(function (Meet $meet) use ($athleteDailyByMeet, $countedMeetIds) {
                $daily = $athleteDailyByMeet->get($meet->id);

                return [
                    'points' => $daily?->points,
                    'sport_class' => $daily?->result?->sport_class,
                    'counted' => $daily !== null && $countedMeetIds->contains($meet->id),
                ];
            });

            return $row;
        });
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
            ->with(['athlete', 'sportClassGroup'])
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
        $ageGroup = $this->groupResolver->resolveAgeGroup(
            $athlete,
            "$cup->year-01-01",
            $cup,
            $first->sportClassGroup
        );

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
        return SportRankAssigner::assign($rowsSortedByPointsDesc, fn (CupOverallResult $row) => $row->total_points);
    }
}
