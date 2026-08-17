<?php

namespace App\Http\Controllers;

use App\Models\Athlete;
use App\Models\AthletePerformanceNote;
use App\Services\AthletePerformanceNoteService;
use App\Services\PdfExportService;
use App\Services\WpsAthleteAnalysisService;
use App\Services\WpsChartService;
use App\Support\WpsRankingFilter;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Athletenanalyse (Spec "WPS Rankings" §7).
 *
 * Die Profilansicht ist eine Livewire-Komponente; der Controller reicht nur durch und liefert
 * die PDF-Ausgabe.
 *
 * Kein eigener Sucheinstieg: Der Weg führt über die Athletenverwaltung, wo ohnehin gesucht
 * und geblättert wird. Eine zweite Athletenliste daneben wäre überflüssig.
 *
 * Lesend, verbandsweit — die Notizen im PDF unterliegen dagegen der Sichtbarkeitsregel
 * aus §7.5.
 */
class WpsAthleteAnalysisController extends Controller
{
    public function __construct(
        private readonly WpsAthleteAnalysisService $service,
        private readonly WpsChartService $chartService,
        private readonly AthletePerformanceNoteService $noteService,
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

        // Notizen erscheinen nur auf ausdrücklichen Wunsch: Ein PDF wird weitergegeben, und
        // eine Krankheitsnotiz landete sonst womöglich außerhalb des vorgesehenen Kreises
        // (§7.5). Zusätzlich muss der Abrufende sie überhaupt sehen dürfen.
        $mitNotizen = $request->boolean('notes')
            && auth()->user()?->can('viewForAthlete', [AthletePerformanceNote::class, $athlete]) === true;

        $notizen = $mitNotizen
            ? $this->noteService->forAthlete($athlete, $request->integer('from') ?: null, $request->integer('to') ?: null)
            : collect();

        $grafiken = [];

        foreach ($profil->byEvent as $bewerb => $zeilen) {
            $grafiken[$bewerb] = $this->chartService->series($bewerb, $zeilen, $notizen);
        }

        return $this->pdfExportService->stream(
            'pdf.wps-athlete-analysis',
            [
                'profile' => $profil,
                'charts' => $grafiken,
                'notes' => $notizen,
                'notesByResult' => $this->noteService->indexByResult($notizen),
                'generatedAt' => now(),
            ],
            sprintf(
                'athletenanalyse-%s-%s.pdf',
                str($athlete->full_name)->slug(),
                now()->format('Y-m-d'),
            ),
        );
    }
}
