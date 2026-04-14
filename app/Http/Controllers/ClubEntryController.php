<?php

namespace App\Http\Controllers;

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Entry;
use App\Models\Meet;
use App\Models\SwimEvent;
use App\Services\ClubEntryService;
use App\Support\TimeParser;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ClubEntryController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ClubEntryService $entryService,
    ) {}

    // ── Index ─────────────────────────────────────────────────────────────────

    /**
     * Übersicht aller Einzelmeldungen des eigenen Clubs für einen Wettkampf.
     */
    public function index(Meet $meet): View
    {
        $this->authorizeMeet($meet);

        $club = $this->userClub();

        $entries = Entry::query()
            ->with(['athlete', 'swimEvent.strokeType'])
            ->where('meet_id', $meet->id)
            ->where('club_id', $club->id)
            ->whereHas('swimEvent', fn ($q) => $q->where('relay_count', 1))
            ->orderBy('swim_event_id')
            ->orderBy('athlete_id')
            ->get();

        $canManage = $this->canManage($meet);

        return view('club-entries.index', compact('meet', 'club', 'entries', 'canManage'));
    }

    // ── Create / Store ────────────────────────────────────────────────────────

    /**
     * Formular: neue Einzelmeldung anlegen.
     */
    public function create(Meet $meet): View
    {
        $this->authorize('manageEntries', $meet);

        $club = $this->userClub();

        $events = SwimEvent::query()
            ->with('strokeType')
            ->where('meet_id', $meet->id)
            ->where('relay_count', 1)
            ->orderBy('event_number')
            ->get();

        $athletes = $club->athletes()->with('sportClasses')->orderBy('last_name')->get();

        return view('club-entries.create', compact('meet', 'club', 'events', 'athletes'));
    }

    /**
     * Einzelmeldung speichern.
     */
    public function store(Request $request, Meet $meet): RedirectResponse
    {
        $this->authorize('manageEntries', $meet);

        $club = $this->userClub();

        $validated = $request->validate([
            'swim_event_id' => ['required', 'integer', 'exists:swim_events,id'],
            'athlete_id' => ['required', 'integer', 'exists:athletes,id'],
            'entry_time' => ['nullable', 'string', 'max:20'],
            'entry_course' => ['nullable', 'in:LCM,SCM,SCY'],
        ]);

        // SwimEvent muss zum Meet gehören und Einzel-Event sein
        $event = SwimEvent::where('id', $validated['swim_event_id'])
            ->where('meet_id', $meet->id)
            ->where('relay_count', 1)
            ->firstOrFail();

        // Athlet muss zum Club gehören
        $club->athletes()->findOrFail($validated['athlete_id']);

        // Zeitstring parsen
        $entryTime = null;
        $entryTimeCode = null;

        if (! empty($validated['entry_time'])) {
            $upper = strtoupper(trim($validated['entry_time']));
            if (in_array($upper, ['NT', 'NS', 'WO'], true)) {
                $entryTimeCode = $upper;
            } else {
                $entryTime = TimeParser::parse($validated['entry_time']);
            }
        }

        Entry::updateOrCreate(
            [
                'meet_id' => $meet->id,
                'swim_event_id' => $event->id,
                'athlete_id' => $validated['athlete_id'],
            ],
            [
                'club_id' => $club->id,
                'entry_time' => $entryTime,
                'entry_time_code' => $entryTimeCode,
                'entry_course' => $validated['entry_course'] ?? $meet->course,
                'sport_class' => $this->resolveSportClass($validated['athlete_id'], $event),
            ]
        );

        return redirect()
            ->route('club-entries.index', $meet)
            ->with('success', 'Meldung gespeichert.');
    }

    // ── Edit / Update ─────────────────────────────────────────────────────────

    /**
     * Formular: Meldung bearbeiten.
     */
    public function edit(Meet $meet, Entry $entry): View
    {
        $this->authorize('manageEntries', $meet);
        $this->authorizeEntry($entry, $meet);

        $club = $this->userClub();

        $events = SwimEvent::query()
            ->with('strokeType')
            ->where('meet_id', $meet->id)
            ->where('relay_count', 1)
            ->orderBy('event_number')
            ->get();

        $athletes = $club->athletes()->with('sportClasses')->orderBy('last_name')->get();

        // Bestzeiten für das aktuelle Event laden
        $bestTimes = $this->entryService->bestTimes(
            $entry->athlete,
            $entry->swimEvent,
            $meet
        );

        return view('club-entries.edit', compact('meet', 'club', 'entry', 'events', 'athletes', 'bestTimes'));
    }

    /**
     * Meldung aktualisieren.
     */
    public function update(Request $request, Meet $meet, Entry $entry): RedirectResponse
    {
        $this->authorize('manageEntries', $meet);
        $this->authorizeEntry($entry, $meet);

        $validated = $request->validate([
            'entry_time' => ['nullable', 'string', 'max:20'],
            'entry_course' => ['nullable', 'in:LCM,SCM,SCY'],
        ]);

        $entryTime = null;
        $entryTimeCode = null;

        if (! empty($validated['entry_time'])) {
            $upper = strtoupper(trim($validated['entry_time']));
            if (in_array($upper, ['NT', 'NS', 'WO'], true)) {
                $entryTimeCode = $upper;
            } else {
                $entryTime = TimeParser::parse($validated['entry_time']);
                if ($entryTime === null) {
                    return back()
                        ->withInput()
                        ->withErrors(['entry_time' => 'Ungültiges Zeitformat. Beispiel: 01:23.45']);
                }
            }
        }

        $entry->update([
            'entry_time' => $entryTime,
            'entry_time_code' => $entryTimeCode,
            'entry_course' => $validated['entry_course'] ?? $entry->entry_course,
        ]);

        return redirect()
            ->route('club-entries.index', $meet)
            ->with('success', 'Meldung aktualisiert.');
    }

    // ── Destroy ───────────────────────────────────────────────────────────────

    /**
     * Meldung löschen.
     */
    public function destroy(Meet $meet, Entry $entry): RedirectResponse
    {
        $this->authorize('deleteEntry', $meet);
        $this->authorizeEntry($entry, $meet);

        $entry->delete();

        return redirect()
            ->route('club-entries.index', $meet)
            ->with('success', 'Meldung gelöscht.');
    }

    // ── AJAX Endpunkte ────────────────────────────────────────────────────────

    /**
     * AJAX: Athleten für ein bestimmtes Event (Geschlecht + Sportklasse).
     *
     * GET /meets/{meet}/club-entries/eligible-athletes?event_id=X
     */
    public function eligibleAthletes(Request $request, Meet $meet): JsonResponse
    {
        $request->validate(['event_id' => ['required', 'integer', 'exists:swim_events,id']]);

        $event = SwimEvent::where('id', $request->event_id)
            ->where('meet_id', $meet->id)
            ->firstOrFail();

        $club = $this->userClub();

        $athletes = $this->entryService->eligibleAthletes($event, $club)
            ->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->last_name.', '.$a->first_name,
                'birth_year' => $a->birth_date ? substr($a->birth_date, 0, 4) : null,
                'classes' => $a->sportClasses->pluck('sport_class')->join(', '),
            ]);

        return response()->json($athletes);
    }

    /**
     * AJAX: Bestzeiten eines Athleten für ein bestimmtes Event.
     *
     * GET /meets/{meet}/club-entries/best-times?event_id=X&athlete_id=Y
     */
    public function bestTimes(Request $request, Meet $meet): JsonResponse
    {
        $request->validate([
            'event_id' => ['required', 'integer', 'exists:swim_events,id'],
            'athlete_id' => ['required', 'integer', 'exists:athletes,id'],
        ]);

        $event = SwimEvent::where('id', $request->event_id)->where('meet_id', $meet->id)->firstOrFail();
        $athlete = $this->userClub()->athletes()->findOrFail($request->athlete_id);

        $times = $this->entryService->bestTimes($athlete, $event, $meet);

        return response()->json([
            'LCM' => [
                'raw' => $times['LCM'],
                'formatted' => $this->entryService->formatTime($times['LCM']) ?? 'NT',
            ],
            'SCM' => [
                'raw' => $times['SCM'],
                'formatted' => $this->entryService->formatTime($times['SCM']) ?? 'NT',
            ],
        ]);
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    /**
     * Gibt den Club des eingeloggten Users zurück.
     * Wirft 403, wenn kein Club zugeordnet (und kein Admin).
     */
    private function userClub(): Club
    {
        $user = auth()->user();

        if ($user->is_admin) {
            // Admins: Club über Query-Parameter oder ersten Club nehmen
            // (für Tests/Demo — in Produktion würde man einen Club-Selektor einbauen)
            return Club::firstOrFail();
        }

        if (! $user->club_id) {
            abort(403, 'Kein Verein zugeordnet.');
        }

        return $user->club;
    }

    /**
     * Prüft, ob der User Meldungen verwalten darf (ohne Exception).
     */
    private function canManage(Meet $meet): bool
    {
        return auth()->user()->can('manageEntries', $meet);
    }

    /**
     * Sicherstellen, dass der User (via Policy) auf den Meet zugreifen darf.
     * index() nutzt dies ohne Exception — nur für Lesezugriff.
     */
    private function authorizeMeet(Meet $meet): void
    {
        $user = auth()->user();

        if (! $user->is_admin && ! $user->club_id) {
            abort(403, 'Kein Verein zugeordnet.');
        }

        // Meet muss für Club-Meldungen offen sein (außer für Admins)
        if (! $user->is_admin && ! $meet->is_open) {
            abort(403, 'Dieser Wettkampf ist nicht für Vereinsmeldungen geöffnet.');
        }
    }

    /**
     * Entry muss zum Meet und zum Club des Users gehören.
     */
    private function authorizeEntry(Entry $entry, Meet $meet): void
    {
        $club = $this->userClub();

        abort_if(
            $entry->meet_id !== $meet->id || $entry->club_id !== $club->id,
            403,
            'Zugriff verweigert.'
        );
    }

    /**
     * Leitet die passende Sportklasse eines Athleten für ein Event ab.
     * Nutzt die stroke-basierte Kategorie-Logik (S/SB/SM).
     */
    private function resolveSportClass(int $athleteId, SwimEvent $event): ?string
    {
        $athlete = Athlete::with('sportClasses')->find($athleteId);
        if (! $athlete) {
            return null;
        }

        // Kategorie aus Stroke ableiten
        $category = match ($event->strokeType?->lenex_code) {
            'BREAST' => 'SB',
            'MEDLEY', 'IMRELAY' => 'SM',
            default => 'S',
        };

        $sc = $athlete->sportClasses->firstWhere('category', $category);

        return $sc?->sport_class;
    }
}
