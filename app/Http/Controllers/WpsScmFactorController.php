<?php

namespace App\Http\Controllers;

use App\Models\StrokeType;
use App\Models\WpsScmConversionFactor;
use App\Services\WpsScmConversionService;
use App\Services\WpsScmFactorCalibrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Verwaltung der Umrechnungsfaktoren Kurzbahn → Langbahn.
 *
 * Nur für Administratoren (RequireAdmin). Die Faktoren bestimmen mit, welche Punkte
 * österreichische Athletinnen und Athleten erhalten — in einem Land, in dem ausschließlich
 * Kurzbahn geschwommen wird, betrifft das praktisch alle Ergebnisse.
 */
class WpsScmFactorController extends Controller
{
    public function __construct(
        private readonly WpsScmConversionService $conversionService,
        private readonly WpsScmFactorCalibrationService $calibrationService,
    ) {}

    public function index(): View
    {
        return view('wps.factors.index', [
            'factors' => WpsScmConversionFactor::with('strokeType')
                ->orderBy('stroke_type_id')
                ->orderBy('distance')
                ->orderBy('sport_class')
                ->get(),
            'strokeTypes' => StrokeType::orderBy('id')->get(),
        ]);
    }

    /** Faktorenbericht: angesetzte gegen beobachtete Werte (Spec §9.7). */
    public function report(): View
    {
        return view('wps.factors.report', [
            'rows' => $this->calibrationService->report($this->conversionService),
            'minSampleSize' => $this->calibrationService->minSampleSize(),
            'windowMonths' => $this->calibrationService->windowMonths(),
            'plausibleRange' => $this->calibrationService->plausibleRange(),
        ]);
    }

    /** Ermittelt die Faktoren aus den eigenen Ergebnissen neu. */
    public function calibrate(): RedirectResponse
    {
        $summary = $this->calibrationService->calibrate();

        $meldung = sprintf(
            '%d Faktor(en) neu angelegt, %d aktualisiert, %d übersprungen '
            .'(zu wenige Athleten oder manuell gesetzt).',
            $summary['created'],
            $summary['updated'],
            $summary['skipped'],
        );

        if ($summary['rejected_pairs'] > 0) {
            $meldung .= sprintf(
                ' %d Vergleichspaar(e) als unplausibel verworfen — siehe Spalte "verworfen".',
                $summary['rejected_pairs'],
            );
        }

        return redirect()->route('wps.factors.report')->with('success', $meldung);
    }

    public function update(Request $request, WpsScmConversionFactor $factor): RedirectResponse
    {
        $validated = $request->validate([
            'factor' => 'required|numeric|min:0.5|max:1.5',
            'notes' => 'nullable|string|max:1000',
            'active' => 'nullable|boolean',
        ]);

        $factor->update([
            'factor' => $validated['factor'],
            'notes' => $validated['notes'] ?? null,
            'active' => $request->boolean('active'),
            // Eine Änderung von Hand bleibt als solche erkennbar und wird vom
            // Kalibrierungslauf nicht überschrieben.
            'source' => WpsScmConversionFactor::SOURCE_MANUAL,
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
        ]);

        return redirect()->route('wps.factors.index')
            ->with('success', 'Faktor gespeichert. Betroffene Ergebnisse müssen neu berechnet werden.');
    }

    public function destroy(WpsScmConversionFactor $factor): RedirectResponse
    {
        $factor->delete();

        return redirect()->route('wps.factors.index')
            ->with('success', 'Faktor gelöscht.');
    }
}
