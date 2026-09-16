<?php

namespace App\Http\Controllers;

use App\Models\WpsPointVersion;
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
    public function show(Request $request, WpsPointVersion $version): View
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
                ->withErrors([
                    'version' => "Version \"$version->label\" wird von bereits berechneten ".
                        'Ergebnissen verwendet und kann nicht gelöscht werden. '.
                        'Sie kann stattdessen archiviert werden.',
                ]);
        }

        $label = $version->label;
        $version->delete();

        return redirect()->route('wps.versions.index')
            ->with('success', "Version \"$label\" wurde gelöscht.");
    }
}
