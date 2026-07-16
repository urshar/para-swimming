<?php

namespace App\Http\Controllers;

use App\Models\CupDailyResult;
use App\Models\Meet;
use App\Models\SportClassGroup;
use App\Services\CupStalenessService;
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
        private readonly CupStalenessService $stalenessService,
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

        $status = $this->stalenessService->dailyRankingStatus($meet);

        return view('cups.daily-ranking', [
            'meet' => $meet,
            'brackets' => $brackets,
            'calculatedAt' => $status['calculatedAt'],
            'isStale' => $status['isStale'],
            'staleReason' => $status['reason'],
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
     * Liefert je vorhandener Wertungskategorie (Sportklassengruppe, ggf. nach
     * Geschlecht getrennt oder gemeinsam laut Cup::isGenderCombined())
     * die gerankte Athletenliste, sortiert nach Gruppen-Reihenfolge.
     *
     * @return Collection<int, array{gender: ?string, group: SportClassGroup, results: Collection<int, CupDailyResult>}>
     */
    private function resolveBrackets(Meet $meet): Collection
    {
        $cup = $meet->cup;

        $rows = CupDailyResult::where('meet_id', $meet->id)
            ->with('sportClassGroup')
            ->get(['gender', 'sport_class_group_id']);

        $byGroup = $rows->groupBy('sport_class_group_id');

        $brackets = collect();

        foreach ($byGroup as $groupRows) {
            $group = $groupRows->first()->sportClassGroup;

            if ($cup && $cup->isGenderCombined($group)) {
                $brackets->push(['gender' => null, 'group' => $group]);

                continue;
            }

            foreach ($groupRows->pluck('gender')->unique() as $gender) {
                $brackets->push(['gender' => $gender, 'group' => $group]);
            }
        }

        return $brackets
            ->sortBy(fn (array $bracket) => sprintf('%03d-%s', $bracket['group']->sort_order, $bracket['gender'] ?? ''))
            ->map(fn (array $bracket) => [
                'gender' => $bracket['gender'],
                'group' => $bracket['group'],
                'results' => $this->dailyRankingService->rankedBracket($meet->id, $bracket['gender'],
                    $bracket['group']->id),
            ])
            ->values();
    }
}
