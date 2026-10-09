<?php

namespace App\Http\Controllers;

use App\Models\Classifier;
use App\Services\TeamManagerImportService;
use App\Services\TeamManagerSource;
use App\Support\TeamManagerImportPreview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/**
 * Einmalige Übernahme von Vereinen und Athleten aus dem Splash Team Manager:
 *
 *   GET  team-manager-import          → showForm()
 *   POST team-manager-import          → upload()   — speichert die Datei, schreibt nichts in die Datenbank
 *   GET  team-manager-import/preview  → preview()  — liest die Datei erneut (nach dem Anlegen von Klassifizierern)
 *   POST team-manager-import/run      → run()
 *
 * In der Session liegt nur der Dateipfad.
 */
class TeamManagerImportController extends Controller
{
    /** Auswahlwert für "diesen Namen keinem Klassifizierer zuordnen". */
    public const string SKIP_CLASSIFIER = 'NONE';

    private const string SESSION_KEY = 'team_manager_import';

    private const string STORAGE_DIRECTORY = 'team-manager-imports';

    public function __construct(
        private readonly TeamManagerSource $source,
        private readonly TeamManagerImportService $importService,
    ) {}

    public function showForm(): View
    {
        return view('team-manager-import.form');
    }

    public function upload(Request $request): RedirectResponse
    {
        $request->validate([
            'team_file' => 'required|file|extensions:mdb,accdb|max:51200',
        ]);

        $file = $request->file('team_file');
        $this->deleteStoredFile();

        $path = $file->storeAs(
            self::STORAGE_DIRECTORY,
            uniqid('team_').'.'.strtolower($file->getClientOriginalExtension()),
            'local'
        );

        Session::put(self::SESSION_KEY, ['path' => $path]);

        return redirect()->route('team-manager-import.preview');
    }

    public function preview(): View|RedirectResponse
    {
        try {
            $preview = $this->readPreview();
        } catch (Throwable $e) {
            $this->deleteStoredFile();

            return redirect()
                ->route('team-manager-import')
                ->withErrors(['team_file' => $e->getMessage()]);
        }

        return view('team-manager-import.preview', [
            'preview' => $preview,
            'classifiers' => Classifier::query()->orderBy('last_name')->orderBy('first_name')->get(),
            'skipValue' => self::SKIP_CLASSIFIER,
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        try {
            $preview = $this->readPreview();
        } catch (Throwable $e) {
            $this->deleteStoredFile();

            return redirect()
                ->route('team-manager-import')
                ->withErrors(['team_file' => $e->getMessage()]);
        }

        $rules = [];
        foreach (array_keys($preview->unknownClassifiers) as $key) {
            $rules['classifier_map.'.md5($key)] = [
                'required',
                function (string $attribute, mixed $value, callable $fail) {
                    if ($value !== self::SKIP_CLASSIFIER && ! Classifier::query()->whereKey($value)->exists()) {
                        $fail('Unbekannter Klassifizierer.');
                    }
                },
            ];
        }

        $validated = $request->validate($rules, [
            'classifier_map.*.required' => 'Bitte zuordnen oder "Nicht zuordnen" wählen.',
        ]);

        $assignments = [];
        foreach (array_keys($preview->unknownClassifiers) as $key) {
            $value = $validated['classifier_map'][md5($key)];
            $assignments[$key] = $value === self::SKIP_CLASSIFIER ? null : (int) $value;
        }

        try {
            $counts = $this->importService->import($preview, $assignments);
        } catch (Throwable $e) {
            return redirect()
                ->route('team-manager-import.preview')
                ->with('error', 'Der Import ist fehlgeschlagen, es wurde nichts übernommen: '.$e->getMessage());
        }

        $this->deleteStoredFile();

        return redirect()
            ->route('athletes.index')
            ->with('success', sprintf(
                'Team Manager übernommen: %d Vereine neu, %d aktualisiert; %d Athleten neu, %d aktualisiert; %d Klassifizierungen.',
                $counts['clubs_created'],
                $counts['clubs_updated'],
                $counts['athletes_created'],
                $counts['athletes_updated'],
                $counts['classifications'],
            ));
    }

    /** @throws Throwable wenn keine Datei hochgeladen ist oder sie sich nicht lesen lässt */
    private function readPreview(): TeamManagerImportPreview
    {
        $path = Session::get(self::SESSION_KEY)['path'] ?? null;

        if ($path === null || ! Storage::disk('local')->exists($path)) {
            throw new RuntimeException('Keine Datei vorhanden. Bitte die Team-Manager-Datei hochladen.');
        }

        return $this->importService->preview($this->source->read(Storage::disk('local')->path($path)));
    }

    private function deleteStoredFile(): void
    {
        $path = Session::get(self::SESSION_KEY)['path'] ?? null;

        if ($path !== null) {
            Storage::disk('local')->delete($path);
        }

        Session::forget(self::SESSION_KEY);
    }
}
