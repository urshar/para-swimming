<?php

namespace App\Http\Controllers;

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
 *   GET  /base-times/import          → showForm()  — Upload-Formular (Datei + Versionsdaten)
 *   POST /base-times/import/preview  → preview()   — Vorschau (erkannte Kategorien/Bewerbe/Hinweise)
 *   POST /base-times/import/run      → run()       — Import durchführen
 */
class BaseTimeImportController extends Controller
{
    public function __construct(
        private readonly BaseTimeImportService $importService
    ) {}

    public function showForm(): View
    {
        return view('base-times.import');
    }

    public function preview(Request $request): View|RedirectResponse
    {
        $validated = $request->validate([
            'base_time_file' => 'required|file|extensions:xlsx|max:20480',
            'label' => 'required|string|max:100',
            'valid_from' => 'required|date',
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

        Session::put('base_time_import', [
            'path' => $path,
            'version' => [
                'label' => $validated['label'],
                'valid_from' => $validated['valid_from'],
                'valid_until' => $validated['valid_until'] ?? null,
            ],
        ]);

        return view('base-times.import-preview', [
            'parsed' => $parsed,
            'fileName' => $file->getClientOriginalName(),
        ]);
    }

    public function run(): RedirectResponse
    {
        $importData = Session::get('base_time_import');

        if (! $importData) {
            return redirect()->route('base-times.import')
                ->withErrors(['base_time_file' => 'Session abgelaufen. Bitte Datei erneut hochladen.']);
        }

        try {
            $result = $this->importService->import(
                Storage::disk('local')->path($importData['path']),
                $importData['version'],
            );
        } catch (Throwable $e) {
            return redirect()->route('base-times.import')
                ->withErrors(['base_time_file' => 'Import fehlgeschlagen: '.$e->getMessage()]);
        }

        Session::forget('base_time_import');
        Storage::disk('local')->delete($importData['path']);

        $message = "{$result['base_times']} Basiswerte importiert ".
            "({$result['categories']} Kategorien, {$result['disciplines']} Bewerbe, ".
            "{$result['sport_classes']} Sportklassen, {$result['derivation_rules']} Herleitungs-Regeln).";

        // TODO: auf base-times.index umstellen, sobald die CRUD-Übersicht (Schritt 9) existiert.
        return redirect()
            ->route('base-times.import')
            ->with('success', $message);
    }
}
