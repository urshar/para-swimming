<?php

namespace App\Http\Controllers;

use App\Concerns\SearchesAthletes;
use App\Models\Athlete;
use App\Models\Club;
use App\Models\Entry;
use App\Models\Meet;
use App\Models\SwimEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EntryController extends Controller
{
    use SearchesAthletes;

    public function index(Request $request): View
    {
        $query = Entry::with(['athlete', 'club', 'swimEvent.strokeType', 'meet'])
            ->latest();

        if ($meetId = $request->query('meet_id')) {
            $query->where('meet_id', $meetId);
        }

        if ($search = $request->query('search')) {
            $this->applyAthleteSearch($query, $search);
        }

        $entries = $query->paginate(25)->withQueryString();
        $meets = Meet::orderByDesc('start_date')->get();

        return view('entries.index', compact('entries', 'meets'));
    }

    public function create(Meet $meet): RedirectResponse|View
    {
        if (! $meet->is_open) {
            return back()->withErrors([
                'meet' => 'Dieser Wettkampf ist nicht offen für Club-Meldungen.',
            ]);
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

    public function store(Request $request, Meet $meet): RedirectResponse
    {
        $data = $request->validate(array_merge(
            [
                'swim_event_id' => 'required|exists:swim_events,id',
                'athlete_id' => 'required|exists:athletes,id',
                'club_id' => 'required|exists:clubs,id',
            ],
            $this->sharedEntryRules()
        ));

        // Prüfen ob SwimEvent zum Meet gehört
        $swimEvent = SwimEvent::findOrFail($data['swim_event_id']);
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

        Entry::create(array_merge($data, ['meet_id' => $meet->id]));

        return redirect()
            ->route('meets.show', $meet)
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

        $entry->update($data);

        return redirect()
            ->route('meets.show', $entry->meet)
            ->with('success', 'Meldung aktualisiert.');
    }

    public function destroy(Entry $entry): RedirectResponse
    {
        $meet = $entry->meet;
        $entry->delete();

        return redirect()
            ->route('meets.show', $meet)
            ->with('success', 'Meldung gelöscht.');
    }

    // ── Private Hilfsmethoden ─────────────────────────────────────────────────

    private function sharedEntryRules(): array
    {
        return [
            'entry_time' => 'nullable|integer|min:0',
            'entry_time_code' => 'nullable|string|max:10',
            'entry_course' => 'nullable|in:LCM,SCM,SCY,SCM16,SCM20,SCM33,SCY20,SCY27,SCY33,SCY36,OPEN',
            'sport_class' => 'nullable|string|max:15',
            'status' => 'nullable|in:EXH,RJC,SICK,WDR',
        ];
    }
}
