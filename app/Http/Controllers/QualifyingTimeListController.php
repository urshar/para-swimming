<?php

namespace App\Http\Controllers;

use App\Models\QualifyingTargetPoint;
use App\Models\QualifyingTime;
use App\Models\QualifyingTimeList;
use App\Models\StrokeType;
use App\Services\QualifyingTimeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * QualifyingTimeListController
 *
 * CRUD für die Richtzeitenlisten "ÖSTM & ÖM" (Phase 1 der Spec). Anzeige ist
 * für alle authentifizierten User offen (Leserechte laut Spec-Abschnitt
 * "Berechtigungen"); Anlegen/Bearbeiten/Löschen sowie das Pflegen von
 * Zielpunkten und Richtzeiten-Zeilen ist admin-only.
 */
class QualifyingTimeListController extends Controller
{
    public function __construct(
        private readonly QualifyingTimeService $qualifyingTimeService,
    ) {}

    // ── Richtzeitenliste ──────────────────────────────────────────────────────

    public function index(): View
    {
        $lists = QualifyingTimeList::withCount(['targetPoints', 'times'])
            ->orderByDesc('year')
            ->get();

        return view('qualifying-time-lists.index', compact('lists'));
    }

    public function create(): View
    {
        $this->authorizeAdmin();

        return view('qualifying-time-lists.form', ['list' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAdmin();

        $validated = $this->validateList($request);

        $list = $this->qualifyingTimeService->createList($validated);

        return redirect()
            ->route('qualifying-time-lists.edit', $list)
            ->with('success', "Richtzeitenliste $list->year angelegt. Jetzt Zielpunkte und Richtzeiten pflegen.");
    }

    /** Read-only Ansicht — für alle authentifizierten User. */
    public function show(QualifyingTimeList $qualifyingTimeList): View
    {
        $qualifyingTimeList->load(['targetPoints', 'times.strokeType']);

        return view('qualifying-time-lists.show', ['list' => $qualifyingTimeList]);
    }

    public function edit(QualifyingTimeList $qualifyingTimeList): View
    {
        $this->authorizeAdmin();

        $qualifyingTimeList->load(['targetPoints', 'times.strokeType']);

        $strokeTypes = StrokeType::active()->orderBy('name_de')->get();

        return view('qualifying-time-lists.form', [
            'list' => $qualifyingTimeList,
            'strokeTypes' => $strokeTypes,
        ]);
    }

    public function update(Request $request, QualifyingTimeList $qualifyingTimeList): RedirectResponse
    {
        $this->authorizeAdmin();

        $validated = $this->validateList($request, $qualifyingTimeList->id);

        $this->qualifyingTimeService->updateList($qualifyingTimeList, $validated);

        return redirect()
            ->route('qualifying-time-lists.edit', $qualifyingTimeList)
            ->with('success', "Richtzeitenliste $qualifyingTimeList->year aktualisiert.");
    }

    public function destroy(QualifyingTimeList $qualifyingTimeList): RedirectResponse
    {
        $this->authorizeAdmin();

        $year = $qualifyingTimeList->year;
        $this->qualifyingTimeService->deleteList($qualifyingTimeList);

        return redirect()
            ->route('qualifying-time-lists.index')
            ->with('success', "Richtzeitenliste $year gelöscht.");
    }

    // ── Zielpunkte ────────────────────────────────────────────────────────────

    public function storeTargetPoint(Request $request, QualifyingTimeList $qualifyingTimeList): RedirectResponse
    {
        $this->authorizeAdmin();

        $validated = $request->validate([
            'sport_class' => 'required|string|max:15',
            'points' => 'required|integer|min:0|max:2000',
        ]);

        $this->qualifyingTimeService->upsertTargetPoint(
            $qualifyingTimeList,
            $validated['sport_class'],
            $validated['points']
        );

        return redirect()
            ->route('qualifying-time-lists.edit', $qualifyingTimeList)
            ->with('success', "Zielpunkte für \"{$validated['sport_class']}\" gespeichert.");
    }

    public function destroyTargetPoint(
        QualifyingTimeList $qualifyingTimeList,
        QualifyingTargetPoint $targetPoint
    ): RedirectResponse {
        $this->authorizeAdmin();

        abort_unless($targetPoint->qualifying_time_list_id === $qualifyingTimeList->id, 404);

        $sportClass = $targetPoint->sport_class;
        $this->qualifyingTimeService->deleteTargetPoint($targetPoint);

        return redirect()
            ->route('qualifying-time-lists.edit', $qualifyingTimeList)
            ->with('success', "Zielpunkte-Override für \"$sportClass\" entfernt (Standard 100 gilt wieder).");
    }

    // ── Richtzeiten-Zeilen ────────────────────────────────────────────────────

    public function storeTime(Request $request, QualifyingTimeList $qualifyingTimeList): RedirectResponse
    {
        $this->authorizeAdmin();

        $validated = $request->validate([
            'stroke_type_id' => 'required|integer|exists:stroke_types,id',
            'distance' => 'required|integer|min:1|max:20000',
            'gender' => 'required|in:M,F',
            'sport_class' => 'required|string|max:15',
            'value' => 'nullable|string|max:20',
        ]);

        $this->qualifyingTimeService->upsertTime(
            $qualifyingTimeList,
            $validated['stroke_type_id'],
            $validated['distance'],
            $validated['gender'],
            $validated['sport_class'],
            $validated['value'] ?? null,
        );

        return redirect()
            ->route('qualifying-time-lists.edit', $qualifyingTimeList)
            ->with('success', 'Richtzeit gespeichert.');
    }

    public function destroyTime(QualifyingTimeList $qualifyingTimeList, QualifyingTime $time): RedirectResponse
    {
        $this->authorizeAdmin();

        abort_unless($time->qualifying_time_list_id === $qualifyingTimeList->id, 404);

        $this->qualifyingTimeService->deleteTime($time);

        return redirect()
            ->route('qualifying-time-lists.edit', $qualifyingTimeList)
            ->with('success', 'Richtzeit gelöscht.');
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->is_admin, 403, 'Nur für Administratoren.');
    }

    private function validateList(Request $request, ?int $excludeId = null): array
    {
        return $request->validate([
            'year' => 'required|integer|min:2000|max:2100|unique:qualifying_time_lists,year,'.($excludeId ?? 'NULL').',id',
            'is_active' => 'boolean',
        ]);
    }
}
