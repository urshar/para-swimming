<?php

namespace App\Http\Controllers;

use App\Concerns\SearchesAthletes;
use App\Models\Athlete;
use App\Models\BaseTimeVersion;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Result;
use App\Models\ResultSplit;
use App\Models\SwimEvent;
use App\Services\WorldAquaticsPointsService;
use App\Support\TimeParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

class ResultController extends Controller
{
    use SearchesAthletes;

    public function __construct(
        private readonly WorldAquaticsPointsService $pointsService,
    ) {}

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

        // Für die "Punkte neu berechnen"-Aktion: nur relevant, wenn nach einem Meet gefiltert wird.
        $selectedMeet = $meetId ? $meets->firstWhere('id', (int) $meetId) : null;
        $baseTimeVersions = $selectedMeet ? BaseTimeVersion::orderByDesc('valid_from')->get() : collect();
        $automaticVersion = $selectedMeet ? $this->pointsService->resolveAutomaticVersion($selectedMeet) : null;

        // Punkte, die von der aktuellen Basiswert-Tabelle abweichen (z.B. weil sich ein Basiswert
        // nachträglich geändert hat, seit die Punkte zuletzt berechnet wurden).
        $outdatedResultsCount = $selectedMeet
            ? $this->pointsService->findOutdatedResults($selectedMeet, $automaticVersion)->count()
            : 0;

        $skippedResultIds = session('points_skipped_results', []);
        $skippedResults = collect();
        if (! empty($skippedResultIds)) {
            $skippedResults = Result::with(['athlete', 'swimEvent'])
                ->whereIn('id', array_keys($skippedResultIds))
                ->get()
                ->map(fn (Result $r) => ['result' => $r, 'reason' => $skippedResultIds[$r->id]]);
        }

        return view('results.index', compact(
            'results', 'meets', 'selectedMeet', 'baseTimeVersions', 'automaticVersion', 'skippedResults',
            'outdatedResultsCount'
        ));
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

        // Bewusst ALLE Athleten/Vereine, nicht nur jene mit einer Meldung zu diesem Meet —
        // ein Ergebnis kann auch für einen Athleten ohne (oder mit fehlender) Meldung erfasst
        // werden müssen. Gleiches Muster wie EntryController::create().
        $athletes = Athlete::with(['club', 'nation', 'sportClasses'])
            ->orderBy('last_name')
            ->get();
        $clubs = Club::with('nation')->orderBy('name')->get();

        return view('results.form', compact('meet', 'swimEvents', 'athletes', 'clubs'));
    }

    /**
     * @throws Throwable
     */
    public function store(Request $request, Meet $meet): RedirectResponse
    {
        $data = $this->validateResult($request);

        // Prüfen ob SwimEvent zum Meet gehört
        $swimEvent = SwimEvent::findOrFail($data['result']['swim_event_id']);
        if ($swimEvent->meet_id !== $meet->id) {
            return back()->withErrors(['swim_event_id' => 'Diese Disziplin gehört nicht zu diesem Wettkampf.']);
        }

        DB::transaction(function () use ($meet, $data) {
            $result = Result::create(array_merge(
                $data['result'],
                ['meet_id' => $meet->id]
            ));

            $this->storeSplits($result, $data['splits']);
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
            'athletes' => collect(),
            'clubs' => collect(),
        ]);
    }

    /**
     * @throws Throwable
     */
    public function update(Request $request, Result $result): RedirectResponse
    {
        $data = $this->validateResult($request);

        DB::transaction(function () use ($result, $data) {
            $result->update($data['result']);

            // Splits komplett ersetzen
            $result->splits()->delete();
            $this->storeSplits($result, $data['splits']);
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
            // Kommt aus einem maskierten MM:SS.hh-Feld, nicht mehr als Hundertstel-Integer
            // (gleiches Muster wie EntryController::parseEntryTime() für Meldezeiten) —
            // deshalb hier als String validiert und unten über parseTime() umgerechnet.
            'swim_time' => 'nullable|string|max:20',
            'status' => 'nullable|in:EXH,DSQ,DNS,DNF,SICK,WDR',
            'sport_class' => 'nullable|string|max:15',
            'points' => 'nullable|integer|min:0',
            'heat' => 'nullable|integer|min:1',
            'lane' => 'nullable|integer|min:0',
            'place' => 'nullable|integer|min:1',
            // Kommt als Sekunden mit Komma aus dem Formular (z.B. "0,14", Fehlstart negativ
            // möglich, z.B. "-0,03") — unten über parseReactionTime() in Hundertstelsekunden
            // umgerechnet, wie es die Spalte speichert.
            'reaction_time' => 'nullable|string|max:10',
            'comment' => 'nullable|string|max:255',
            'is_world_record' => 'boolean',
            'is_european_record' => 'boolean',
            'is_national_record' => 'boolean',

            'splits' => 'nullable|array',
            'splits.*.distance' => 'nullable|integer|min:1',
            'splits.*.split_time' => 'nullable|string|max:20',
        ]);

        // Checkbox-/Switch-Felder fehlen im Request komplett, wenn sie nicht angehakt
        // sind — dann fehlen sie auch in $validated, und ein zuvor gesetztes Flag würde
        // beim Abhaken NICHT zurückgesetzt. Explizit über boolean() normalisieren
        // (gleiches Muster wie MeetController::store()/update()).
        $validated['is_world_record'] = $request->boolean('is_world_record');
        $validated['is_european_record'] = $request->boolean('is_european_record');
        $validated['is_national_record'] = $request->boolean('is_national_record');
        $validated['swim_time'] = $this->parseTime($validated['swim_time'] ?? null);
        $validated['reaction_time'] = $this->parseReactionTime($validated['reaction_time'] ?? null);

        return [
            'result' => collect($validated)->except('splits')->toArray(),
            'splits' => collect($validated['splits'] ?? [])
                ->map(fn ($s) => [
                    'distance' => $s['distance'] ?? null,
                    'split_time' => $this->parseTime($s['split_time'] ?? null),
                ])
                ->filter(fn ($s) => ! empty($s['distance']) && ! empty($s['split_time']))
                ->values()
                ->toArray(),
        ];
    }

    /** MM:SS.hh (bzw. HH:MM:SS.hh) aus dem maskierten Zeit-Feld → Hundertstelsekunden. */
    private function parseTime(?string $raw): ?int
    {
        $raw = trim((string) $raw);

        return $raw === '' ? null : TimeParser::parse($raw);
    }

    /** Sekunden mit Komma (z.B. "0,14", "-0,03") → Hundertstelsekunden. */
    private function parseReactionTime(?string $raw): ?int
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        $normalized = str_replace(',', '.', $raw);

        return is_numeric($normalized) ? (int) round(((float) $normalized) * 100) : null;
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
