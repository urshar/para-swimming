<?php

namespace App\Http\Controllers;

use App\Services\RecordImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

/**
 * RecordImportController
 *
 * Import-Flow für LENEX Rekord-Dateien:
 *   GET  /records/import          → showForm()   — Upload-Formular
 *   POST /records/import/preview  → preview()    — Vorschau + Bestätigung
 *   POST /records/import/run      → run()        — Import durchführen
 */
class RecordImportController extends Controller
{
    public function __construct(
        private readonly RecordImportService $importService
    ) {}

    public function showForm(): View
    {
        return view('records.import');
    }

    /**
     * Datei hochladen, analysieren und Vorschau anzeigen.
     * Unbekannte Clubs, Athleten und Rekorde mit ungeklärter Nationalität
     * werden zur manuellen Bestätigung angezeigt.
     */
    public function preview(Request $request): View|RedirectResponse
    {
        $request->validate([
            'lenex_file' => 'required|file|extensions:lxf,xml|max:20480',
        ]);

        $file = $request->file('lenex_file');
        $path = $file->storeAs('record-imports', uniqid('rec_').'.'.$file->getClientOriginalExtension(), 'local');

        try {
            $preview = $this->importService->preview(Storage::disk('local')->path($path));
        } catch (Throwable $e) {
            return redirect()->route('records.import')
                ->withErrors(['lenex_file' => 'Datei konnte nicht gelesen werden: '.$e->getMessage()]);
        }

        Session::put('record_import_path', $path);

        return view('records.import-preview', [
            'preview' => $preview,
            'fileName' => $file->getClientOriginalName(),
        ]);
    }

    /**
     * Import nach Bestätigung durchführen.
     * Nimmt die Entscheidungen für unbekannte Clubs/Athleten,
     * regionale Rekorde und ausstehende (Nationalität unklar) Rekorde entgegen.
     */
    public function run(Request $request): RedirectResponse
    {
        $path = Session::get('record_import_path');
        if (! $path) {
            return redirect()->route('records.import')
                ->withErrors(['lenex_file' => 'Session abgelaufen. Bitte Datei erneut hochladen.']);
        }

        $approvedClubs = $request->input('clubs', []);
        $approvedAthletes = $request->input('athletes', []);
        $newClubData = $request->input('new_clubs', []);
        $newAthleteData = $request->input('new_athletes', []);
        $approvedRegional = $request->input('regional', []);
        $approvedPending = $request->input('pending', []);   // ['pending_key' => 'import'|'skip']

        try {
            $result = $this->importService->import(
                Storage::disk('local')->path($path),
                $approvedClubs,
                $approvedAthletes,
                $newClubData,
                $newAthleteData,
                $approvedRegional,
                $approvedPending,
            );
        } catch (Throwable $e) {
            return redirect()->route('records.import')
                ->withErrors(['lenex_file' => 'Import fehlgeschlagen: '.$e->getMessage()]);
        }

        Session::forget('record_import_path');

        $msg = "{$result['imported']} Rekord(e) importiert, {$result['skipped']} übersprungen";
        if (($result['regional_auto'] ?? 0) > 0) {
            $msg .= ", {$result['regional_auto']} Regionalrekord(e) automatisch gesetzt";
        }

        return redirect()
            ->route('records.index')
            ->with('success', $msg.'.');
    }
}
