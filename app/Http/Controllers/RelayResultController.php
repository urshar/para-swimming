<?php

namespace App\Http\Controllers;

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\RelayEntry;
use App\Models\RelayResult;
use App\Models\RelayResultMember;
use App\Models\SwimEvent;
use App\Services\RelayClassValidator;
use App\Services\ScoringGroupService;
use App\Support\ListUrl;
use App\Support\TimeParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * RelayResultController
 *
 * Manuelle Erfassung von Staffelergebnissen (Anlegen, Bearbeiten, Löschen). Die Liste ist die Ergebnis-Sammelansicht
 * der Veranstaltung (MeetResultsOverviewController), dorthin führen Rücksprünge. Nur Admin (Route-Middleware).
 *
 * - Geschlecht der Mannschaft: leer = aus den Mitgliedern (RelayResult::genderFromMembers()).
 * - Staffelklasse: leer = aus den Sportklassen der Mitglieder (RelayClassValidator).
 * - Mitglieder werden im Formular aus der Staffelmeldung desselben Vereins und Bewerbs vorbelegt.
 */
class RelayResultController extends Controller
{
    public function __construct(
        private readonly RelayClassValidator $relayValidator,
        private readonly ScoringGroupService $scoring,
    ) {}

    public function create(Request $request, Meet $meet): View
    {
        $presetEventId = (string) $request->integer('swim_event_id');

        return $this->form($meet, null, $presetEventId);
    }

    /**
     * @throws Throwable
     */
    public function store(Request $request, Meet $meet): RedirectResponse
    {
        $data = $this->validated($request, $meet);

        DB::transaction(function () use ($meet, $data) {
            $relayResult = RelayResult::create($data['result'] + ['meet_id' => $meet->id]);
            $this->storeMembers($relayResult, $data['members']);
        });
        $this->scoring->syncPlaces(SwimEvent::findOrFail($data['result']['swim_event_id']));

        if ($request->boolean('save_next')) {
            return redirect()
                ->route('meets.relay-results.create', ['meet' => $meet, 'swim_event_id' => $data['result']['swim_event_id']])
                ->with('success', 'Staffelergebnis gespeichert.');
        }

        return redirect()
            ->to(MeetResultsOverviewController::backUrl($meet))
            ->with('success', 'Staffelergebnis gespeichert.');
    }

    public function edit(RelayResult $relayResult): View
    {
        return $this->form($relayResult->meet, $relayResult, (string) $relayResult->swim_event_id);
    }

    /**
     * @throws Throwable
     */
    public function update(Request $request, RelayResult $relayResult): RedirectResponse
    {
        $data = $this->validated($request, $relayResult->meet);

        $previousEvent = $relayResult->swimEvent;

        DB::transaction(function () use ($relayResult, $data) {
            $relayResult->update($data['result']);
            $relayResult->members()->delete();
            $this->storeMembers($relayResult, $data['members']);
        });

        $event = SwimEvent::findOrFail($data['result']['swim_event_id']);
        $this->scoring->syncPlaces($event);
        if ($previousEvent && $previousEvent->id !== $event->id) {
            $this->scoring->syncPlaces($previousEvent);
        }

        return redirect()
            ->to(ListUrl::to('results'))
            ->with('success', 'Staffelergebnis aktualisiert.');
    }

    public function destroy(RelayResult $relayResult): RedirectResponse
    {
        $event = $relayResult->swimEvent;
        $relayResult->delete(); // cascadeOnDelete löscht Mitglieder und Zwischenzeiten
        $this->scoring->syncPlaces($event);

        return redirect()
            ->to(ListUrl::to('results'))
            ->with('success', 'Staffelergebnis gelöscht.');
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    private function form(Meet $meet, ?RelayResult $relayResult, string $presetEventId): View
    {
        $relayEvents = $meet->swimEvents()
            ->with('strokeType')
            ->where('relay_count', '>', 1)
            ->orderBy('session_number')
            ->orderBy('event_number')
            ->get();

        // Bewusst alle Athleten (auch vereinsfremde Mitglieder, z. B. bei AK-Staffeln).
        $athletes = Athlete::with(['club', 'sportClasses'])->orderBy('last_name')->orderBy('first_name')->get();
        $clubs = Club::orderBy('name')->get();

        // Vorbelegung der Mitglieder aus der Staffelmeldung: "Bewerb-Verein" → Athleten-IDs je Position.
        $entryMembers = RelayEntry::query()
            ->where('meet_id', $meet->id)
            ->with('members')
            ->get()
            ->mapWithKeys(fn (RelayEntry $entry): array => [
                $entry->swim_event_id.'-'.$entry->club_id => $entry->members
                    ->sortBy('position')
                    ->pluck('athlete_id')
                    ->map(fn (int $id): string => (string) $id)
                    ->values(),
            ]);

        $relayResult?->load('members');
        $maxPositions = max(4, (int) $relayEvents->max('relay_count'));

        return view('relay-results.form', [
            'meet' => $meet,
            'relayResult' => $relayResult,
            'relayEvents' => $relayEvents,
            'athletes' => $athletes,
            'clubs' => $clubs,
            'entryMembers' => $entryMembers,
            'maxPositions' => $maxPositions,
            'presetEventId' => $presetEventId,
            'cancelUrl' => $relayResult ? ListUrl::to('results') : MeetResultsOverviewController::backUrl($meet),
            'placements' => $relayResult ? $this->scoring->placementsOf($relayResult) : [],
        ]);
    }

    /**
     * @return array{result: array<string, mixed>, members: list<array{position: int, athlete: Athlete}>}
     *
     * @throws ValidationException
     */
    private function validated(Request $request, Meet $meet): array
    {
        $validated = $request->validate([
            'swim_event_id' => 'required|integer|exists:swim_events,id',
            'club_id' => 'required|integer|exists:clubs,id',
            'relay_number' => 'nullable|integer|min:1|max:20',
            'name' => 'nullable|string|max:100',
            'gender' => 'nullable|in:M,F,X',
            'relay_class' => 'nullable|string|max:10',
            'swim_time' => 'nullable|string|max:20',
            'status' => 'nullable|in:EXH,DSQ,DNS,DNF,SICK,WDR',
            'points' => 'nullable|integer|min:0',
            'comment' => 'nullable|string|max:255',
            'members' => 'nullable|array',
            'members.*' => 'nullable|integer|exists:athletes,id',
        ]);

        $event = SwimEvent::findOrFail($validated['swim_event_id']);
        if ($event->meet_id !== $meet->id || $event->relay_count <= 1) {
            throw ValidationException::withMessages(['swim_event_id' => 'Bitte einen Staffelbewerb dieser Veranstaltung wählen.']);
        }

        $athletes = Athlete::with('sportClasses')->findMany(array_filter($validated['members'] ?? []))->keyBy('id');
        $members = [];
        foreach (array_slice($validated['members'] ?? [], 0, $event->relay_count, true) as $index => $athleteId) {
            if ($athleteId && $athletes->has($athleteId)) {
                $members[] = ['position' => (int) $index + 1, 'athlete' => $athletes[$athleteId]];
            }
        }
        if (count(array_unique(array_map(fn (array $m): int => $m['athlete']->id, $members))) !== count($members)) {
            throw ValidationException::withMessages(['members' => 'Ein Athlet kann nur einmal in der Staffel stehen.']);
        }

        $swimTime = trim((string) ($validated['swim_time'] ?? ''));

        // Staffelklasse leer = aus den Sportklassen der Mitglieder (S-Klasse je Athlet).
        $relayClass = $validated['relay_class'] ?? null;
        if (empty($relayClass)) {
            $classes = array_map(
                fn (array $m): ?string => $m['athlete']->sportClasses->firstWhere('category', 'S')?->sport_class,
                $members,
            );
            $relayClass = count($members) === $event->relay_count
                ? $this->relayValidator->resolveRelayClass(array_filter($classes))
                : null;
        }

        return [
            'result' => [
                'swim_event_id' => $event->id,
                'club_id' => (int) $validated['club_id'],
                'relay_number' => $validated['relay_number'] ?? null,
                'name' => $validated['name'] ?? null,
                'gender' => $validated['gender']
                    ?? RelayResult::genderFromMembers(array_map(fn (array $m): ?string => $m['athlete']->gender, $members)),
                'relay_class' => $relayClass,
                'swim_time' => $swimTime === '' ? null : TimeParser::parse($swimTime),
                'status' => $validated['status'] ?? null,
                'points' => $validated['points'] ?? null,
                'comment' => $validated['comment'] ?? null,
            ],
            'members' => $members,
        ];
    }

    /** @param  list<array{position: int, athlete: Athlete}>  $members */
    private function storeMembers(RelayResult $relayResult, array $members): void
    {
        foreach ($members as $member) {
            $athlete = $member['athlete'];
            RelayResultMember::create([
                'relay_result_id' => $relayResult->id,
                'position' => $member['position'],
                'athlete_id' => $athlete->id,
                'first_name' => $athlete->first_name,
                'last_name' => $athlete->last_name,
                'gender' => $athlete->gender,
                'sport_class' => $athlete->sportClasses->firstWhere('category', 'S')?->sport_class,
            ]);
        }
    }
}
