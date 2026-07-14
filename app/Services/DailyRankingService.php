<?php

namespace App\Services;

use App\Models\Cup;
use App\Models\CupDailyResult;
use App\Models\Meet;
use App\Models\Result;
use App\Models\SportClassGroup;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * DailyRankingService
 *
 * Berechnet die Tageswertung (Punkt 9 der Spec) für ein einzelnes Cup-Meet
 * und persistiert sie als Snapshot in cup_daily_results (Erik: "Snapshot je
 * Berechnungslauf", kein Live-Recompute). Ein erneuter Aufruf von
 * calculateForMeet() ersetzt den bisherigen Snapshot dieses Meets vollständig.
 *
 * Wertungskategorie = Geschlecht + Sportklassengruppe (KEINE Altersgruppe —
 * die kommt laut Erik erst bei der Gesamtwertung dazu, siehe OverallRankingService).
 *
 * Gewertet wird pro Athlet nur das beste gültige Ergebnis des Tages (Punkt 9).
 * Die Punkte werden dabei NICHT ungeprüft aus results.points übernommen,
 * sondern über WorldAquaticsPointsService gegen die im Cup konfigurierte
 * Basiswert-Version neu berechnet (Erik: das beim Speichern/Import gesetzte
 * results.points könnte auf einer anderen — z.B. älteren — Basiswert-Version
 * beruhen als der, die für diese Cup-Saison gilt). results.points selbst wird
 * dabei NICHT überschrieben; die neu berechneten Punkte fließen ausschließlich
 * in den Cup-Wertungs-Snapshot ein. Ergebnisse, für die sich mit der
 * Cup-Basiswert-Version keine Punkte berechnen lassen (z.B. fehlender
 * Basiswert-Eintrag), fließen nicht ein — ebenso wie Ergebnisse ohne
 * zugeordnete Sportklassengruppe (z.B. künftige Staffel-Klassen) und
 * ungültige Ergebnisse (DSQ/DNS/DNF/WDR).
 */
readonly class DailyRankingService
{
    public function __construct(
        private GroupResolverService $groupResolver,
        private TopGroupClassificationService $topGroupClassificationService,
        private WorldAquaticsPointsService $pointsService,
    ) {}

    /**
     * Berechnet die Tageswertung für ein Meet neu und ersetzt den bisherigen
     * Snapshot. Wirft eine Exception, wenn das Meet keinem Cup zugeordnet ist.
     *
     * @return EloquentCollection<int, CupDailyResult>
     *
     * @throws InvalidArgumentException wenn das Meet keinem Cup zugeordnet ist
     * @throws Throwable bei einem Fehler innerhalb der Transaktion
     */
    public function calculateForMeet(Meet $meet): EloquentCollection
    {
        $cup = $meet->cup;

        if (! $cup) {
            throw new InvalidArgumentException("Meet \"$meet->name\" ist keinem Cup zugeordnet.");
        }

        DB::transaction(function () use ($meet, $cup) {
            CupDailyResult::where('cup_id', $cup->id)->where('meet_id', $meet->id)->delete();

            $bestPerAthlete = $this->resolveBestResultsPerAthlete($meet, $cup);

            $calculatedAt = now();

            foreach ($bestPerAthlete as $athleteId => $entry) {
                CupDailyResult::create([
                    'cup_id' => $cup->id,
                    'meet_id' => $meet->id,
                    'athlete_id' => $athleteId,
                    'club_id' => $entry['result']->club_id,
                    'result_id' => $entry['result']->id,
                    'sport_class_group_id' => $entry['group']->id,
                    'gender' => $entry['gender'],
                    'points' => $entry['points'],
                    'calculated_at' => $calculatedAt,
                ]);
            }
        });

        return CupDailyResult::where('cup_id', $cup->id)->where('meet_id', $meet->id)->get();
    }

    /**
     * Für jeden Athleten das punktbeste gültige, einer Gruppe zugeordnete
     * Ergebnis des Meets — Punkte neu berechnet gegen die Cup-Basiswert-Version.
     *
     * @return array<int, array{result: Result, group: SportClassGroup, gender: string, points: int}>
     */
    private function resolveBestResultsPerAthlete(Meet $meet, Cup $cup): array
    {
        $sportClassMap = $this->groupResolver->loadSportClassMap();
        $topGroupClassificationMap = $this->topGroupClassificationService->loadClassificationMap($cup);
        $cupVersion = $cup->baseTimeVersion;

        $results = $meet->results()
            ->with(['athlete', 'club.nation', 'swimEvent.strokeType'])
            ->get();

        $best = [];

        foreach ($results as $result) {
            if (! $result->isValid() || ! $result->athlete || ! $result->athlete->gender) {
                continue;
            }

            $cupPoints = $this->pointsService->calculatePoints($result, $meet, $cupVersion);

            if ($cupPoints === null) {
                continue; // z.B. kein Basiswert-Eintrag für diese Sportklasse/Bewerb in der Cup-Version
            }

            $group = $this->groupResolver->resolveSportClassGroup($result, $cup, $sportClassMap, $topGroupClassificationMap);

            if (! $group) {
                continue; // Sportklasse keiner aktiven Gruppe zugeordnet (z.B. Staffel)
            }

            $athleteId = $result->athlete_id;
            $current = $best[$athleteId] ?? null;

            if (! $current || $cupPoints > $current['points']) {
                $best[$athleteId] = [
                    'result' => $result,
                    'group' => $group,
                    'gender' => $result->athlete->gender,
                    'points' => $cupPoints,
                ];
            }
        }

        return $best;
    }

    /**
     * Rangliste einer Wertungskategorie ("Damen PI" etc.) innerhalb eines Meets,
     * inklusive Rang (Sportwertung: gleiche Punkte = gleicher Rang, nächster
     * Rang überspringt entsprechend). Der Rang wird beim Lesen aus den
     * gespeicherten Punkten abgeleitet, nicht separat gespeichert.
     *
     * @return Collection<int, CupDailyResult>
     */
    public function rankedBracket(int $meetId, string $gender, int $sportClassGroupId): Collection
    {
        $rows = CupDailyResult::forBracket($meetId, $gender, $sportClassGroupId)
            ->with(['athlete', 'club'])
            ->get();

        return $this->assignRanks($rows);
    }

    /**
     * @param  Collection<int, CupDailyResult>  $rowsSortedByPointsDesc  absteigend nach points sortiert
     * @return Collection<int, CupDailyResult>  dieselben Zeilen, jeweils mit dynamischem rank-Attribut
     */
    private function assignRanks(Collection $rowsSortedByPointsDesc): Collection
    {
        $rank = 0;
        $position = 0;
        $previousPoints = null;

        return $rowsSortedByPointsDesc->map(function (CupDailyResult $row) use (&$rank, &$position, &$previousPoints) {
            $position++;

            if ($previousPoints === null || $row->points < $previousPoints) {
                $rank = $position;
            }

            $previousPoints = $row->points;
            $row->rank = $rank;

            return $row;
        });
    }
}
