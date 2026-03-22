<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\Nation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ClubController extends Controller
{
    public function index(Request $request): View
    {
        $query = Club::with('nation')->orderBy('name');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('code', 'like', '%'.$search.'%');
            });
        }

        if ($nationId = $request->query('nation_id')) {
            $query->where('nation_id', $nationId);
        }

        $clubs = $query->paginate(25)->withQueryString();
        $nations = Nation::orderBy('name_de')->get();

        return view('clubs.index', compact('clubs', 'nations'));
    }

    public function create(): View
    {
        $nations = Nation::active()->orderBy('name_de')->get();

        return view('clubs.form', compact('nations'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'short_name' => 'nullable|string|max:40',
            'code' => 'nullable|string|max:10',
            'nation_id' => 'required|exists:nations,id',
            'type' => 'required|in:CLUB,NATIONALTEAM,REGIONALTEAM,UNATTACHED',
        ]);

        $club = Club::create($data);

        return redirect()
            ->route('clubs.show', $club)
            ->with('success', 'Club erfolgreich angelegt.');
    }

    public function show(Club $club): View
    {
        $club->load('nation');
        $club->loadCount(['athletes', 'entries', 'results']);

        $athletes = $club->athletes()
            ->with('sportClasses')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(20);

        return view('clubs.show', compact('club', 'athletes'));
    }

    public function edit(Club $club): View
    {
        $nations = Nation::active()->orderBy('name_de')->get();

        return view('clubs.form', compact('club', 'nations'));
    }

    public function update(Request $request, Club $club): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'short_name' => 'nullable|string|max:40',
            'code' => 'nullable|string|max:10',
            'nation_id' => 'required|exists:nations,id',
            'type' => 'required|in:CLUB,NATIONALTEAM,REGIONALTEAM,UNATTACHED',
        ]);

        $club->update($data);

        return redirect()
            ->route('clubs.show', $club)
            ->with('success', 'Club aktualisiert.');
    }

    public function destroy(Club $club): RedirectResponse
    {
        // Prüfen ob Athleten zugeordnet sind
        if ($club->athletes()->exists()) {
            return back()->withErrors([
                'club' => 'Club kann nicht gelöscht werden — es sind noch '.
                    $club->athletes()->count().' Athleten zugeordnet.',
            ]);
        }

        $club->delete();

        return redirect()
            ->route('clubs.index')
            ->with('success', 'Club gelöscht.');
    }
}
