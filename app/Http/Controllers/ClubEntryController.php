<?php

namespace App\Http\Controllers;

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Entry;
use App\Models\Meet;
use App\Models\RelayEntry;
use App\Models\RelayEntryMember;
use App\Models\SwimEvent;
use App\Services\ClubEntryService;
use App\Services\RelayClassValidator;
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
        private readonly RelayClassValidator $relayValidator,
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

    // ── Create Relay ──────────────────────────────────────────────────────────────

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
            ->route('club-entries.index', array_merge(['meet' => $meet], $this->clubParam()))
            ->with('success', 'Meldung gespeichert.');
    }

    // ── Update Relay ──────────────────────────────────────────────────────────────

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

    // ── Edit / Update ─────────────────────────────────────────────────────────

    /**
     * Meldung löschen.
     */
    public function destroy(Meet $meet, Entry $entry): RedirectResponse
    {
        $this->authorize('deleteEntry', $meet);
        $this->authorizeEntry($entry, $meet);

        $entry->delete();

        return redirect()
            ->route('club-entries.index', array_merge(['meet' => $meet], $this->clubParam()))
            ->with('success', 'Meldung gelöscht.');
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

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

        abort_if(! $request->wantsJson(), 400, 'JSON expected.');

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

    // ── Private Hilfsmethoden (Relay) ─────────────────────────────────────────────

    /**
     * Übersicht aller Staffelmeldungen des eigenen Clubs für einen Wettkampf.
     */
    public function indexRelay(Meet $meet): View
    {
        $this->authorizeMeet($meet);

        $club = $this->userClub();

        $relayEntries = RelayEntry::query()
            ->with(['swimEvent.strokeType', 'members.athlete.sportClasses'])
            ->where('meet_id', $meet->id)
            ->where('club_id', $club->id)
            ->orderBy('swim_event_id')
            ->orderBy('id')
            ->get();

        $canManage = $this->canManage($meet);

        return view('club-entries.index-relay', compact('meet', 'club', 'relayEntries', 'canManage'));
    }

    /**
     * Formular: neue Staffelmeldung anlegen.
     */
    public function createRelay(Meet $meet): View
    {
        $this->authorize('manageEntries', $meet);

        $club = $this->userClub();

        $events = SwimEvent::query()
            ->with('strokeType')
            ->where('meet_id', $meet->id)
            ->where('relay_count', '>', 1)
            ->orderBy('event_number')
            ->get();

        return view('club-entries.create-relay', compact('meet', 'club', 'events'));
    }

    /**
     * Staffelmeldung speichern (mit Members + automatischer relay_class-Berechnung).
     */
    public function storeRelay(Request $request, Meet $meet): RedirectResponse
    {
        $this->authorize('manageEntries', $meet);

        $club = $this->userClub();

        $validated = $request->validate([
            'swim_event_id' => ['required', 'integer', 'exists:swim_events,id'],
            'athlete_ids' => ['nullable', 'array'],
            'athlete_ids.*' => ['integer', 'exists:athletes,id'],
            'entry_time' => ['nullable', 'string', 'max:20'],
            'entry_course' => ['nullable', 'in:LCM,SCM,SCY'],
        ]);

        // SwimEvent muss zum Meet gehören und ein Staffel-Event sein
        $event = SwimEvent::where('id', $validated['swim_event_id'])
            ->where('meet_id', $meet->id)
            ->where('relay_count', '>', 1)
            ->firstOrFail();

        // Athleten validieren (Club-Zugehörigkeit + max. relay_count), wenn angegeben
        $athleteIds = [];
        if (! empty($validated['athlete_ids'])) {
            $athleteIds = $this->resolveAndValidateAthletes($validated['athlete_ids'], $club, $event);
            if ($athleteIds instanceof RedirectResponse) {
                return $athleteIds;
            }
        }

        // Meldezeit parsen
        [$entryTime, $entryTimeCode] = $this->parseEntryTime($validated['entry_time'] ?? null);

        // relay_class berechnen (null, wenn keine Athleten angegeben)
        $relayClass = ! empty($athleteIds) ? $this->computeRelayClass($athleteIds, $event) : null;

        $relayEntry = RelayEntry::create([
            'meet_id' => $meet->id,
            'swim_event_id' => $event->id,
            'club_id' => $club->id,
            'relay_class' => $relayClass,
            'entry_time' => $entryTime,
            'entry_time_code' => $entryTimeCode,
            'entry_course' => $validated['entry_course'] ?? $meet->course,
            'status' => 'pending',
        ]);

        // Members anlegen
        foreach (array_values($athleteIds) as $pos => $athleteId) {
            $sportClass = $this->resolveSportClass($athleteId, $event);
            RelayEntryMember::create([
                'relay_entry_id' => $relayEntry->id,
                'athlete_id' => $athleteId,
                'position' => $pos + 1,
                'sport_class' => $sportClass,
            ]);
        }

        return redirect()
            ->route('club-entries.relay.index', array_merge(['meet' => $meet], $this->clubParam()))
            ->with('success', 'Staffelmeldung gespeichert.');
    }

    // ── Destroy ───────────────────────────────────────────────────────────────

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
     * Formular: Staffelmeldung bearbeiten.
     */
    public function editRelay(Meet $meet, RelayEntry $relayEntry): View
    {
        $this->authorize('manageEntries', $meet);
        $this->authorizeRelayEntry($relayEntry, $meet);

        $relayEntry->load(['members.athlete', 'swimEvent.strokeType']);

        $club = $this->userClub();

        $events = SwimEvent::query()
            ->with('strokeType')
            ->where('meet_id', $meet->id)
            ->where('relay_count', '>', 1)
            ->orderBy('event_number')
            ->get();

        return view('club-entries.edit-relay', compact('meet', 'club', 'relayEntry', 'events'));
    }

    /**
     * Staffelmeldung aktualisieren (Members werden komplett neu geschrieben).
     */
    public function updateRelay(Request $request, Meet $meet, RelayEntry $relayEntry): RedirectResponse
    {
        $this->authorize('manageEntries', $meet);
        $this->authorizeRelayEntry($relayEntry, $meet);

        $club = $this->userClub();

        $validated = $request->validate([
            'athlete_ids' => ['nullable', 'array'],
            'athlete_ids.*' => ['integer', 'exists:athletes,id'],
            'entry_time' => ['nullable', 'string', 'max:20'],
            'entry_course' => ['nullable', 'in:LCM,SCM,SCY'],
        ]);

        $event = $relayEntry->swimEvent;

        // Athleten validieren (Club-Zugehörigkeit + max. relay_count), wenn angegeben
        $athleteIds = [];
        if (! empty($validated['athlete_ids'])) {
            $athleteIds = $this->resolveAndValidateAthletes($validated['athlete_ids'], $club, $event);
            if ($athleteIds instanceof RedirectResponse) {
                return $athleteIds;
            }
        }

        [$entryTime, $entryTimeCode] = $this->parseEntryTime($validated['entry_time'] ?? null);

        // relay_class neu berechnen (nur wenn Athleten angegeben)
        $relayClass = ! empty($athleteIds) ? $this->computeRelayClass($athleteIds, $event) : $relayEntry->relay_class;

        $relayEntry->update([
            'relay_class' => $relayClass,
            'entry_time' => $entryTime,
            'entry_time_code' => $entryTimeCode,
            'entry_course' => $validated['entry_course'] ?? $relayEntry->entry_course,
        ]);

        // Members komplett neu schreiben — nur, wenn Athleten angegeben wurden
        if (! empty($athleteIds)) {
            $relayEntry->members()->delete();
            foreach (array_values($athleteIds) as $pos => $athleteId) {
                $sportClass = $this->resolveSportClass($athleteId, $event);
                RelayEntryMember::create([
                    'relay_entry_id' => $relayEntry->id,
                    'athlete_id' => $athleteId,
                    'position' => $pos + 1,
                    'sport_class' => $sportClass,
                ]);
            }
        }

        return redirect()
            ->route('club-entries.relay.index', array_merge(['meet' => $meet], $this->clubParam()))
            ->with('success', 'Staffelmeldung aktualisiert.');
    }

    // ── AJAX: Eligible Relay Athletes ─────────────────────────────────────────────

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
            ->route('club-entries.index', array_merge(['meet' => $meet], $this->clubParam()))
            ->with('success', 'Meldung aktualisiert.');
    }

    /**
     * Staffelmeldung löschen (Members werden per cascadeOnDelete mitgelöscht).
     */
    public function destroyRelay(Meet $meet, RelayEntry $relayEntry): RedirectResponse
    {
        $this->authorize('deleteEntry', $meet);
        $this->authorizeRelayEntry($relayEntry, $meet);

        $relayEntry->delete();

        return redirect()
            ->route('club-entries.relay.index', array_merge(['meet' => $meet], $this->clubParam()))
            ->with('success', 'Staffelmeldung gelöscht.');
    }

    // ── Create / Store ────────────────────────────────────────────────────────

    /**
     * AJAX: Athleten für ein Staffel-Event (nur Geschlecht-Filter).
     *
     * GET /meets/{meet}/relay-entries/relay-athletes?event_id=X
     */
    public function eligibleRelayAthletes(Request $request, Meet $meet): JsonResponse
    {
        $request->validate([
            'event_id' => ['required', 'integer', 'exists:swim_events,id'],
            'relay_entry_id' => ['nullable', 'integer', 'exists:relay_entries,id'],
        ]);

        $event = SwimEvent::where('id', $request->event_id)
            ->where('meet_id', $meet->id)
            ->where('relay_count', '>', 1)
            ->firstOrFail();

        $club = $this->userClub();

        $athletes = $this->entryService->eligibleRelayAthletes($event, $club,
            $request->integer('relay_entry_id') ?: null)
            ->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->last_name.', '.$a->first_name,
                'birth_year' => $a->birth_date ? substr($a->birth_date, 0, 4) : null,
                'classes' => $a->sportClasses->pluck('sport_class')->join(', '),
            ]);

        return response()->json($athletes);
    }

    // ── AJAX Endpunkte ────────────────────────────────────────────────────────

    /**
     * Leitet auf das einzige offene Meet weiter, oder zeigt eine Auswahl.
     * Einstiegspunkt für den Sidebar-Link "Einzelmeldungen".
     */
    public function pickMeet(): RedirectResponse|View
    {
        $this->authorizeClubAccess();

        $user = auth()->user();
        $openMeets = Meet::where('is_open', true)->orderBy('start_date')->get();
        $clubs = $user->is_admin ? Club::orderBy('name')->get() : null;

        if (! $user->is_admin && $openMeets->count() === 1) {
            return redirect()->route('club-entries.index', $openMeets->first());
        }

        return view('club-entries.pick-meet', [
            'meets' => $openMeets,
            'clubs' => $clubs,
            'mode' => 'individual',
        ]);
    }

    /**
     * Leitet auf das einzige offene Meet weiter, oder zeigt eine Auswahl.
     * Einstiegspunkt für den Sidebar-Link "Staffelmeldungen".
     */
    public function pickMeetRelay(): RedirectResponse|View
    {
        $this->authorizeClubAccess();

        $user = auth()->user();
        $openMeets = Meet::where('is_open', true)->orderBy('start_date')->get();
        $clubs = $user->is_admin ? Club::orderBy('name')->get() : null;

        if (! $user->is_admin && $openMeets->count() === 1) {
            return redirect()->route('club-entries.relay.index', $openMeets->first());
        }

        return view('club-entries.pick-meet', [
            'meets' => $openMeets,
            'clubs' => $clubs,
            'mode' => 'relay',
        ]);
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
     * Gibt den Club des eingeloggten Users zurück.
     * Wirft 403, wenn kein Club zugeordnet (und kein Admin).
     */
    private function userClub(): Club
    {
        $user = auth()->user();

        if ($user->is_admin) {
            $clubId = request()->integer('club_id');
            if (! $clubId) {
                abort(400, 'Bitte einen Verein auswählen.');
            }

            return Club::findOrFail($clubId);
        }

        if (! $user->club_id) {
            abort(403, 'Kein Verein zugeordnet.');
        }

        return $user->club;
    }

    // ── Index Relay ───────────────────────────────────────────────────────────────

    /**
     * Prüft, ob der User Meldungen verwalten darf (ohne Exception).
     */
    private function canManage(Meet $meet): bool
    {
        return auth()->user()->can('manageEntries', $meet);
    }

    // ── Store Relay ───────────────────────────────────────────────────────────────

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

    // ── Edit Relay ────────────────────────────────────────────────────────────────

    /**
     * Gibt club_id als Query-Parameter-Array zurück (nur für Admins relevant).
     * Wird an alle Links/Redirects angehängt damit der Kontext erhalten bleibt.
     */
    private function clubParam(): array
    {
        if (auth()->user()->is_admin && request()->has('club_id')) {
            return ['club_id' => request()->integer('club_id')];
        }

        return [];
    }

    // ── Destroy Relay ─────────────────────────────────────────────────────────────

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
    // ── Club-Auswahl (Einstiegspunkte Sidebar) ───────────────────────────────

    /**
     * Dedupliziert, prüft Club-Zugehörigkeit und relay_count-Limit.
     * Gibt das bereinigte Array zurück oder eine RedirectResponse bei Fehler.
     *
     * @return array<int>|RedirectResponse
     */
    private function resolveAndValidateAthletes(array $rawIds, Club $club, SwimEvent $event): array|RedirectResponse
    {
        $athleteIds = array_unique($rawIds);

        foreach ($athleteIds as $athleteId) {
            $club->athletes()->findOrFail($athleteId);
        }

        if (count($athleteIds) > $event->relay_count) {
            return back()
                ->withInput()
                ->withErrors(['athlete_ids' => 'Zu viele Athleten für diese Staffel (max. '.$event->relay_count.')']);
        }

        return $athleteIds;
    }

    /**
     * Parst Meldezeit-String → [entryTime, entryTimeCode].
     * Ausgelagert, um Duplikation zwischen store/update zu vermeiden.
     *
     * @return array{0: ?int, 1: ?string}
     */
    private function parseEntryTime(?string $raw): array
    {
        if (! $raw || trim($raw) === '') {
            return [null, null];
        }

        $upper = strtoupper(trim($raw));
        if (in_array($upper, ['NT', 'NS', 'WO'], true)) {
            return [null, $upper];
        }

        $parsed = TimeParser::parse($raw);

        return [$parsed, null];
    }

    /**
     * Berechnet die relay_class aus Athleten-IDs und Event.
     * Gibt null zurück, wenn die Kombination ungültig ist.
     */
    private function computeRelayClass(array $athleteIds, SwimEvent $event): ?string
    {
        $strokeCode = $event->strokeType?->lenex_code ?? '';
        $category = match (strtoupper($strokeCode)) {
            'BREAST' => 'SB',
            'MEDLEY', 'IMRELAY' => 'SM',
            default => 'S',
        };

        $memberClasses = [];
        foreach ($athleteIds as $athleteId) {
            $athlete = Athlete::with('sportClasses')->find($athleteId);
            if (! $athlete) {
                continue;
            }
            $sc = $athlete->sportClasses->firstWhere('category', $category);
            if ($sc) {
                $memberClasses[] = $category.$sc->class_number;
            }
        }

        if (empty($memberClasses)) {
            return null;
        }

        return $this->relayValidator->resolveRelayClass($memberClasses);
    }

    /**
     * RelayEntry muss zum Meet und zum Club des Users gehören.
     */
    private function authorizeRelayEntry(RelayEntry $relayEntry, Meet $meet): void
    {
        $club = $this->userClub();

        abort_if(
            $relayEntry->meet_id !== $meet->id || $relayEntry->club_id !== $club->id,
            403,
            'Zugriff verweigert.'
        );
    }

    /**
     * Prüft nur ob der User Club-Zugang hat (ohne Meet-Kontext).
     * Für pick-meet Routen die noch kein konkretes Meet haben.
     */
    private function authorizeClubAccess(): void
    {
        $user = auth()->user();
        if (! $user->is_admin && ! $user->club_id) {
            abort(403, 'Kein Verein zugeordnet.');
        }
    }
}
