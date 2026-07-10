<?php

namespace App\Http\Controllers;

use App\Models\BaseTimeVersion;
use App\Services\BaseTimeImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

/**
 * BaseTimeImportController
 *
 * Import-Flow für die World-Aquatics-Basiswert-Excel-Datei:
 *   GET  /base-times/import          → showForm()  — Upload-Formular (Datei + Ziel-Version)
 *   POST /base-times/import/preview  → preview()   — Vorschau (erkannte Kategorien/Bewerbe/Hinweise)
 *   POST /base-times/import/run      → run()       — Import durchführen
 *
 * Ziel-Version: entweder eine bestehende Version auswählen (kein erneutes Label/Zeitraum nötig,
 * z.B. wenn die Version zuvor separat angelegt wurde) oder "Neue Version anlegen".
 */
class BaseTimeImportController extends Controller
{
    public function __construct(
        private readonly BaseTimeImportService $importService
    ) {}

    public function showForm(Request $request): View
    {
        return view('base-times.import', [
            'versions' => BaseTimeVersion::orderByDesc('valid_from')->get(),
            'selectedVersionId' => $request->query('version'),
        ]);
    }

    public function preview(Request $request): View|RedirectResponse
    {
        $validated = $request->validate([
            'base_time_file' => 'required|file|extensions:xlsx|max:20480',
            'version_id' => 'nullable|integer|exists:base_time_versions,id',
            'label' => 'required_without:version_id|nullable|string|max:100',
            'valid_from' => 'required_without:version_id|nullable|date',
            'valid_until' => 'nullable|date|after:valid_from',
        ]);

        $file = $request->file('base_time_file');
        $path = $file->storeAs(
            'base-time-imports',
            uniqid('bt_').'.'.$file->getClientOriginalExtension(),
            'local'
        );

        try {
            $parsed = $this->importService->parse(Storage::disk('local')->path($path));
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);

            return redirect()->route('base-times.import')
                ->withErrors(['base_time_file' => 'Datei konnte nicht gelesen werden: '.$e->getMessage()]);
        }

        $sessionData = ['path' => $path];
        if (! empty($validated['version_id'])) {
            $sessionData['version_id'] = (int) $validated['version_id'];
        } else {
            $sessionData['version'] = [
                'label' => $validated['label'],
                'valid_from' => $validated['valid_from'],
                'valid_until' => $validated['valid_until'] ?? null,
            ];
        }

        Session::put('base_time_import', $sessionData);

        return view('base-times.import-preview', [
            'parsed' => $parsed,
            'fileName' => $file->getClientOriginalName(),
            'targetVersion' => isset($sessionData['version_id'])
                ? BaseTimeVersion::find($sessionData['version_id'])
                : null,
        ]);
    }

    public function run(): RedirectResponse
    {
        $importData = Session::get('base_time_import');

        if (! $importData) {
            return redirect()->route('base-times.import')
                ->withErrors(['base_time_file' => 'Session abgelaufen. Bitte Datei erneut hochladen.']);
        }

        $fullPath = Storage::disk('local')->path($importData['path']);

        try {
            if (isset($importData['version_id'])) {
                $version = BaseTimeVersion::findOrFail($importData['version_id']);
                $result = $this->importService->importIntoExistingVersion($fullPath, $version);
            } else {
                $result = $this->importService->import($fullPath, $importData['version']);
            }
        } catch (Throwable $e) {
            return redirect()->route('base-times.import')
                ->withErrors(['base_time_file' => 'Import fehlgeschlagen: '.$e->getMessage()]);
        }

        Session::forget('base_time_import');
        Storage::disk('local')->delete($importData['path']);

        $message = "{$result['base_times']} Basiswerte importiert ".
            "({$result['categories']} Kategorien, {$result['disciplines']} Bewerbe, ".
            "{$result['sport_classes']} Sportklassen, {$result['derivation_rules']} Herleitungs-Regeln).";

        return redirect()
            ->route('base-times.categories.index', $result['version_id'])
            ->with('success', $message);
    }
}
