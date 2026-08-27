<?php

namespace App\Http\Controllers;

use App\Models\Nation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NationController extends Controller
{
    public function index(Request $request): View
    {
        $sort = in_array($request->query('sort'), ['code', 'name_de', 'name_en'], true)
            ? $request->query('sort')
            : 'code';
        $direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';

        $nations = Nation::orderBy($sort, $direction)->paginate(25)->withQueryString();

        return view('nations.index', compact('nations', 'sort', 'direction'));
    }

    public function edit(Nation $nation): View
    {
        return view('nations.edit', compact('nation'));
    }

    public function update(Request $request, Nation $nation): RedirectResponse
    {
        $data = $request->validate([
            'name_de' => 'required|string|max:100',
            'name_en' => 'required|string|max:100',
            'is_active' => 'boolean',
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $nation->update($data);

        return redirect()
            ->route('nations.index')
            ->with('success', 'Nation aktualisiert.');
    }
}
