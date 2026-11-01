<?php

namespace App\Http\Controllers;

use App\Models\WpsPointVersion;
use App\Services\WpsParameterImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidationValidator;
use Illuminate\View\View;
use Throwable;

/**
 * Import-Flow für die WPS-Point-Score-Datei:
 *   GET  /wps/import          → showForm()     — Upload-Formular (nur die Datei)
 *   POST /wps/import/preview  → preview()      — Vorschau und Validierung, schreibt nichts
 *   GET  /wps/import/preview  → showPreview()  — dieselbe Vorschau erneut, z. B. nach einem Fehler beim Import
 *   POST /wps/import/run      → run()          — Versionsdaten prüfen, Import durchführen
 *
 * Der Preview-Schritt ist verbindlich: ein Import darf nie ohne vorherige Anzeige der
 * Validierungsergebnisse laufen. Aufbau bewusst identisch zum BaseTimeImportController.
 *
 * Die allgemeinen Versionsdaten (Bezeichnung, Jahr, Gültig ab …) werden erst in der Vorschau erfasst, vorbelegt
 * aus den Angaben der Datei (WpsImportPreview::versionSuggestion()), und mit dem Import abgeschickt.
 *
 * In der Session liegen nur Dateipfad und Dateiname — keine Eloquent-Modelle.
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
        $request->validate([
            'wps_file' => 'required|file|extensions:xlsx,txt|max:20480',
        ]);

        $file = $request->file('wps_file');

        // Die Endung muss erhalten bleiben (kleingeschrieben): Der Service erkennt daran das
        // Format — auch beim erneuten Lesen in showPreview() und run().
        $path = $file->storeAs(
            self::STORAGE_DIRECTORY,
            uniqid('wps_').'.'.strtolower($file->getClientOriginalExtension()),
            'local'
        );

        try {
            $preview = $this->importService->parse(Storage::disk('local')->path($path));
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);

            return redirect()->route('wps.import')
                ->withErrors(['wps_file' => 'Datei konnte nicht gelesen werden: '.$e->getMessage()]);
        }

        // Eine vorher begonnene, nicht abgeschlossene Vorschau hinterließe sonst ihre Datei.
        $previous = Session::get(self::SESSION_KEY);

        if ($previous !== null && $previous['path'] !== $path) {
            Storage::disk('local')->delete($previous['path']);
        }

        Session::put(self::SESSION_KEY, [
            'path' => $path,
            'file_name' => $file->getClientOriginalName(),
        ]);

        return view('wps.import.preview', [
            'preview' => $preview,
            'fileName' => $file->getClientOriginalName(),
            'suggestion' => $preview->versionSuggestion(),
        ]);
    }

    /**
     * Zeigt die Vorschau der bereits hochgeladenen Datei erneut an — Rücksprungziel, wenn die Versionsdaten beim
     * Import nicht passen. Die Datei wird dafür neu gelesen; in der Session liegt nur ihr Pfad.
     */
    public function showPreview(): View|RedirectResponse
    {
        $importData = Session::get(self::SESSION_KEY);

        if (! $importData) {
            return $this->sessionExpired();
        }

        try {
            $preview = $this->importService->parse(Storage::disk('local')->path($importData['path']));
        } catch (Throwable $e) {
            return redirect()->route('wps.import')
                ->withErrors(['wps_file' => 'Datei konnte nicht gelesen werden: '.$e->getMessage()]);
        }

        return view('wps.import.preview', [
            'preview' => $preview,
            'fileName' => $importData['file_name'],
            'suggestion' => $preview->versionSuggestion(),
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $importData = Session::get(self::SESSION_KEY);

        if (! $importData) {
            return $this->sessionExpired();
        }

        // Fehler führen zurück in die Vorschau, nicht zum Upload — die Datei bleibt dafür liegen.
        $validator = Validator::make($request->all(), [
            'label' => 'required|string|max:100',
            'year' => 'required|integer|min:1990|max:2100',
            'version' => 'nullable|string|max:20',
            'source' => 'nullable|string|max:255',
            'valid_from' => 'required|date',
            'valid_until' => 'nullable|date|after:valid_from',
        ]);

        $validator->after(static function (ValidationValidator $check): void {
            $data = $check->getData();

            if ($check->errors()->has('year')) {
                return;
            }

            $exists = WpsPointVersion::query()
                ->where('year', $data['year'])
                ->where('version', $data['version'] ?? null)
                ->exists();

            if ($exists) {
                $check->errors()->add('version',
                    "Für das Jahr {$data['year']} existiert bereits die Version \"".($data['version'] ?? '—').'". '.
                    'Bitte eine abweichende Versionsbezeichnung wählen oder die bestehende Version zuerst '.
                    'archivieren bzw. löschen.');
            }
        });

        if ($validator->fails()) {
            return redirect()->route('wps.import.preview.show')
                ->withErrors($validator)
                ->withInput();
        }

        $validated = $validator->validated();

        try {
            $preview = $this->importService->parse(
                Storage::disk('local')->path($importData['path'])
            );

            $result = $this->importService->import($preview, [
                'label' => $validated['label'],
                'year' => (int) $validated['year'],
                'version' => $validated['version'] ?? null,
                'source' => $validated['source'] ?? null,
                'valid_from' => $validated['valid_from'],
                'valid_until' => $validated['valid_until'] ?? null,
            ]);
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

    private function sessionExpired(): RedirectResponse
    {
        return redirect()->route('wps.import')
            ->withErrors(['wps_file' => 'Session abgelaufen. Bitte Datei erneut hochladen.']);
    }
}
