<?php

namespace App\Http\Controllers;

use App\Models\Meet;
use App\Services\WorldAquaticsPointsService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;

class WorldAquaticsPointsController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly WorldAquaticsPointsService $pointsService,
    ) {}

    /** Berechnet die World-Aquatics-Punkte aller Results eines Meets neu. */
    public function recalculate(Meet $meet): RedirectResponse
    {
        $this->authorize('manageEntries', $meet);

        $summary = $this->pointsService->recalculateForMeet($meet);

        $message = "{$summary['updated']} Punktzahl(en) aktualisiert.";
        if ($summary['skipped'] > 0) {
            $message .= " {$summary['skipped']} Ergebnis(se) übersprungen (".
                implode(', ', array_map(
                    fn ($reason, $count) => "{$count}× $reason",
                    array_keys($summary['skipped_reasons']),
                    $summary['skipped_reasons']
                )).').';
        }

        return back()->with('success', $message);
    }
}
