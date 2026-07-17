<?php

namespace App\Http\Controllers;

use App\Models\BaseTimeDiscipline;
use App\Models\QualifyingExcludedDiscipline;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * QualifyingExcludedDisciplineController
 *
 * Verwaltet, welche Bewerbe bei ÖSTM & ÖM nicht ausgetragen werden (z.B.
 * 25m-Bewerbe, 800m/1500m Freistil) und daher von der automatischen
 * Richtzeiten-Berechnung ausgenommen sind (Modul "Richtzeiten ÖSTM & ÖM",
 * Ergänzung zu Phase 2). Gilt global, nicht pro Richtzeitenliste/Jahr.
 * Nur für Admins zugänglich.
 */
class QualifyingExcludedDisciplineController extends Controller
{
    public function index(): View
    {
        $this->authorizeAdmin();

        $disciplines = BaseTimeDiscipline::where('relay_count', 1)
            ->with(['strokeType', 'qualifyingExclusion'])
            ->get()
            ->sortBy([
                fn (BaseTimeDiscipline $d) => $d->strokeType?->name_de,
                fn (BaseTimeDiscipline $d) => $d->distance,
            ]);

        return view('qualifying-excluded-disciplines.index', compact('disciplines'));
    }

    public function store(BaseTimeDiscipline $discipline): RedirectResponse
    {
        $this->authorizeAdmin();

        QualifyingExcludedDiscipline::firstOrCreate(['base_time_discipline_id' => $discipline->id]);

        return redirect()
            ->route('qualifying-excluded-disciplines.index')
            ->with('success', "\"$discipline->display_name\" von der Richtzeiten-Berechnung ausgeschlossen.");
    }

    public function destroy(BaseTimeDiscipline $discipline): RedirectResponse
    {
        $this->authorizeAdmin();

        QualifyingExcludedDiscipline::where('base_time_discipline_id', $discipline->id)->delete();

        return redirect()
            ->route('qualifying-excluded-disciplines.index')
            ->with('success', "\"$discipline->display_name\" wieder für die Richtzeiten-Berechnung zugelassen.");
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->is_admin, 403, 'Nur für Administratoren.');
    }
}
