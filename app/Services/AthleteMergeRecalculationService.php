<?php

namespace App\Services;

use App\Models\Cup;
use App\Models\CupDailyResult;
use App\Models\CupOverallResult;
use App\Models\CupTopGroupClassification;
use App\Models\Meet;
use App\Models\QualifyingTimeList;
use Throwable;

/**
 * Neuberechnung nach dem Zusammenführen zweier Athleten (AthleteMergeService): Cup-Wertungen und Qualifikationen
 * sind Snapshots, die nach dem Umhängen nicht mehr stimmen.
 *
 * Je Cup werden nur die Stufen neu berechnet, die schon einmal berechnet wurden, in der Reihenfolge der
 * Berechnungskette (siehe CupStalenessService): Topgruppe → Tageswertung je Cup-Wettkampf → Gesamtwertung. Eine nie
 * berechnete Wertung legt das Zusammenführen nicht an. Je Richtzeitenliste ersetzt calculateForList() alle
 * Qualifikationen; meldet sie einen Fehler (z. B. kein Zeitraum-Beginn), landet er im Bericht, bricht aber nichts ab.
 */
final readonly class AthleteMergeRecalculationService
{
    public function __construct(
        private TopGroupClassificationService $topGroupClassificationService,
        private DailyRankingService $dailyRankingService,
        private OverallRankingService $overallRankingService,
        private QualificationDeterminationService $qualificationDeterminationService,
    ) {}

    /**
     * @param  list<int>  $cupIds
     * @param  list<int>  $qualifyingTimeListIds
     * @return array{
     *     cups: list<array{id: int, name: string, stages: list<string>}>,
     *     qualifying_time_lists: list<array{id: int, year: int, qualified: int}>,
     *     errors: list<string>,
     * }
     *
     * @throws Throwable
     */
    public function recalculate(array $cupIds, array $qualifyingTimeListIds): array
    {
        $report = ['cups' => [], 'qualifying_time_lists' => [], 'errors' => []];

        foreach (Cup::whereKey($cupIds)->orderBy('year')->get() as $cup) {
            $stages = $this->recalculateCup($cup);
            if ($stages !== []) {
                $report['cups'][] = ['id' => $cup->id, 'name' => $cup->name, 'stages' => $stages];
            }
        }

        foreach (QualifyingTimeList::whereKey($qualifyingTimeListIds)->orderBy('year')->get() as $list) {
            $result = $this->qualificationDeterminationService->calculateForList($list);
            if (isset($result['error'])) {
                $report['errors'][] = $result['error'];
            } else {
                $report['qualifying_time_lists'][] = ['id' => $list->id, 'year' => $list->year,
                    'qualified' => $result['qualified']];
            }
        }

        return $report;
    }

    /**
     * @return list<string> neu berechnete Stufen
     *
     * @throws Throwable
     */
    private function recalculateCup(Cup $cup): array
    {
        $stages = [];

        if (CupTopGroupClassification::where('cup_id', $cup->id)->exists()) {
            $this->topGroupClassificationService->calculateForCup($cup);
            $stages[] = 'Topgruppe';
        }

        foreach (Meet::where('cup_id', $cup->id)->oldest('start_date')->get() as $meet) {
            if (CupDailyResult::where('cup_id', $cup->id)->where('meet_id', $meet->id)->exists()) {
                $this->dailyRankingService->calculateForMeet($meet);
                $stages[] = "Tageswertung $meet->name";
            }
        }

        if (CupOverallResult::where('cup_id', $cup->id)->exists()) {
            $this->overallRankingService->calculateForCup($cup);
            $stages[] = 'Gesamtwertung';
        }

        return $stages;
    }
}
