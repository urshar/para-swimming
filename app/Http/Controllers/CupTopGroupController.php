<?php

namespace App\Http\Controllers;

use App\Models\Athlete;
use App\Models\Cup;
use App\Models\CupDailyResult;
use App\Models\CupTopGroupClassification;
use App\Models\Meet;
use App\Models\Result;
use App\Services\CupStalenessService;
use App\Services\TopGroupClassificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * CupTopGroupController
 *
 * Einstufungsliste der Top-Gruppe je Cup (Spec cup-top-group.md, Phase 2): zeigt alle eingestuften Athleten mit
 * Grund und den Punkten der letzten zwei Cup-Jahre mit Start, stößt die Einstufung an und erlaubt eine
 * Hand-Einstufung, die eine Neuberechnung nicht überschreibt. Lesen: module:cup,read; Einstufen und
 * Hand-Einstufung: module:cup,write.
 */
class CupTopGroupController extends Controller
{
    public function __construct(
        private readonly CupStalenessService $stalenessService,
        private readonly TopGroupClassificationService $topGroupClassificationService,
    ) {}

    /**
     * POST /cups/{cup}/classify-top-group
     *
     * Stuft die Top-Gruppe des Cups (neu) ein, siehe TopGroupClassificationService. MUSS vor der Tageswertung
     * dieses Cup-Jahres laufen.
     *
     * @throws Throwable bei einem Fehler innerhalb der Berechnung
     */
    public function classify(Cup $cup): RedirectResponse
    {
        $backfilledYears = $this->topGroupClassificationService->predecessorsWithoutClassification($cup)->pluck('year');
        $this->topGroupClassificationService->calculateForCup($cup);

        $message = "Top-Gruppen-Klassifizierung für \"$cup->name\" berechnet.";
        if ($backfilledYears->isNotEmpty()) {
            $message .= ' Vorab nachgerechnet: '.$backfilledYears->implode(', ').'.';
        }

        return redirect()
            ->route('cups.top-group.index', $cup)
            ->with('success', $message);
    }

    /** GET /cups/{cup}/top-group */
    public function index(Cup $cup): View
    {
        $classifications = CupTopGroupClassification::where('cup_id', $cup->id)
            ->with(['athlete.club', 'manualSetBy'])
            ->get()
            ->sortBy(fn (CupTopGroupClassification $row) => sprintf('%d|%s|%s',
                $row->is_top_group ? 0 : 1, $row->athlete->last_name, $row->athlete->first_name))
            ->values();

        $previousCup = Cup::where('year', '<', $cup->year)->orderByDesc('year')->first();

        return view('cups.top-group.index', [
            'cup' => $cup,
            'classifications' => $classifications,
            'status' => $this->stalenessService->topGroupClassificationStatus($cup),
            'previousCup' => $previousCup,
            'previousCupMeetsWithoutDaily' => $previousCup ? $this->meetsWithoutDailyRanking($previousCup) : 0,
        ]);
    }

    /** GET /cups/{cup}/top-group/create — Hand-Einstufung für einen noch nicht eingestuften Athleten. */
    public function create(Cup $cup): View
    {
        $classifiedIds = CupTopGroupClassification::where('cup_id', $cup->id)->pluck('athlete_id');

        return view('cups.top-group.form', [
            'cup' => $cup,
            'athlete' => null,
            'classification' => null,
            'athletes' => Athlete::with('club')
                ->whereNotIn('id', $classifiedIds)
                ->orderBy('last_name')->orderBy('first_name')
                ->get(),
        ]);
    }

    /** POST /cups/{cup}/top-group */
    public function store(Request $request, Cup $cup): RedirectResponse
    {
        $validated = $request->validate([
            'athlete_id' => 'required|exists:athletes,id',
            ...$this->manualRules(),
        ]);

        $athlete = Athlete::findOrFail($validated['athlete_id']);

        if (CupTopGroupClassification::where('cup_id', $cup->id)->where('athlete_id', $athlete->id)->exists()) {
            return redirect()->route('cups.top-group.edit', [$cup, $athlete]);
        }

        // Ohne Zeile ist der Athlet nach der Regel nicht in der Top-Gruppe; einen Grund gibt es erst nach der Berechnung.
        CupTopGroupClassification::create([
            'cup_id' => $cup->id,
            'athlete_id' => $athlete->id,
            'calculated_is_top_group' => false,
            ...$this->manualAttributes($request, $validated),
        ]);

        return redirect()
            ->route('cups.top-group.index', $cup)
            ->with('success', "Einstufung für $athlete->display_name von Hand gesetzt.");
    }

    /** GET /cups/{cup}/top-group/{athlete}/edit */
    public function edit(Cup $cup, Athlete $athlete): View
    {
        return view('cups.top-group.form', [
            'cup' => $cup,
            'athlete' => $athlete,
            'classification' => $this->classificationOrFail($cup, $athlete),
            'athletes' => collect(),
        ]);
    }

    /** PUT /cups/{cup}/top-group/{athlete} */
    public function update(Request $request, Cup $cup, Athlete $athlete): RedirectResponse
    {
        $validated = $request->validate($this->manualRules());

        $this->classificationOrFail($cup, $athlete)->update($this->manualAttributes($request, $validated));

        return redirect()
            ->route('cups.top-group.index', $cup)
            ->with('success', "Einstufung für $athlete->display_name von Hand gesetzt.");
    }

    /**
     * DELETE /cups/{cup}/top-group/{athlete}
     *
     * Nimmt die Hand-Einstufung zurück; es gilt wieder die berechnete Einstufung. Eine Zeile, die nur durch die
     * Hand-Einstufung entstand (noch nie berechnet, reason = null), wird gelöscht.
     */
    public function destroy(Cup $cup, Athlete $athlete): RedirectResponse
    {
        $classification = $this->classificationOrFail($cup, $athlete);

        if ($classification->reason === null) {
            $classification->delete();
        } else {
            $classification->update([
                'is_top_group' => $classification->calculated_is_top_group,
                'manual_is_top_group' => null,
                'manual_note' => null,
                'manual_set_by' => null,
                'manual_set_at' => null,
                'calculated_at' => now(),
            ]);
        }

        return redirect()
            ->route('cups.top-group.index', $cup)
            ->with('success', "Hand-Einstufung für $athlete->display_name zurückgenommen.");
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    private function classificationOrFail(Cup $cup, Athlete $athlete): CupTopGroupClassification
    {
        return CupTopGroupClassification::where('cup_id', $cup->id)->where('athlete_id', $athlete->id)->firstOrFail();
    }

    /** @return array<string, string> */
    private function manualRules(): array
    {
        return [
            'is_top_group' => 'required|boolean',
            'manual_note' => 'required|string|max:1000',
        ];
    }

    /**
     * calculated_at wird mitgesetzt, damit Tageswertung, Gesamtwertung und die Einstufung des Folgejahres die
     * Änderung als "veraltet" erkennen (CupStalenessService prüft nur Zeitstempel).
     *
     * @return array<string, mixed>
     */
    private function manualAttributes(Request $request, array $validated): array
    {
        $isTopGroup = $request->boolean('is_top_group');

        return [
            'is_top_group' => $isTopGroup,
            'manual_is_top_group' => $isTopGroup,
            'manual_note' => $validated['manual_note'],
            'manual_set_by' => $request->user()->id,
            'manual_set_at' => now(),
            'calculated_at' => now(),
        ];
    }

    /** Anzahl der Meets eines Cups mit Ergebnissen, aber ohne Tageswertung — deren Starts zählen für die Regel nicht. */
    private function meetsWithoutDailyRanking(Cup $cup): int
    {
        return Meet::where('cup_id', $cup->id)
            ->whereIn('id', Result::select('meet_id'))
            ->whereNotIn('id', CupDailyResult::select('meet_id'))
            ->count();
    }
}
