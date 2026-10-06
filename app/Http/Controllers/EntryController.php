<?php

namespace App\Http\Controllers;

use App\Concerns\SearchesAthletes;
use App\Models\Athlete;
use App\Models\Club;
use App\Models\Entry;
use App\Models\Meet;
use App\Models\SwimEvent;
use App\Services\ClubEntryService;
use App\Support\ListUrl;
use App\Support\TimeParser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EntryController extends Controller
{
    use SearchesAthletes;

    public function index(Request $request): View
    {
        // Route ist admin-only (RequireAdmin) — das Cockpit zeigt immer alle Meldungen.
        // Basis-Query (Wettkampf + Suche) als gemeinsame Grundlage für Kennzahlen und Liste.
        $base = Entry::query();

        if ($meetId = $request->query('meet_id')) {
            $base->where('meet_id', $meetId);
        }

        if ($search = $request->query('search')) {
            $this->applyAthleteSearch($base, $search);
        }

        // Kennzahlen-Kacheln: zählen im aktuellen Wettkampf-/Such-Kontext, aber unabhängig
        // vom gewählten Status-/Problemfilter (damit die Aufschlüsselung immer vollständig
        // bleibt). Wiederverwendung der Filter-Methoden über countFiltered(), damit die Zähl-
        // und die Filterlogik nicht auseinanderlaufen.
        $counts = [
            'total' => (clone $base)->count(),
            'wdr' => $this->countFiltered($base, fn (Builder $q) => $this->applyStatusFilter($q, 'WDR')),
            'sick' => $this->countFiltered($base, fn (Builder $q) => $this->applyStatusFilter($q, 'SICK')),
            'exh' => $this->countFiltered($base, fn (Builder $q) => $this->applyStatusFilter($q, 'EXH')),
            'no_time' => $this->countFiltered($base, fn (Builder $q) => $this->applyProblemFilter($q, 'no_time')),
            'no_class' => $this->countFiltered($base, fn (Builder $q) => $this->applyProblemFilter($q, 'no_class')),
        ];

        // Liste zusätzlich nach Status/Problem filtern.
        $query = (clone $base)->with(['athlete', 'club', 'swimEvent.strokeType', 'meet'])->latest();
        $this->applyStatusFilter($query, $request->query('status'));
        $this->applyProblemFilter($query, $request->query('problem'));

        $entries = $query->paginate(25)->withQueryString();
        $meets = Meet::orderByDesc('start_date')->get();

        return view('entries.index', compact('entries', 'meets', 'counts'));
    }

    public function create(Meet $meet): RedirectResponse|View
    {
        if (! $meet->is_open) {
            return back()->with('error', 'Dieser Wettkampf ist nicht offen für Club-Meldungen.');
        }

        $swimEvents = $meet->swimEvents()
            ->with('strokeType')
            ->orderBy('session_number')
            ->orderBy('event_number')
            ->get();

        $clubs = Club::with('nation')->orderBy('name')->get();
        $athletes = Athlete::with(['club', 'nation', 'sportClasses'])
            ->orderBy('last_name')
            ->get();

        return view('entries.form', compact('meet', 'swimEvents', 'clubs', 'athletes'));
    }

    /**
     * AJAX: Bestzeiten (Jahres- + absolute) eines beliebigen Athleten für ein Event dieses
     * Meets — für die admin-seitige Meldungserfassung (entries/form). Anders als
     * ClubEntryController::bestTimes() NICHT club-scoped: Admins dürfen jeden Athleten melden.
     *
     * GET /meets/{meet}/entries/best-times?event_id=X&athlete_id=Y
     */
    public function bestTimes(Request $request, Meet $meet, ClubEntryService $service): JsonResponse
    {
        $request->validate([
            'event_id' => ['required', 'integer', 'exists:swim_events,id'],
            'athlete_id' => ['required', 'integer', 'exists:athletes,id'],
        ]);

        $event = SwimEvent::where('id', $request->event_id)->where('meet_id', $meet->id)->firstOrFail();
        $athlete = Athlete::findOrFail($request->athlete_id);

        return response()->json($service->bestTimesForPanel($athlete, $event, $meet));
    }

    public function store(Request $request, Meet $meet, ClubEntryService $service): RedirectResponse
    {
        $data = $request->validate(array_merge(
            [
                'swim_event_id' => 'required|exists:swim_events,id',
                'athlete_id' => 'required|exists:athletes,id',
                'club_id' => 'required|exists:clubs,id',
            ],
            $this->sharedEntryRules()
        ));

        // Prüfen ob SwimEvent zum Meet gehört (strokeType für die Sportklassen-Ableitung mitladen)
        $swimEvent = SwimEvent::with('strokeType')->findOrFail($data['swim_event_id']);
        if ($swimEvent->meet_id !== $meet->id) {
            return back()->withErrors([
                'swim_event_id' => 'Diese Disziplin gehört nicht zu diesem Wettkampf.',
            ]);
        }

        // Prüfen ob bereits gemeldet
        $alreadyEntered = Entry::where('meet_id', $meet->id)
            ->where('swim_event_id', $data['swim_event_id'])
            ->where('athlete_id', $data['athlete_id'])
            ->exists();

        if ($alreadyEntered) {
            return back()->withErrors([
                'athlete_id' => 'Dieser Athlet ist für diese Disziplin bereits gemeldet.',
            ]);
        }

        // Sportklasse: leeres Feld = aus dem Athleten ableiten (wie im Club-Flow);
        // ein ausgefülltes Feld bleibt als bewusste Abweichung erhalten.
        if (empty($data['sport_class'])) {
            $data['sport_class'] = $service->resolveSportClass((int) $data['athlete_id'], $swimEvent);
        }

        [$entryTime, $entryTimeCode] = $this->parseEntryTime($data['entry_time'] ?? null);
        unset($data['entry_time']);

        Entry::create(array_merge($data, [
            'meet_id' => $meet->id,
            'entry_time' => $entryTime,
            'entry_time_code' => $entryTimeCode,
            'is_late_entry' => $meet->isDeadlinePassed(),
        ]));

        return $this->redirectAfterSave($request, $meet)
            ->with('success', 'Meldung erfolgreich angelegt.');
    }

    public function edit(Entry $entry): View
    {
        $entry->load(['meet', 'athlete', 'club', 'swimEvent.strokeType']);

        $clubs = Club::with('nation')->orderBy('name')->get();

        return view('entries.edit', compact('entry', 'clubs'));
    }

    public function update(Request $request, Entry $entry): RedirectResponse
    {
        $data = $request->validate(array_merge(
            [
                'club_id' => 'required|exists:clubs,id',
                'heat' => 'nullable|integer|min:1',
                'lane' => 'nullable|integer|min:0',
            ],
            $this->sharedEntryRules()
        ));

        [$entryTime, $entryTimeCode] = $this->parseEntryTime($data['entry_time'] ?? null);
        $data['entry_time'] = $entryTime;
        $data['entry_time_code'] = $entryTimeCode;

        $entry->update($data);

        // Zurück zur Meldungsliste, aus der bearbeitet wurde (Cockpit oder "Alle Meldungen", inkl. Filter).
        return redirect()
            ->to(ListUrl::to('entries'))
            ->with('success', 'Meldung aktualisiert.');
    }

    public function destroy(Entry $entry): RedirectResponse
    {
        $entry->delete();

        // Zurück zur (ggf. gefilterten) Meldungsliste, von der aus gelöscht wurde —
        // nicht zur Wettkampf-Detailseite. Andernfalls verliert man beim Löschen
        // mehrerer Meldungen nacheinander jedes Mal den Filter-/Listenkontext.
        return back()->with('success', 'Meldung gelöscht.');
    }

    // ── Private Hilfsmethoden ─────────────────────────────────────────────────

    /**
     * Statusfilter des Cockpits. "NORMAL" meint Meldungen ohne besonderen Status
     * (weder noch WDR/SICK/EXH/RJC); ein leerer/null-Wert (geleertes clearable-Select)
     * lässt die Liste ungefiltert.
     */
    private function applyStatusFilter(Builder $query, ?string $status): void
    {
        if ($status === 'NORMAL') {
            $query->where(function (Builder $q) {
                $q->whereNull('status')->orWhere('status', '');
            });

            return;
        }

        if (in_array($status, ['WDR', 'SICK', 'EXH', 'RJC'], true)) {
            $query->where('status', $status);
        }
    }

    /**
     * Problemfilter des Cockpits: Meldungen, die vor dem Wettkampf noch Handlung
     * brauchen — ohne Meldezeit oder ohne Sportklasse. (Eine echte Doppelmeldung
     * kann es nicht geben — der Unique-Constraint [meet_id, swim_event_id,
     * athlete_id] auf entries verhindert sie bereits auf DB-Ebene.)
     */
    private function applyProblemFilter(Builder $query, ?string $problem): void
    {
        if ($problem === 'no_time') {
            $query->whereNull('entry_time');
        } elseif ($problem === 'no_class') {
            $query->where(function (Builder $q) {
                $q->whereNull('sport_class')->orWhere('sport_class', '');
            });
        }
    }

    /**
     * Zählt die Meldungen einer Klon-Basis-Query nach Anwenden eines Filter-Callbacks.
     * Eigene Methode statt tap()->count(), damit PhpStorms Generics-Resolver nicht auf
     * HigherOrderTapProxy zurückfällt ("Method 'count' not found").
     *
     * @param  callable(Builder): void  $filter
     */
    private function countFiltered(Builder $base, callable $filter): int
    {
        $query = clone $base;
        $filter($query);

        return $query->count();
    }

    private function sharedEntryRules(): array
    {
        return [
            'entry_time' => 'nullable|string|max:20',
            'entry_course' => 'nullable|in:LCM,SCM,SCY,SCM16,SCM20,SCM33,SCY20,SCY27,SCY33,SCY36,OPEN',
            'sport_class' => 'nullable|string|max:15',
            'status' => 'nullable|in:EXH,RJC,SICK,WDR',
        ];
    }

    /**
     * Parst das Meldezeit-Feld (Format "MM:SS.hh", wie club-entries — statt roher
     * Hundertstelsekunden, die ein User erst im Kopf umrechnen müsste). "NT"/"NS"/"WO"
     * werden als Code statt als Zeit gespeichert.
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

        return [TimeParser::parse($raw), null];
    }

    /**
     * Nach dem Speichern zurück zur Herkunftsseite, wenn ein internes return_to
     * mitgegeben wurde (z. B. die "Alle Meldungen"-Übersicht mit gesetztem
     * Filter), sonst zur Wettkampf-Detailseite. Nur gleiche Origin, um
     * Open-Redirects auszuschließen.
     */
    private function redirectAfterSave(Request $request, Meet $meet): RedirectResponse
    {
        $returnTo = $request->input('return_to');

        if (is_string($returnTo) && $returnTo !== '' && str_starts_with($returnTo, url('/'))) {
            return redirect($returnTo);
        }

        return redirect()->route('meets.show', $meet);
    }
}
