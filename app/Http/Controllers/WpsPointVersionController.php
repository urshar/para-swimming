<?php

namespace App\Http\Controllers;

use App\Models\WpsPointVersion;
use App\Services\WpsVersionImpactService;
use App\Support\SportClassSorter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Verwaltung der WPS-Point-Score-Versionen.
 *
 * Anlegen erfolgt ausschließlich über den Import (WpsPointImportController) — Parametersätze
 * ohne Datei einzupflegen wäre fehleranfällig und ist nicht vorgesehen.
 */
class WpsPointVersionController extends Controller
{
    public function index(): View
    {
        return view('wps.versions.index', [
            'versions' => WpsPointVersion::query()
                ->withCount('parameters')
                ->orderByDesc('year')
                ->orderByDesc('version')
                ->get(),
        ]);
    }

    /**
     * Detailansicht mit filterbarer Parametertabelle.
     *
     * Serverseitig gefiltert und paginiert — eine Version umfasst mehrere hundert
     * Parametersätze, die nicht vollständig in die Seite gehören.
     */
    public function show(Request $request, WpsPointVersion $version, WpsVersionImpactService $impactService): View
    {
        $filters = $request->validate([
            'gender' => 'nullable|string|size:1',
            'sport_class' => 'nullable|string|max:15',
            'course' => 'nullable|string|max:3',
        ]);

        $parameters = $version->parameters()
            ->with('strokeType')
            ->when(
                $filters['gender'] ?? null,
                static fn ($query, string $gender) => $query->where('gender', $gender)
            )
            ->when(
                $filters['sport_class'] ?? null,
                static fn ($query, string $sportClass) => $query->where('sport_class', $sportClass)
            )
            ->when(
                $filters['course'] ?? null,
                static fn ($query, string $course) => $query->where('course', $course)
            )
            ->orderBy('gender')
            ->orderBy('distance')
            ->orderBy('sport_class')
            ->paginate(50)
            ->withQueryString();

        return view('wps.versions.show', [
            'version' => $version,
            'parameters' => $parameters,
            'filters' => $filters,
            'affectedMeets' => $impactService->affectedMeets($version),
            // Nicht ->orderBy('sport_class'): reine String-Sortierung reiht "S10" vor "S2" ein.
            // Stattdessen nach dem Pluck numerisch über SportClassSorter, wie im Basiswerte- und
            // WPS-Rangliste-Dropdown auch (Design-Feedback Erik, 2026-09-04).
            'sportClasses' => $version->parameters()
                ->distinct()
                ->pluck('sport_class')
                ->sortBy(static fn (string $sportClass): string => SportClassSorter::key($sportClass))
                ->values(),
        ]);
    }

    /**
     * Allgemeine Daten einer Version nachträglich korrigieren (z. B. vergessene Versionsnummer, falsches "Gültig ab").
     *
     * Die Parameter selbst bleiben unverändert — dafür gibt es nur den Import einer neuen Version.
     */
    public function edit(WpsPointVersion $version): View
    {
        return view('wps.versions.edit', ['version' => $version]);
    }

    /**
     * Ein geänderter Gültigkeitszeitraum kann die automatisch gewählte Version bestehender Wettkämpfe ändern. Neu
     * berechnet wird bewusst nicht von selbst — die Detailseite zeigt danach die betroffenen Wettkämpfe an
     * (WpsVersionImpactService).
     */
    public function update(Request $request, WpsPointVersion $version): RedirectResponse
    {
        $validated = $request->validate([
            'label' => 'required|string|max:100',
            'year' => 'required|integer|min:1990|max:2100',
            'version' => 'nullable|string|max:20',
            'source' => 'nullable|string|max:255',
            'valid_from' => 'required|date',
            'valid_until' => 'nullable|date|after:valid_from',
        ]);

        $duplicate = WpsPointVersion::query()
            ->whereKeyNot($version->id)
            ->where('year', $validated['year'])
            ->where('version', $validated['version'] ?? null)
            ->exists();

        if ($duplicate) {
            return back()->withInput()->withErrors([
                'version' => "Für das Jahr {$validated['year']} existiert bereits die Version \"".
                    ($validated['version'] ?? '—').'".',
            ]);
        }

        $version->update($validated);

        return redirect()->route('wps.versions.show', $version)
            ->with('success', "Version \"$version->label\" wurde gespeichert.");
    }

    public function activate(WpsPointVersion $version): RedirectResponse
    {
        $version->update(['status' => WpsPointVersion::STATUS_ACTIVE]);

        return redirect()->route('wps.versions.index')
            ->with('success', "Version \"$version->label\" ist wieder aktiv.");
    }

    public function archive(WpsPointVersion $version): RedirectResponse
    {
        $version->update(['status' => WpsPointVersion::STATUS_ARCHIVED]);

        return redirect()->route('wps.versions.index')
            ->with('success', "Version \"$version->label\" wurde archiviert.");
    }

    /**
     * Löschen ist nur zulässig, solange kein Ergebnis auf die Version verweist — sonst ginge
     * die Nachvollziehbarkeit historischer Punkte verloren. In dem Fall ist zu archivieren.
     */
    public function destroy(WpsPointVersion $version): RedirectResponse
    {
        if (! $version->isDeletable()) {
            return redirect()->route('wps.versions.index')
                ->with('error', "Version \"$version->label\" wird von bereits berechneten ".
                        'Ergebnissen verwendet und kann nicht gelöscht werden. '.
                        'Sie kann stattdessen archiviert werden.');
        }

        $label = $version->label;
        $version->delete();

        return redirect()->route('wps.versions.index')
            ->with('success', "Version \"$label\" wurde gelöscht.");
    }
}
