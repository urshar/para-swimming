<?php

namespace App\Http\Controllers;

use App\Jobs\CalculateWpsPointsJob;
use App\Models\Meet;
use App\Models\Result;
use App\Models\WpsPointVersion;
use App\Services\WpsPointCalculationService;
use App\Services\WpsPointVersionResolver;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Löst die WPS-Punkteberechnung für einen Wettkampf aus.
 *
 * Absicherung über EntryPolicy::manageEntries — dieselbe Regel wie beim bestehenden
 * World-Aquatics-Recalculate, damit für beide Punktesysteme dieselben Personen zuständig sind.
 */
class WpsPointCalculationController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly WpsPointCalculationService $calculationService,
        private readonly WpsPointVersionResolver $versionResolver,
    ) {}

    public function recalculate(Request $request, Meet $meet): RedirectResponse
    {
        $this->authorize('manageEntries', $meet);

        if (! $meet->hasWpsPointsEnabled()) {
            return back()->withErrors([
                'wps' => 'Für diesen Wettkampf ist das Punktesystem WPS nicht aktiviert. '.
                    'Es kann im Wettkampf-Formular unter "Punkteberechnung" gesetzt werden.',
            ]);
        }

        $validated = $request->validate([
            'wps_point_version_id' => 'nullable|integer|exists:wps_point_versions,id',
            'only_missing' => 'nullable|boolean',
        ]);

        $version = ! empty($validated['wps_point_version_id'])
            ? WpsPointVersion::find($validated['wps_point_version_id'])
            : null;

        if ($this->versionResolver->resolveForMeet($meet, $version) === null) {
            return back()->withErrors([
                'wps' => 'Für das Wettkampfdatum ist keine gültige WPS-Version hinterlegt. '.
                    'Bitte eine Version importieren oder dem Wettkampf ausdrücklich zuweisen.',
            ]);
        }

        $onlyMissing = $request->boolean('only_missing');

        // Große Wettkämpfe laufen im Hintergrund, damit der Request nicht in ein Timeout
        // läuft. Kleine synchron, damit die Zusammenfassung sofort erscheint.
        if ($this->resultCount($meet) > (int) config('wps.sync_threshold')) {
            CalculateWpsPointsJob::dispatch($meet->id, $version?->id, $onlyMissing);

            return back()->with('success',
                'Die WPS-Punkteberechnung läuft im Hintergrund. '.
                'Die Ergebnisse erscheinen, sobald sie abgeschlossen ist.');
        }

        $summary = $this->calculationService->recalculateForMeet($meet, $version, $onlyMissing);

        return back()->with('success', $this->summaryMessage($summary, $version));
    }

    private function resultCount(Meet $meet): int
    {
        return Result::where('meet_id', $meet->id)->count();
    }

    /**
     * Baut die Rückmeldung nach dem Muster des World-Aquatics-Recalculate: Anzahl der
     * aktualisierten Ergebnisse, dazu die häufigsten Gründe für übersprungene.
     *
     * @param  array{updated: int, skipped: int, skipped_reasons: array<string, int>, skipped_results: array<int, string>}  $summary
     */
    private function summaryMessage(array $summary, ?WpsPointVersion $version): string
    {
        $message = "{$summary['updated']} WPS-Punktzahl(en) aktualisiert".
            ($version instanceof WpsPointVersion
                ? " (Version: $version->label)"
                : ' (automatisch ermittelte Version)').'.';

        if ($summary['skipped'] > 0) {
            arsort($summary['skipped_reasons']);
            $shown = array_slice($summary['skipped_reasons'], 0, 15, preserve_keys: true);
            $remaining = count($summary['skipped_reasons']) - count($shown);

            $message .= " {$summary['skipped']} Ergebnis(se) übersprungen (".
                implode(', ', array_map(
                    static fn (string $reason, int $count): string => "{$count}× $reason",
                    array_keys($shown),
                    $shown
                )).
                ($remaining > 0 ? " sowie $remaining weitere(r) Grund/Gründe" : '').').';

            session()->flash('wps_skipped_results', $summary['skipped_results']);
        }

        return $message;
    }
}
