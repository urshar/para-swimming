<?php

namespace App\Http\Controllers;

use App\Concerns\SearchesAthletes;
use App\Models\Meet;
use App\Models\Result;
use App\Models\ResultSplit;
use App\Models\SwimEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

class ResultController extends Controller
{
    use SearchesAthletes;

    public function index(Request $request): View
    {
        $query = Result::with(['athlete', 'club', 'swimEvent.strokeType', 'meet'])
            ->latest();

        if ($meetId = $request->query('meet_id')) {
            $query->where('meet_id', $meetId);
        }

        if ($search = $request->query('search')) {
            $this->applyAthleteSearch($query, $search);
        }

        if ($status = $request->query('status')) {
            $status === 'valid'
                ? $query->whereNull('status')
                : $query->where('status', $status);
        }

        $results = $query->paginate(25)->withQueryString();
        $meets = Meet::orderByDesc('start_date')->get();

        return view('results.index', compact('results', 'meets'));
    }

    public function show(Result $result): View
    {
        $result->load([
            'athlete.sportClasses',
            'club.nation',
            'swimEvent.strokeType',
            'meet',
            'splits',
        ]);

        return view('results.show', compact('result'));
    }

    public function create(Meet $meet): View
    {
        $swimEvents = $meet->swimEvents()
            ->with('strokeType')
            ->orderBy('session_number')
            ->orderBy('event_number')
            ->get();

        $entries = $meet->entries()
            ->with(['athlete.sportClasses', 'club'])
            ->get();

        return view('results.form', compact('meet', 'swimEvents', 'entries'));
    }

    /**
     * @throws Throwable
     */
    public function store(Request $request, Meet $meet): RedirectResponse
    {
        $data = $this->validateResult($request);

        // Prüfen ob SwimEvent zum Meet gehört
        $swimEvent = SwimEvent::findOrFail($data['swim_event_id']);
        if ($swimEvent->meet_id !== $meet->id) {
            return back()->withErrors(['swim_event_id' => 'Diese Disziplin gehört nicht zu diesem Wettkampf.']);
        }

        DB::transaction(function () use ($meet, $data, $request) {
            $result = Result::create(array_merge(
                $data['result'],
                ['meet_id' => $meet->id]
            ));

            $this->storeSplits($result, $request->input('splits', []));
        });

        return redirect()
            ->route('meets.show', $meet)
            ->with('success', 'Ergebnis gespeichert.');
    }

    public function edit(Result $result): View
    {
        $result->load(['splits', 'meet', 'athlete', 'club', 'swimEvent.strokeType']);

        $swimEvents = $result->meet->swimEvents()
            ->with('strokeType')
            ->orderBy('event_number')
            ->get();

        return view('results.form', [
            'meet' => $result->meet,
            'result' => $result,
            'swimEvents' => $swimEvents,
            'entries' => collect(),
        ]);
    }

    /**
     * @throws Throwable
     */
    public function update(Request $request, Result $result): RedirectResponse
    {
        $data = $this->validateResult($request);

        DB::transaction(function () use ($result, $data, $request) {
            $result->update($data['result']);

            // Splits komplett ersetzen
            $result->splits()->delete();
            $this->storeSplits($result, $request->input('splits', []));
        });

        return redirect()
            ->route('meets.show', $result->meet)
            ->with('success', 'Ergebnis aktualisiert.');
    }

    public function destroy(Result $result): RedirectResponse
    {
        $meet = $result->meet;
        $result->delete(); // cascadeOnDelete löscht auch splits

        return redirect()
            ->route('meets.show', $meet)
            ->with('success', 'Ergebnis gelöscht.');
    }

    // ── Ergebnisse eines Athleten (für API) ───────────────────────────────────

    public function byAthlete(int $athleteId): JsonResponse
    {
        $results = Result::where('athlete_id', $athleteId)
            ->with(['meet', 'swimEvent.strokeType', 'splits'])
            ->whereNull('status')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($results);
    }

    // ── Private Hilfsmethoden ─────────────────────────────────────────────────

    private function validateResult(Request $request): array
    {
        $validated = $request->validate([
            'swim_event_id' => 'required|exists:swim_events,id',
            'athlete_id' => 'required|exists:athletes,id',
            'club_id' => 'required|exists:clubs,id',
            'swim_time' => 'nullable|integer|min:0',
            'status' => 'nullable|in:EXH,DSQ,DNS,DNF,SICK,WDR',
            'sport_class' => 'nullable|string|max:15',
            'points' => 'nullable|integer|min:0',
            'heat' => 'nullable|integer|min:1',
            'lane' => 'nullable|integer|min:0',
            'place' => 'nullable|integer|min:1',
            'reaction_time' => 'nullable|integer',  // kann negativ sein (Fehlstart)
            'comment' => 'nullable|string|max:255',
            'is_world_record' => 'boolean',
            'is_european_record' => 'boolean',
            'is_national_record' => 'boolean',

            // Splits
            'splits' => 'nullable|array',
            'splits.*.distance' => 'required_with:splits.*|integer|min:1',
            'splits.*.split_time' => 'required_with:splits.*|integer|min:0',
        ]);

        return [
            'result' => collect($validated)->except('splits')->toArray(),
            'splits' => $validated['splits'] ?? [],
        ];
    }

    private function storeSplits(Result $result, array $splits): void
    {
        foreach ($splits as $split) {
            if (empty($split['distance']) || empty($split['split_time'])) {
                continue;
            }
            ResultSplit::create([
                'result_id' => $result->id,
                'distance' => $split['distance'],
                'split_time' => $split['split_time'],
            ]);
        }
    }
}
