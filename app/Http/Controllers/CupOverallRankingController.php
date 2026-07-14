<?php

namespace App\Http\Controllers;

use App\Models\AgeGroup;
use App\Models\Cup;
use App\Models\CupOverallResult;
use App\Models\SportClassGroup;
use App\Services\OverallRankingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\View\View;
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
    ) {}

    /**
     * GET /cups/{cup}/overall-ranking
     *
     * Zeigt eine Rangliste pro vorhandener Wertungskategorie (Geschlecht +
     * Sportklassengruppe + Altersgruppe) auf Basis des zuletzt berechneten
     * Snapshots.
     */
    public function show(Cup $cup): View
    {
        $brackets = $this->resolveBrackets($cup);

        $calculatedAt = CupOverallResult::where('cup_id', $cup->id)->max('calculated_at');

        return view('cups.overall-ranking', [
            'cup' => $cup,
            'brackets' => $brackets,
            'calculatedAt' => $calculatedAt,
        ]);
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

    /**
     * Liefert je vorhandener Wertungskategorie (Geschlecht + Sportklassengruppe
     * + Altersgruppe, sortiert nach Gruppen-Reihenfolge) die gerankte
     * Athletenliste.
     *
     * @return Collection<int, array{gender: string, group: SportClassGroup, ageGroup: ?AgeGroup, results: Collection<int, CupOverallResult>}>
     */
    private function resolveBrackets(Cup $cup): Collection
    {
        $combinations = CupOverallResult::where('cup_id', $cup->id)
            ->with(['sportClassGroup', 'ageGroup'])
            ->get(['gender', 'sport_class_group_id', 'age_group_id'])
            ->unique(fn (CupOverallResult $row) => "$row->gender-$row->sport_class_group_id-$row->age_group_id")
            ->sortBy(fn (CupOverallResult $row) => sprintf(
                '%03d-%s-%03d',
                $row->sportClassGroup->sort_order,
                $row->gender,
                $row->ageGroup?->sort_order ?? 999
            ));

        return $combinations->map(fn (CupOverallResult $row) => [
            'gender' => $row->gender,
            'group' => $row->sportClassGroup,
            'ageGroup' => $row->ageGroup,
            'results' => $this->overallRankingService->rankedBracket(
                $cup->id, $row->gender, $row->sport_class_group_id, $row->age_group_id
            ),
        ])->values();
    }
}
