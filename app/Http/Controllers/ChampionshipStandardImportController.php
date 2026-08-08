<?php

namespace App\Http\Controllers;

use App\Models\Championship;
use App\Services\ChampionshipStandardImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

/**
 * Import-Flow für die WPS-Normdatei einer Meisterschaft (Spec §9.2):
 *
 *   GET  championships/{championship}/import          → showForm()
 *   POST championships/{championship}/import/preview  → preview()  — schreibt nichts
 *   POST championships/{championship}/import/run      → run()
 *
 * Der Vorschauschritt ist verbindlich; ein Import läuft nie ohne vorherige Anzeige der
 * Validierungsergebnisse. Aufbau bewusst identisch zum WpsPointImportController.
 *
 * Importiert wird immer in eine bereits angelegte Meisterschaft: Die Datei enthält weder
 * Name noch Art noch verlässlich den Qualifikationszeitraum.
 *
 * In der Session liegen nur Dateipfad und Meisterschafts-ID — keine Eloquent-Modelle.
 */
class ChampionshipStandardImportController extends Controller
{
    private const string SESSION_KEY = 'championship_standard_import';

    private const string STORAGE_DIRECTORY = 'championship-standard-imports';

    public function __construct(
        private readonly ChampionshipStandardImportService $importService
    ) {}

    public function showForm(Championship $championship): View
    {
        return view('championships.import.form', ['championship' => $championship]);
    }

    public function preview(Request $request, Championship $championship): View|RedirectResponse
    {
        $request->validate([
            'standards_file' => 'required|file|extensions:xlsx,xls|max:20480',
        ]);

        $file = $request->file('standards_file');

        $path = $file->storeAs(
            self::STORAGE_DIRECTORY,
            uniqid('championship_').'.'.$file->getClientOriginalExtension(),
            'local'
        );

        try {
            $preview = $this->importService->parse(Storage::disk('local')->path($path), $championship);
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);

            return redirect()
                ->route('championships.import', $championship)
                ->withErrors(['standards_file' => 'Datei konnte nicht gelesen werden: '.$e->getMessage()]);
        }

        Session::put(self::SESSION_KEY, [
            'path' => $path,
            'championship_id' => $championship->getKey(),
        ]);

        return view('championships.import.preview', [
            'championship' => $championship,
            'preview' => $preview,
        ]);
    }

    public function run(Championship $championship): RedirectResponse
    {
        $session = Session::get(self::SESSION_KEY);

        if (! is_array($session) || ($session['championship_id'] ?? null) !== $championship->getKey()) {
            return redirect()
                ->route('championships.import', $championship)
                ->withErrors(['standards_file' => 'Die Vorschau ist abgelaufen. Bitte die Datei erneut hochladen.']);
        }

        $path = $session['path'];

        if (! Storage::disk('local')->exists($path)) {
            Session::forget(self::SESSION_KEY);

            return redirect()
                ->route('championships.import', $championship)
                ->withErrors(['standards_file' => 'Die hochgeladene Datei ist nicht mehr vorhanden. Bitte erneut hochladen.']);
        }

        try {
            // Bewusst erneut geparst statt die Vorschau in der Session zu halten: Ein
            // serialisiertes Vorschauobjekt könnte nach einem Deployment nicht mehr zur
            // Klasse passen, und die Datei ist die verlässlichere Quelle.
            $preview = $this->importService->parse(Storage::disk('local')->path($path), $championship);
            $ergebnis = $this->importService->import($championship, $preview);
        } catch (Throwable $e) {
            $this->cleanUp($path);

            return redirect()
                ->route('championships.import', $championship)
                ->withErrors(['standards_file' => $e->getMessage()]);
        }

        // Bewusst kein finally: Mit einem return im catch-Zweig ist für den Leser (und für
        // die statische Analyse) nicht mehr erkennbar, dass $ergebnis danach gesetzt ist.
        $this->cleanUp($path);

        return redirect()
            ->route('championships.show', $championship)
            ->with('success', sprintf(
                '%d Norm(en) neu angelegt, %d aktualisiert. ÖBSV-Prozentsätze und -Zeiten '
                .'blieben unverändert.',
                $ergebnis['created'],
                $ergebnis['updated'],
            ));
    }

    /** Hochgeladene Datei und Sitzungsdaten entfernen — nach Erfolg wie nach Fehlschlag. */
    private function cleanUp(string $path): void
    {
        Storage::disk('local')->delete($path);
        Session::forget(self::SESSION_KEY);
    }
}
