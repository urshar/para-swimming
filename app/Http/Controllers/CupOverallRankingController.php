<?php

namespace App\Http\Controllers;

use App\Models\Cup;
use App\Models\CupOverallResult;
use App\Services\CupStalenessService;
use App\Services\OverallRankingService;
use App\Services\PdfExportService;
use Illuminate\Http\RedirectResponse;
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
        $meets = $this->overallRankingService->cupMeets($cup);
        $brackets = $this->overallRankingService->rankedBrackets($cup, $meets);

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
        $meets = $this->overallRankingService->cupMeets($cup);

        return $this->pdfExportService->stream('pdf.cup-overall-ranking', [
            'cup' => $cup,
            'meets' => $meets,
            'brackets' => $this->overallRankingService->rankedBrackets($cup, $meets),
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
}
