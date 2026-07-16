<?php

namespace App\Http\Controllers;

use App\Models\AgeGroup;
use App\Models\Cup;
use App\Models\CupDailyResult;
use App\Models\CupOverallResult;
use App\Models\Meet;
use App\Models\SportClassGroup;
use App\Services\CupStalenessService;
use App\Services\OverallRankingService;
use App\Services\PdfExportService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * CupOverallRankingController
 *
 * Zeigt die Gesamtwertung (Punkt 10 der Spec) für ein Cup-Jahr an und löst
 * die (Neu-)Berechnung aus. Die Anzeige ist für alle angemeldeten Nutzer
 * offen; das Neu berechnen ist admin-only, analog zur Tageswertung.
 */
class CupOverallRankingController extends Controller
{
    public function __construct(
        private readonly OverallRankingService $overallRankingService,
        private readonly PdfExportService $pdfExportService,
        private readonly CupStalenessService $stalenessService,
    ) {}

    /**
     * GET /cup-wertung
     *
     * Öffentliche Cup-Übersicht (für alle angemeldeten Nutzer) als Einstieg
     * zur Gesamtwertung — im Unterschied zu cups.index (CupController), das
     * die admin-only Konfigurations-CRUD-Liste ist.
     */
    public function index(): View
    {
        $cups = Cup::orderByDesc('year')->get();

        return view('cups.overall-ranking-index', compact('cups'));
    }

    /**
     * GET /cups/{cup}/overall-ranking
     *
     * Zeigt eine Rangliste pro vorhandener Wertungskategorie (Geschlecht +
     * Sportklassengruppe + Altersgruppe) auf Basis des zuletzt berechneten
     * Snapshots.
     */
    public function show(Cup $cup): View
    {
        $meets = $this->cupMeets($cup);
        $brackets = $this->resolveBrackets($cup, $meets);

        $status = $this->stalenessService->overallRankingStatus($cup);

        return view('cups.overall-ranking', [
            'cup' => $cup,
            'meets' => $meets,
            'brackets' => $brackets,
            'calculatedAt' => $status['calculatedAt'],
            'isStale' => $status['isStale'],
            'staleReason' => $status['reason'],
        ]);
    }

    /**
     * GET /cups/{cup}/overall-ranking/pdf
     *
     * Öffnet die Gesamtwertung als PDF im Browser (Punkt 11/12 der Spec).
     */
    public function pdf(Cup $cup): Response
    {
        $meets = $this->cupMeets($cup);

        return $this->pdfExportService->stream('pdf.cup-overall-ranking', [
            'cup' => $cup,
            'meets' => $meets,
            'brackets' => $this->resolveBrackets($cup, $meets),
            'calculatedAt' => CupOverallResult::where('cup_id', $cup->id)->max('calculated_at'),
        ], "cup-gesamtwertung-$cup->id.pdf", orientation: 'landscape');
    }

    /**
     * POST /cups/{cup}/overall-ranking/calculate
     *
     * @throws Throwable bei einem unerwarteten Fehler innerhalb der Berechnung
     */
    public function calculate(Cup $cup): RedirectResponse
    {
        abort_unless(auth()->user()?->is_admin, 403, 'Nur für Administratoren.');

        $this->overallRankingService->calculateForCup($cup);

        return redirect()
            ->route('cups.overall-ranking.show', $cup)
            ->with('success', 'Gesamtwertung wurde neu berechnet.');
    }

    /** Alle Meets dieses Cups in zeitlicher Reihenfolge — die "Runden" der Gesamtwertungs-Tabelle. */
    private function cupMeets(Cup $cup): EloquentCollection
    {
        return Meet::where('cup_id', $cup->id)->orderBy('start_date')->get(['id', 'name', 'start_date']);
    }

    /**
     * Liefert je vorhandener Wertungskategorie (Sportklassengruppe + Altersgruppe,
     * ggf. nach Geschlecht getrennt oder gemeinsam laut Cup::isGenderCombined())
     * die gerankte Athletenliste — inkl. Runden-Aufschlüsselung (siehe attachRoundBreakdown()).
     *
     * @param  EloquentCollection<int, Meet>  $meets  siehe cupMeets(), einmal pro Cup ermittelt
     * @return Collection<int, array{gender: ?string, group: SportClassGroup, ageGroup: ?AgeGroup, results: Collection<int, CupOverallResult>}>
     */
    private function resolveBrackets(Cup $cup, EloquentCollection $meets): Collection
    {
        $rows = CupOverallResult::where('cup_id', $cup->id)
            ->with(['sportClassGroup', 'ageGroup'])
            ->get(['gender', 'sport_class_group_id', 'age_group_id']);

        $byGroupAndAge = $rows->groupBy(fn (CupOverallResult $row) => "$row->sport_class_group_id|$row->age_group_id");

        $brackets = collect();

        foreach ($byGroupAndAge as $groupRows) {
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
            ->map(function (array $bracket) use ($cup, $meets) {
                $results = $this->overallRankingService->rankedBracket(
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
     * Ergänzt jede Gesamtwertungs-Zeile um eine "rounds"-Aufschlüsselung (eine
     * pro Meet des Cups), damit Nutzer die Punkte je Runde nachvollziehen
     * können. Nutzt counted_meet_ids, um zu markieren, welche Runden
     * tatsächlich in die Gesamtpunkte eingeflossen sind (beste X, Punkt 10).
     * Bewusst über meet_id statt über cup_daily_results.id verglichen — Letztere
     * werden bei jeder Neuberechnung der Tageswertung neu vergeben (Zeilen
     * werden gelöscht und neu angelegt), meet_id bleibt dagegen stabil.
     *
     * @param  Collection<int, CupOverallResult>  $rankedResults
     * @param  EloquentCollection<int, Meet>  $meets
     * @return Collection<int, CupOverallResult>
     */
    private function attachRoundBreakdown(Collection $rankedResults, Cup $cup, EloquentCollection $meets): Collection
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
}
