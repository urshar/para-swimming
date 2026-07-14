<?php

namespace App\Http\Controllers;

use App\Models\CupDailyResult;
use App\Models\Meet;
use App\Models\SportClassGroup;
use App\Services\DailyRankingService;
use App\Services\PdfExportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * CupDailyRankingController
 *
 * Zeigt die Tageswertung (Punkt 9 der Spec) für ein Cup-Meet an und löst die
 * (Neu-)Berechnung aus. Die Anzeige ist für alle angemeldeten Nutzer offen
 * (wie Ergebnisse/Meldungen generell); das Neu berechnen ist admin-only, da es
 * den persistierten Cup-Wertungs-Snapshot verändert.
 */
class CupDailyRankingController extends Controller
{
    public function __construct(
        private readonly DailyRankingService $dailyRankingService,
        private readonly PdfExportService $pdfExportService,
    ) {}

    /**
     * GET /meets/{meet}/cup-daily-ranking
     *
     * Zeigt eine Rangliste pro vorhandener Wertungskategorie (Geschlecht +
     * Sportklassengruppe) auf Basis des zuletzt berechneten Snapshots.
     */
    public function show(Meet $meet): View
    {
        abort_unless($meet->cup_id, 404, 'Dieses Meet ist keinem Cup zugeordnet.');

        $meet->load('cup');

        $brackets = $this->resolveBrackets($meet);

        $calculatedAt = CupDailyResult::where('meet_id', $meet->id)->max('calculated_at');

        return view('cups.daily-ranking', [
            'meet' => $meet,
            'brackets' => $brackets,
            'calculatedAt' => $calculatedAt,
        ]);
    }

    /**
     * GET /meets/{meet}/cup-daily-ranking/pdf
     *
     * Öffnet die Tageswertung als PDF im Browser (Punkt 11/12 der Spec).
     */
    public function pdf(Meet $meet): Response
    {
        abort_unless($meet->cup_id, 404, 'Dieses Meet ist keinem Cup zugeordnet.');

        $meet->load('cup');

        return $this->pdfExportService->stream('pdf.cup-daily-ranking', [
            'meet' => $meet,
            'brackets' => $this->resolveBrackets($meet),
            'calculatedAt' => CupDailyResult::where('meet_id', $meet->id)->max('calculated_at'),
        ], "cup-tageswertung-$meet->id.pdf");
    }

    /**
     * POST /meets/{meet}/cup-daily-ranking/calculate
     *
     * @throws Throwable bei einem unerwarteten Fehler innerhalb der Berechnung
     *                   (eine fehlende Cup-Zuordnung wird separat abgefangen, siehe unten)
     */
    public function calculate(Meet $meet): RedirectResponse
    {
        abort_unless(auth()->user()?->is_admin, 403, 'Nur für Administratoren.');

        try {
            $this->dailyRankingService->calculateForMeet($meet);
        } catch (InvalidArgumentException $e) {
            return redirect()
                ->route('meets.show', $meet)
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('meets.cup-daily-ranking.show', $meet)
            ->with('success', 'Tageswertung wurde neu berechnet.');
    }

    /**
     * Liefert je vorhandener Wertungskategorie (Geschlecht + Sportklassengruppe,
     * sortiert nach Gruppen-Reihenfolge) die gerankte Athletenliste.
     *
     * @return Collection<int, array{gender: string, group: SportClassGroup, results: Collection<int, CupDailyResult>}>
     */
    private function resolveBrackets(Meet $meet): Collection
    {
        $combinations = CupDailyResult::where('meet_id', $meet->id)
            ->with('sportClassGroup')
            ->get(['gender', 'sport_class_group_id'])
            ->unique(fn (CupDailyResult $row) => $row->gender.'-'.$row->sport_class_group_id)
            ->sortBy(fn (CupDailyResult $row) => sprintf('%03d-%s', $row->sportClassGroup->sort_order, $row->gender));

        return $combinations->map(fn (CupDailyResult $row) => [
            'gender' => $row->gender,
            'group' => $row->sportClassGroup,
            'results' => $this->dailyRankingService->rankedBracket($meet->id, $row->gender, $row->sport_class_group_id),
        ])->values();
    }
}
