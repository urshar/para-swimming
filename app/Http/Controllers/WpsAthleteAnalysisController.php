<?php

namespace App\Http\Controllers;

use App\Models\Athlete;
use App\Services\PdfExportService;
use App\Services\WpsAthleteAnalysisService;
use App\Support\WpsRankingFilter;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Athletenanalyse (Spec "WPS Rankings" §7).
 *
 * Die Profilansicht ist eine Livewire-Komponente; der Controller liefert die Suchseite und
 * die PDF-Ausgabe. Lesend, verbandsweit.
 */
class WpsAthleteAnalysisController extends Controller
{
    public function __construct(
        private readonly WpsAthleteAnalysisService $service,
        private readonly PdfExportService $pdfExportService,
    ) {}

    public function show(Athlete $athlete): View
    {
        return view('wps.athletes.show', ['athlete' => $athlete]);
    }

    public function pdf(Request $request, Athlete $athlete): Response
    {
        $bahn = strtoupper((string) $request->query('course', WpsRankingFilter::COURSE_MIXED));

        $profil = $this->service->profile(
            $athlete,
            $request->integer('from') ?: null,
            $request->integer('to') ?: null,
            in_array($bahn, WpsRankingFilter::courses(), true) ? $bahn : WpsRankingFilter::COURSE_MIXED,
        );

        return $this->pdfExportService->stream(
            'pdf.wps-athlete-analysis',
            ['profile' => $profil, 'generatedAt' => now()],
            sprintf(
                'athletenanalyse-%s-%s.pdf',
                str($athlete->full_name)->slug(),
                now()->format('Y-m-d'),
            ),
        );
    }
}
