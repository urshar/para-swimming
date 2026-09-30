<?php

namespace App\Http\Controllers;

use App\Models\Nation;
use App\Support\ListUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NationController extends Controller
{
    /** Anzeige-Labels (Singular, Plural) zu den Schlüsseln aus Nation::referenceCounts(). */
    private const array REFERENCE_LABELS = [
        'athletes' => ['Athlet', 'Athleten'],
        'clubs' => ['Verein', 'Vereine'],
        'meets' => ['Veranstaltung', 'Veranstaltungen'],
        'swimRecords' => ['Rekord', 'Rekorde'],
        'meetSwimRecords' => ['Rekord (als Veranstaltungsland)', 'Rekorde (als Veranstaltungsland)'],
        'classifiers' => ['Klassifizierer', 'Klassifizierer'],
    ];

    public function index(Request $request): View
    {
        $sort = in_array($request->query('sort'), ['code', 'name_de', 'name_en'], true)
            ? $request->query('sort')
            : 'code';
        $direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';

        $nations = Nation::orderBy($sort, $direction)->paginate(25)->withQueryString();

        return view('nations.index', compact('nations', 'sort', 'direction'));
    }

    public function create(): View
    {
        return view('nations.create');
    }

    public function store(Request $request): RedirectResponse
    {
        // IOC-Code vor der Validierung normalisieren, damit "ger" als "GER" geprüft und gespeichert wird.
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        $data = $request->validate([
            'code' => ['required', 'regex:/^[A-Z]{3}$/', 'unique:nations,code'],
            'name_de' => 'required|string|max:100',
            'name_en' => 'required|string|max:100',
            'is_active' => 'boolean',
        ], [
            'code.regex' => 'Der IOC-Code muss aus genau drei Buchstaben bestehen.',
            'code.unique' => 'Eine Nation mit diesem IOC-Code existiert bereits.',
        ]);

        $data['is_active'] = $request->boolean('is_active');
        Nation::create($data);

        return redirect()
            ->route('nations.index')
            ->with('success', 'Nation '.$data['code'].' angelegt.');
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
            ->to(ListUrl::to('nations'))
            ->with('success', 'Nation aktualisiert.');
    }

    public function destroy(Nation $nation): RedirectResponse
    {
        // Nur löschen, wenn nichts mehr auf die Nation verweist — sonst DB-Fehler (restrictOnDelete) bzw.
        // stiller Verlust der Nationszuordnung bei Rekorden/Klassifizierern (nullOnDelete).
        $references = $nation->referenceCounts();

        if ($references !== []) {
            $parts = array_map(
                static fn (string $key, int $count) => $count.' '.self::REFERENCE_LABELS[$key][$count === 1 ? 0 : 1],
                array_keys($references),
                $references
            );

            return back()->withErrors([
                'nation' => 'Nation '.$nation->code.' kann nicht gelöscht werden — es hängen noch '
                    .implode(', ', $parts).' daran (einschließlich gelöschter Einträge).',
            ]);
        }

        $nation->delete();

        return redirect()
            ->to(ListUrl::to('nations'))
            ->with('success', 'Nation '.$nation->code.' gelöscht.');
    }
}
