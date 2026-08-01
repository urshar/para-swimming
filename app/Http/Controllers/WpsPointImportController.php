<?php

namespace App\Http\Controllers;

use App\Services\WpsParameterImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

/**
 * Import-Flow für die WPS-Point-Score-Datei:
 *   GET  /wps/import          → showForm()  — Upload-Formular
 *   POST /wps/import/preview  → preview()   — Vorschau und Validierung, schreibt nichts
 *   POST /wps/import/run      → run()       — Import durchführen
 *
 * Der Preview-Schritt ist verbindlich: ein Import darf nie ohne vorherige Anzeige der
 * Validierungsergebnisse laufen. Aufbau bewusst identisch zum BaseTimeImportController.
 *
 * In der Session liegen nur der Dateipfad und die Versionsangaben — keine Eloquent-Modelle.
 */
class WpsPointImportController extends Controller
{
    private const string SESSION_KEY = 'wps_point_import';

    private const string STORAGE_DIRECTORY = 'wps-point-imports';

    public function __construct(
        private readonly WpsParameterImportService $importService
    ) {}

    public function showForm(): View
    {
        return view('wps.import.form');
    }

    public function preview(Request $request): View|RedirectResponse
    {
        $validated = $request->validate([
            'wps_file' => 'required|file|extensions:xlsx|max:20480',
            'label' => 'required|string|max:100',
            'year' => 'required|integer|min:1990|max:2100',
            'version' => 'nullable|string|max:20',
            'source' => 'nullable|string|max:255',
            'valid_from' => 'required|date',
            'valid_until' => 'nullable|date|after:valid_from',
        ]);

        $file = $request->file('wps_file');

        $path = $file->storeAs(
            self::STORAGE_DIRECTORY,
            uniqid('wps_').'.'.$file->getClientOriginalExtension(),
            'local'
        );

        try {
            $preview = $this->importService->parse(Storage::disk('local')->path($path));
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);

            return redirect()->route('wps.import')
                ->withInput()
                ->withErrors(['wps_file' => 'Datei konnte nicht gelesen werden: '.$e->getMessage()]);
        }

        Session::put(self::SESSION_KEY, [
            'path' => $path,
            'version' => [
                'label' => $validated['label'],
                'year' => (int) $validated['year'],
                'version' => $validated['version'] ?? null,
                'source' => $validated['source'] ?? null,
                'valid_from' => $validated['valid_from'],
                'valid_until' => $validated['valid_until'] ?? null,
            ],
        ]);

        return view('wps.import.preview', [
            'preview' => $preview,
            'fileName' => $file->getClientOriginalName(),
            'version' => Session::get(self::SESSION_KEY)['version'],
        ]);
    }

    public function run(): RedirectResponse
    {
        $importData = Session::get(self::SESSION_KEY);

        if (! $importData) {
            return redirect()->route('wps.import')
                ->withErrors(['wps_file' => 'Session abgelaufen. Bitte Datei erneut hochladen.']);
        }

        try {
            $preview = $this->importService->parse(
                Storage::disk('local')->path($importData['path'])
            );

            $result = $this->importService->import($preview, $importData['version']);
        } catch (Throwable $e) {
            return redirect()->route('wps.import')
                ->withErrors(['wps_file' => 'Import fehlgeschlagen: '.$e->getMessage()]);
        }

        Session::forget(self::SESSION_KEY);
        Storage::disk('local')->delete($importData['path']);

        return redirect()
            ->route('wps.versions.show', $result['version_id'])
            ->with('success', "{$result['parameters']} Parametersätze importiert.");
    }

    /** Bricht einen begonnenen Import ab und räumt die hochgeladene Datei weg. */
    public function cancel(): RedirectResponse
    {
        $importData = Session::pull(self::SESSION_KEY);

        if ($importData !== null) {
            Storage::disk('local')->delete($importData['path']);
        }

        return redirect()->route('wps.versions.index')
            ->with('success', 'Import abgebrochen.');
    }
}
