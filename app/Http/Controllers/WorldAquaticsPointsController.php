<?php

namespace App\Http\Controllers;

use App\Models\BaseTimeVersion;
use App\Models\Meet;
use App\Services\WorldAquaticsPointsService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WorldAquaticsPointsController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly WorldAquaticsPointsService $pointsService,
    ) {}

    /**
     * Berechnet die World-Aquatics-Punkte aller Results eines Meets neu.
     *
     * $version_id (optional): übersteuert die automatische Zuordnung nach Wettkampfdatum.
     * Leer/nicht gesetzt = automatische Zuordnung verwenden.
     */
    public function recalculate(Request $request, Meet $meet): RedirectResponse
    {
        $this->authorize('manageEntries', $meet);

        $validated = $request->validate([
            'version_id' => 'nullable|integer|exists:base_time_versions,id',
        ]);

        $version = ! empty($validated['version_id'])
            ? BaseTimeVersion::find($validated['version_id'])
            : null;

        $summary = $this->pointsService->recalculateForMeet($meet, $version);

        $message = "{$summary['updated']} Punktzahl(en) aktualisiert".
            ($version ? " (Basiswert-Version: $version->label)" : ' (automatisch ermittelte Basiswert-Version)').'.';

        if ($summary['skipped'] > 0) {
            arsort($summary['skipped_reasons']);
            $shown = array_slice($summary['skipped_reasons'], 0, 15, preserve_keys: true);
            $remaining = count($summary['skipped_reasons']) - count($shown);

            $message .= " {$summary['skipped']} Ergebnis(se) übersprungen (".
                implode(', ', array_map(
                    fn ($reason, $count) => "{$count}× $reason",
                    array_keys($shown),
                    $shown
                )).
                ($remaining > 0 ? " sowie $remaining weitere(r) Grund/Gründe" : '').').';

            session()->flash('points_skipped_results', $summary['skipped_results']);
        }

        return back()->with('success', $message);
    }
}
