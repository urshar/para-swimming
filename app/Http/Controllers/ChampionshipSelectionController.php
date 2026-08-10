<?php

namespace App\Http\Controllers;

use App\Models\Championship;
use App\Services\PdfExportService;
use App\Services\QualificationSelectionService;
use App\Support\QualificationRankingEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Auswahl-Rangliste (Spec §8) und ihre PDF-Ausgabe (§11).
 *
 * Beantwortet die dritte Frage des Moduls — *wer fährt?* — und greift erst, wenn aus der
 * Qualifikantenliste mehr Namen kommen als Startplätze vorhanden sind. Die Auswahl selbst
 * trifft ein Mensch; die Rangliste liefert nur die Reihenfolge.
 *
 * Lesend, deshalb ohne RequireAdmin; Vereinsnutzer sehen nur die Athleten ihres Vereins.
 */
class ChampionshipSelectionController extends Controller
{
    public function __construct(
        private readonly QualificationSelectionService $selectionService,
        private readonly PdfExportService $pdfExportService,
    ) {}

    public function show(Request $request, Championship $championship): View
    {
        $clubId = $this->clubFilter();

        return view('championships.selection', [
            'championship' => $championship,
            'eventRankings' => $this->selectionService->rankingByEvent($championship, $clubId),
            'athleteRanking' => $this->selectionService->rankingByAthlete($championship, $clubId),
            // "beste n" blendet weitere Plätze aus, ohne sie zu löschen (§8).
            'limit' => $this->limit($request),
        ]);
    }

    public function pdf(Request $request, Championship $championship): Response
    {
        $clubId = $this->clubFilter();

        return $this->pdfExportService->stream(
            'pdf.championship-selection',
            [
                'championship' => $championship,
                'eventRankings' => $this->selectionService->rankingByEvent($championship, $clubId),
                'athleteRanking' => $this->selectionService->rankingByAthlete($championship, $clubId),
                'limit' => $this->limit($request),
                'generatedAt' => now(),
            ],
            $this->filename($championship),
        );
    }

    /**
     * Kürzt eine Rangliste auf die Obergrenze.
     *
     * @param  Collection<int, QualificationRankingEntry>  $eintraege
     * @return Collection<int, QualificationRankingEntry>
     *
     * @used-by championships.selection
     * @used-by pdf.championship-selection
     */
    public static function applyLimit(Collection $eintraege, ?int $limit): Collection
    {
        return $limit === null ? $eintraege : $eintraege->take($limit);
    }

    /**
     * Obergrenze der angezeigten Plätze.
     *
     * null bedeutet "alle". Werte unter 1 werden verworfen statt auf 1 gehoben — eine
     * Rangliste ohne Zeilen ist offensichtlich nicht gemeint, und stillschweigend etwas
     * anderes anzuzeigen als angefordert wäre schlechter als der Standardwert.
     */
    private function limit(Request $request): ?int
    {
        $wert = $request->integer('limit');

        return $wert >= 1 ? $wert : null;
    }

    private function clubFilter(): ?int
    {
        $nutzer = auth()->user();

        return $nutzer?->is_admin === true ? null : $nutzer?->club_id;
    }

    private function filename(Championship $championship): string
    {
        return sprintf(
            'auswahl-%s-%s.pdf',
            str($championship->display_name)->slug(),
            now()->format('Y-m-d'),
        );
    }
}
