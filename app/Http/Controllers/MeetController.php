<?php

namespace App\Http\Controllers;

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MeetController extends Controller
{
    public function index(Request $request): View
    {
        $query = Meet::with('nation')->latest('start_date');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('city', 'like', '%'.$search.'%');
            });
        }

        if ($course = $request->query('course')) {
            $query->where('course', $course);
        }

        if ($year = $request->query('year')) {
            $query->whereYear('start_date', $year);
        }

        $meets = $query->paginate(20)->withQueryString();
        $meetCount = Meet::count();
        $athleteCount = Athlete::count();
        $clubCount = Club::count();
        $resultCount = Result::count();

        return view('meets.index', compact(
            'meets', 'meetCount', 'athleteCount', 'clubCount', 'resultCount'
        ));
    }

    public function create(): View
    {
        $nations = Nation::active()->orderBy('name_de')->get();

        return view('meets.form', compact('nations'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateMeet($request);
        $data['is_open'] = $request->boolean('is_open');

        $meet = Meet::create($data);

        return redirect()
            ->route('meets.show', $meet)
            ->with('success', 'Wettkampf erfolgreich erstellt.');
    }

    public function show(Meet $meet): View
    {
        $meet->load(['nation', 'clubs.nation']);
        $meet->loadCount(['swimEvents', 'entries', 'results']);

        $swimEvents = $meet->swimEvents()
            ->with('strokeType')
            ->orderBy('session_number')
            ->orderBy('event_number')
            ->get();

        return view('meets.show', compact('meet', 'swimEvents'));
    }

    public function edit(Meet $meet): View
    {
        $nations = Nation::active()->orderBy('name_de')->get();

        return view('meets.form', compact('meet', 'nations'));
    }

    public function update(Request $request, Meet $meet): RedirectResponse
    {
        $data = $this->validateMeet($request);
        $data['is_open'] = $request->boolean('is_open');

        $meet->update($data);

        return redirect()
            ->route('meets.show', $meet)
            ->with('success', 'Wettkampf aktualisiert.');
    }

    public function destroy(Meet $meet): RedirectResponse
    {
        $meet->delete();

        return redirect()
            ->route('meets.index')
            ->with('success', 'Wettkampf gelöscht.');
    }

    // ── Private Hilfsmethoden ─────────────────────────────────────────────────

    private function validateMeet(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'city' => 'required|string|max:100',
            'nation_id' => 'required|exists:nations,id',
            'course' => 'required|in:LCM,SCM,SCY,SCM16,SCM20,SCM33,SCY20,SCY27,SCY33,SCY36,OPEN',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'organizer' => 'nullable|string|max:255',
            'altitude' => 'nullable|integer|min:0|max:9000',
            'timing' => 'nullable|in:AUTOMATIC,SEMIAUTOMATIC,MANUAL3,MANUAL2,MANUAL1',
            'entry_type' => 'nullable|in:OPEN,INVITATION',
            'is_open' => 'boolean',
        ]);
    }
}
