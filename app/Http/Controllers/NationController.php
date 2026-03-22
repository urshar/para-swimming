<?php

namespace App\Http\Controllers;

use App\Models\Nation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NationController extends Controller
{
    public function index(): View
    {
        $nations = Nation::orderBy('name_de')->get();

        return view('nations.index', compact('nations'));
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
