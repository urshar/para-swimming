<?php

namespace App\Http\Controllers;

use App\Models\Cup;
use App\Services\CupClubRankingService;
use App\Services\CupStalenessService;
use App\Support\ClubRankingConfiguration;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * CupClubRankingController
 *
 * Vereinswertung des ÖBSV Cups (Spec §13). Zeigt wahlweise die klassische
 * Startwertung (System A) oder die leistungsorientierte Wertung (System B) für
 * ein Cup-Jahr. Beide werden dynamisch berechnet und nicht persistiert — es
 * gibt daher keinen calculate-Schritt. Die Anzeige ist für alle angemeldeten
 * Nutzer offen (analog Tages-/Gesamtwertung). Der PDF-Export folgt in Phase 4.
 */
class CupClubRankingController extends Controller
{
    /** @var list<string> */
    private const array SYSTEMS = ['start', 'performance'];

    private const string DEFAULT_SYSTEM = 'performance';

    public function __construct(
        private readonly CupClubRankingService $clubRankingService,
        private readonly CupStalenessService $stalenessService,
    ) {}

    /**
     * GET /vereinswertung
     *
     * Cup-Übersicht als Einstieg zur Vereinswertung (für alle angemeldeten
     * Nutzer), analog zur Gesamtwertungsübersicht.
     */
    public function index(): View
    {
        $cups = Cup::orderByDesc('year')->get();

        return view('cups.club-ranking-index', compact('cups'));
    }

    /**
     * GET /cups/{cup}/club-ranking?system=start|performance&foreign=0|1
     *
     * Zeigt die gewählte Vereinswertung. Der Ausland-Schalter überschreibt den
     * Konfigurationswert (cup_club_ranking.include_foreign_clubs) je Aufruf;
     * fehlt der Parameter, gilt der Konfigurationswert. Der Staleness-Hinweis
     * gilt nur für die Leistungswertung, da nur sie auf dem
     * cup_daily_results-Snapshot beruht.
     */
    public function show(Cup $cup, Request $request): View
    {
        $system = in_array($request->query('system'), self::SYSTEMS, true)
            ? $request->query('system')
            : self::DEFAULT_SYSTEM;

        $includeForeign = $request->has('foreign') ? $request->boolean('foreign') : null;

        if ($system === 'start') {
            $ranking = $this->clubRankingService->calculateStartRanking($cup, $includeForeign);
            $status = ['calculatedAt' => null, 'isStale' => false, 'reason' => null];
        } else {
            $config = ClubRankingConfiguration::fromConfig();

            if ($includeForeign !== null) {
                $config = $config->withIncludeForeignClubs($includeForeign);
            }

            $ranking = $this->clubRankingService->calculatePerformanceRanking($cup, $config);
            $status = $this->stalenessService->clubRankingStatus($cup);
        }

        return view('cups.club-ranking', [
            'cup' => $cup,
            'cups' => Cup::orderByDesc('year')->get(['id', 'year', 'name']),
            'system' => $system,
            'includeForeign' => $includeForeign ?? (bool) config('cup_club_ranking.include_foreign_clubs', false),
            'ranking' => $ranking,
            'calculatedAt' => $status['calculatedAt'],
            'isStale' => $status['isStale'],
            'staleReason' => $status['reason'],
        ]);
    }
}
