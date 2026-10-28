<?php

namespace App\Http\Controllers;

use App\Models\Athlete;
use App\Models\AthleteDuplicateDismissal;
use App\Services\AthleteDuplicateFinder;
use App\Support\ListUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Dublettenvorschläge (AthleteDuplicateFinder) mit Sprung in die Vorschau zum Zusammenführen und der Markierung
 * "kein Duplikat". Admin-only.
 */
class AthleteDuplicateController extends Controller
{
    public function __construct(
        private readonly AthleteDuplicateFinder $finder,
    ) {}

    /**
     * GET /athletes/duplicates
     */
    public function index(Request $request): View
    {
        $withDismissed = $request->boolean('dismissed');

        return view('athletes.duplicates', [
            'pairs' => $this->finder->find($withDismissed),
            'withDismissed' => $withDismissed,
        ]);
    }

    /**
     * POST /athletes/duplicates/dismissals — Paar als "kein Duplikat" markieren.
     */
    public function dismiss(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'athlete_id' => ['required', 'integer', 'exists:athletes,id'],
            'other_athlete_id' => ['required', 'integer', 'exists:athletes,id', 'different:athlete_id'],
        ]);

        $ids = [(int) $validated['athlete_id'], (int) $validated['other_athlete_id']];
        sort($ids);

        AthleteDuplicateDismissal::firstOrCreate(
            ['athlete_id' => $ids[0], 'other_athlete_id' => $ids[1]],
            ['dismissed_by' => $request->user()?->id],
        );

        return redirect()->to(ListUrl::to('athletes.duplicates'))
            ->with('success', $this->pairName($ids).' als "kein Duplikat" markiert.');
    }

    /**
     * DELETE /athletes/duplicates/dismissals/{dismissal} — Markierung zurücknehmen.
     */
    public function restore(AthleteDuplicateDismissal $dismissal): RedirectResponse
    {
        $name = $this->pairName([$dismissal->athlete_id, $dismissal->other_athlete_id]);
        $dismissal->delete();

        return redirect()->to(ListUrl::to('athletes.duplicates'))
            ->with('success', "Markierung für $name zurückgenommen.");
    }

    /**
     * @param  array{0: int, 1: int}  $ids
     */
    private function pairName(array $ids): string
    {
        return Athlete::whereKey($ids)->orderBy('id')->get()
            ->map(fn (Athlete $athlete) => "#$athlete->id $athlete->display_name")
            ->implode(' und ');
    }
}
