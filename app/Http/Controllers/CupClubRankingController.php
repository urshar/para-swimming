<?php

namespace App\Http\Controllers;

use App\Models\Cup;
use App\Services\CupClubRankingService;
use App\Services\CupStalenessService;
use App\Services\PdfExportService;
use App\Support\ClubRankingConfiguration;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * CupClubRankingController
 *
 * Vereinswertung des ÖBSV Cups (Spec §13). Zeigt wahlweise die klassische
 * Startwertung (System A) oder die leistungsorientierte Wertung (System B) für
 * ein Cup-Jahr. Beide werden dynamisch berechnet und nicht persistiert — es
 * gibt daher keinen calculate-Schritt. Die Anzeige ist für alle angemeldeten
 * Nutzer offen (analog Tages-/Gesamtwertung). Der PDF-Export bildet dieselbe
 * Wertung mit denselben Filtern ab (Spec §17 Phase 4).
 */
class CupClubRankingController extends Controller
{
    /** @var list<string> */
    private const array SYSTEMS = ['start', 'performance'];

    private const string DEFAULT_SYSTEM = 'performance';

    public function __construct(
        private readonly CupClubRankingService $clubRankingService,
        private readonly CupStalenessService $stalenessService,
        private readonly PdfExportService $pdfExportService,
    ) {}

    /**
     * GET /vereinswertung
     *
     * Cup-Übersicht als Einstieg zur Vereinswertung (für alle angemeldeten
     * Nutzer), analog zur Gesamtwertungs-Übersicht.
     */
    public function index(): View
    {
        $cups = Cup::orderByDesc('year')->get();

        return view('cups.club-ranking-index', compact('cups'));
    }

    /**
     * GET /cups/{cup}/club-ranking?system=start|performance&foreign=0|1&kader=N
     *
     * Zeigt die gewählte Vereinswertung. Ausland-Schalter und Kaderanzahl
     * überschreiben je Aufruf die Konfigurationswerte; fehlt der Parameter, gilt
     * der Konfigurationswert. Der Staleness-Hinweis gilt nur für die
     * Leistungswertung, da nur sie auf dem cup_daily_results-Snapshot beruht.
     */
    public function show(Cup $cup, Request $request): View
    {
        return view('cups.club-ranking', [
            ...$this->resolveRankingData($cup, $request),
            'cup' => $cup,
            'cups' => Cup::orderByDesc('year')->get(['id', 'year', 'name']),
        ]);
    }

    /**
     * GET /cups/{cup}/club-ranking/pdf?system=&foreign=&kader=&detail=0|1
     *
     * PDF-Export mit denselben Filtern wie show(). detail=1 weist bei der
     * Leistungswertung zusätzlich die gewerteten Athleten je Verein aus.
     */
    public function pdf(Cup $cup, Request $request): Response
    {
        $data = $this->resolveRankingData($cup, $request);

        return $this->pdfExportService->stream('pdf.cup-club-ranking', [
            ...$data,
            'cup' => $cup,
            'detail' => $data['system'] === 'performance' && $request->boolean('detail'),
            'generatedAt' => now(),
        ], "cup-vereinswertung-{$data['system']}-$cup->id.pdf");
    }

    /**
     * Löst System, Filter und Rangliste aus dem Request auf — gemeinsam genutzt
     * von show() und pdf(), damit PDF und Ansicht garantiert dieselbe Wertung
     * zeigen.
     *
     * @return array{system: string, includeForeign: bool, kaderCount: int, maxCountedAthletes: int, ranking: Collection<int, object>, calculatedAt: ?Carbon, isStale: bool, staleReason: ?string}
     */
    private function resolveRankingData(Cup $cup, Request $request): array
    {
        $system = in_array($request->query('system'), self::SYSTEMS, true)
            ? $request->query('system')
            : self::DEFAULT_SYSTEM;

        $includeForeign = $request->has('foreign') ? $request->boolean('foreign') : null;

        $maxCountedAthletes = (int) config('cup_club_ranking.max_counted_athletes_per_club', 5);

        // Anzahl der je Verein gewerteten Kaderathleten (0 … max), per Aufruf
        // überschreibbar; fehlt der Parameter, gilt der Konfigurationswert.
        $kader = $request->has('kader')
            ? max(0, min($maxCountedAthletes, (int) $request->query('kader')))
            : null;

        if ($system === 'start') {
            $ranking = $this->clubRankingService->calculateStartRanking($cup, $includeForeign);
            $status = ['calculatedAt' => null, 'isStale' => false, 'reason' => null];
        } else {
            $config = ClubRankingConfiguration::fromConfig();

            if ($includeForeign !== null) {
                $config = $config->withIncludeForeignClubs($includeForeign);
            }

            if ($kader !== null) {
                $config = $config->withCountedKaderAthletesPerClub($kader);
            }

            $ranking = $this->clubRankingService->calculatePerformanceRanking($cup, $config);
            $status = $this->stalenessService->clubRankingStatus($cup);
        }

        return [
            'system' => $system,
            'includeForeign' => $includeForeign ?? (bool) config('cup_club_ranking.include_foreign_clubs', false),
            'kaderCount' => $kader ?? (int) config('cup_club_ranking.counted_kader_athletes_per_club', 0),
            'maxCountedAthletes' => $maxCountedAthletes,
            'ranking' => $ranking,
            'calculatedAt' => $status['calculatedAt'],
            'isStale' => $status['isStale'],
            'staleReason' => $status['reason'],
        ];
    }
}
