<?php

namespace App\Http\Controllers;

use App\Models\Athlete;
use App\Models\AthletePerformanceNote;
use App\Services\AthletePerformanceNoteService;
use App\Services\PdfExportService;
use App\Services\WpsAthleteAnalysisService;
use App\Services\WpsChartService;
use App\Support\WpsAthleteProfile;
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
 * Zwei Einstiege: über die Athletenverwaltung (dort wird ohnehin gesucht und geblättert) und
 * seit Phase 13 zusätzlich über die Athletenauswahl unter Statistik (picker()) — bewusst kein
 * eigenes, zweites Athleten-Verzeichnis, nur ein durchsuchbares Auswahlfeld. show() merkt sich
 * per Query-Parameter, über welchen Weg die Seite erreicht wurde, damit der Rückweg-Button zum
 * richtigen Ziel führt (Design-Feedback Erik, 15.09.2026).
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

    /**
     * GET /wps/athletes/{athlete}?from=athlete
     *
     * ?from=athlete kommt vom Link auf der Athleten-Detailseite (athletes/show.blade.php) — der
     * Rückweg-Button führt dann dorthin zurück statt zur Athletenauswahl (picker()), von wo aus
     * er sonst kommt.
     */
    public function show(Athlete $athlete, Request $request): View
    {
        $fromAthlete = $request->query('from') === 'athlete';

        return view('wps.athletes.show', [
            'athlete' => $athlete,
            'backUrl' => $fromAthlete ? route('athletes.show', $athlete) : route('wps.athletes.picker'),
            'backLabel' => $fromAthlete ? 'Zum Athleten' : 'Zur Athletenauswahl',
        ]);
    }

    /**
     * GET /statistics/wps-athlete-analysis
     *
     * Athleten-Auswahl als zweiter Einstieg zur Analyse, neben dem Weg über die
     * Athletenverwaltung — auf Wunsch aus der Statistik heraus erreichbar (Design-Feedback
     * Erik, 15.09.2026), ohne dabei die ursprüngliche Athletenliste zu duplizieren: nur ein
     * durchsuchbares Auswahlfeld, keine eigene, zweite Tabelle.
     */
    public function picker(): View
    {
        $athletes = Athlete::query()
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name']);

        return view('wps.athletes.picker', compact('athletes'));
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

        // Maß der Grafik aus der Adresse; Zeit als Rückfallwert, weil sie bei jedem Ergebnis
        // vorliegt.
        $mass = $request->query('metric') === WpsChartService::METRIC_POINTS
            ? WpsChartService::METRIC_POINTS
            : WpsChartService::METRIC_TIME;

        // Auswahl der Bewerbe; ohne Angabe kommen alle ins PDF.
        $gewaehlt = array_filter(explode('|', (string) $request->query('events', '')));

        if ($gewaehlt !== []) {
            $profil = new WpsAthleteProfile(
                $profil->athlete,
                $profil->byEvent->filter(
                    static fn ($zeilen, string $bewerb): bool => in_array($bewerb, $gewaehlt, true)
                ),
                $profil->sportClassesByCategory,
                $profil->firstYear,
                $profil->lastYear,
            );
        }

        $grafiken = [];

        foreach ($profil->byEvent as $bewerb => $zeilen) {
            $grafiken[$bewerb] = $this->chartService->series($bewerb, $zeilen, $notizen, $mass);
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
