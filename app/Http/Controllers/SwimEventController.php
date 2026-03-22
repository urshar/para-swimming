<?php

namespace App\Http\Controllers;

use App\Models\Meet;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SwimEventController extends Controller
{
    public function create(Meet $meet): View
    {
        $strokeTypes = StrokeType::active()
            ->orderByRaw("FIELD(category, 'standard', 'special', 'fin')")
            ->orderBy('name_de')
            ->get();

        return view('swim-events.form', compact('meet', 'strokeTypes'));
    }

    public function store(Request $request, Meet $meet): RedirectResponse
    {
        $data = $this->validateSwimEvent($request);
        $data['meet_id'] = $meet->id;

        SwimEvent::create($data);

        return redirect()
            ->route('meets.show', $meet)
            ->with('success', 'Disziplin hinzugefügt.');
    }

    public function edit(SwimEvent $event): View
    {
        $strokeTypes = StrokeType::active()
            ->orderByRaw("FIELD(category, 'standard', 'special', 'fin')")
            ->orderBy('name_de')
            ->get();

        return view('swim-events.form', [
            'meet' => $event->meet,
            'event' => $event,
            'strokeTypes' => $strokeTypes,
        ]);
    }

    public function update(Request $request, SwimEvent $event): RedirectResponse
    {
        $data = $this->validateSwimEvent($request);
        $event->update($data);

        return redirect()
            ->route('meets.show', $event->meet)
            ->with('success', 'Disziplin aktualisiert.');
    }

    public function destroy(SwimEvent $event): RedirectResponse
    {
        $meet = $event->meet;

        if ($event->entries()->exists() || $event->results()->exists()) {
            return back()->withErrors([
                'event' => 'Disziplin kann nicht gelöscht werden — es gibt bereits Meldungen oder Ergebnisse.',
            ]);
        }

        $event->delete();

        return redirect()
            ->route('meets.show', $meet)
            ->with('success', 'Disziplin gelöscht.');
    }

    // ── Private Hilfsmethoden ─────────────────────────────────────────────────

    private function validateSwimEvent(Request $request): array
    {
        return $request->validate([
            'stroke_type_id' => 'required|exists:stroke_types,id',
            'event_number' => 'nullable|integer|min:1',
            'session_number' => 'required|integer|min:1',
            'gender' => 'required|in:M,F,A,X',
            'round' => 'required|in:TIM,FHT,FIN,SEM,QUA,PRE,SOP,SOS,SOQ,TIMETRIAL',
            'distance' => 'required|integer|min:1',
            'relay_count' => 'required|integer|min:1',
            'technique' => 'nullable|in:DIVE,GLIDE,KICK,PULL,START,TURN',
            'style_code' => 'nullable|string|max:6',
            'style_name' => 'nullable|string|max:255',
            'sport_classes' => 'nullable|string|max:100',
            'timing' => 'nullable|in:AUTOMATIC,SEMIAUTOMATIC,MANUAL3,MANUAL2,MANUAL1',
        ]);
    }
}
