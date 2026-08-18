<?php

namespace App\Http\Controllers;

use App\Services\PdfExportService;
use App\Services\WpsClubRankingService;
use App\Support\WpsClubRankingConfiguration;
use App\Support\WpsClubRankingEntry;
use App\Support\WpsRankingFilter;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vereinsauswertung (Spec "WPS Rankings" §9) — ein Analysewerkzeug, keine offizielle
 * ÖBSV-Wertung.
 *
 * Vereinsnutzer sehen nur den eigenen Verein (**[R2]**); das ist die einzige Ansicht dieses
 * Moduls mit dieser Einschränkung.
 */
class WpsClubRankingController extends Controller
{
    public function __construct(
        private readonly WpsClubRankingService $service,
        private readonly PdfExportService $pdfExportService,
    ) {}

    public function show(): View
    {
        return view('wps.clubs.index');
    }

    public function pdf(Request $request): Response
    {
        $filter = WpsRankingFilter::fromQuery($request->query());
        $config = WpsClubRankingConfiguration::fromQuery($request->query());

        $eintraege = $this->service->ranking($filter, $config, $this->clubFilter());

        return $this->pdfExportService->stream(
            'pdf.wps-club-ranking',
            [
                'filter' => $filter,
                'config' => $config,
                'ranked' => $eintraege->reject(
                    static fn (WpsClubRankingEntry $e): bool => $e->isBelowMinimum()
                )->values(),
                'belowMinimum' => $eintraege->filter(
                    static fn (WpsClubRankingEntry $e): bool => $e->isBelowMinimum()
                )->values(),
                'generatedAt' => now(),
            ],
            sprintf('wps-vereinsauswertung-%s.pdf', now()->format('Y-m-d')),
        );
    }

    private function clubFilter(): ?int
    {
        $nutzer = auth()->user();

        return $nutzer?->is_admin === true ? null : $nutzer?->club_id;
    }
}
