<?php

namespace App\Http\Controllers;

use App\Models\Meet;
use App\Services\LenexParserService;
use App\Services\LenexResolverService;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class LenexImportController extends Controller
{
    public function __construct(
        private readonly LenexParserService $parser,
        private readonly LenexResolverService $resolver,
    ) {}

    public function showForm(): View
    {
        return view('lenex.import');
    }

    /**
     * Schritt 1b: Meet-Auswahl anzeigen.
     */
    public function confirmMeet(Request $request): RedirectResponse|View
    {
        $sessionKey = $request->input('session');
        $importData = Session::get($sessionKey);

        if (! $importData) {
            return redirect()->route('lenex.import')
                ->withErrors(['import' => 'Import-Session abgelaufen. Bitte Datei erneut hochladen.']);
        }

        $candidates = $this->findMeetCandidates($importData['meta']);

        return view('lenex.confirm-meet', [
            'importSession' => $sessionKey,
            'meta' => $importData['meta'],
            'type' => $importData['type'],
            'candidates' => $candidates,
        ]);
    }

    /**
     * Schritt 1c: Meet-Auswahl bestätigen und Import starten.
     * meet_id = bestehende Meet-ID, oder leer = neues Meet anlegen.
     */
    public function runImport(Request $request): RedirectResponse
    {
        $sessionKey = $request->input('import_session');
        $importData = Session::get($sessionKey);

        if (! $importData) {
            return redirect()->route('lenex.import')
                ->withErrors(['import' => 'Import-Session abgelaufen. Bitte Datei erneut hochladen.']);
        }

        $meetId = $request->input('meet_id'); // null = neues Meet
        $fullPath = Storage::disk('local')->path($importData['path']);

        try {
            $result = $this->parser->import($fullPath, $this->resolver, $meetId ?: null);

            // Gibt es unaufgelöste Clubs oder Athleten?
            if ($this->resolver->hasUnresolved()) {
                $newSessionKey = uniqid('lenex_import_', true);
                Session::put($newSessionKey, [
                    'path' => $importData['path'],
                    'force_meet_id' => $meetId ?: null,
                    'partial_result' => $result,
                    'unresolved_clubs' => $this->resolver->getUnresolvedClubs(),
                    'unresolved_athletes' => $this->resolver->getUnresolvedAthletes(),
                ]);

                Session::forget($sessionKey);

                return redirect()->route('lenex.import.review', ['session' => $newSessionKey]);
            }

            Session::forget($sessionKey);
            Storage::disk('local')->delete($importData['path']);

            return $this->redirectAfterImport($result);

        } catch (Exception $e) {
            return back()->withErrors([
                'import' => 'Import fehlgeschlagen: '.$e->getMessage(),
            ]);
        }
    }

    /**
     * Schritt 1: LENEX Datei hochladen, Typ erkennen.
     * Bei entries/results → Meet-Auswahl anzeigen (Schritt 1b).
     * Bei structure → direkt importieren.
     */
    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'lenex_file' => [
                'required', 'file', 'max:20480', function ($attr, $value, $fail) {
                    $ext = strtolower($value->getClientOriginalExtension());
                    if (! in_array($ext, ['lxf', 'lef', 'xml'])) {
                        $fail('Nur .lxf, .lef oder .xml Dateien sind erlaubt.');
                    }
                },
            ],
        ]);

        $path = $request->file('lenex_file')->store('lenex-imports', 'local');
        $fullPath = Storage::disk('local')->path($path);

        try {
            // Typ erkennen ohne zu importieren
            $type = $this->parser->detectTypeFromFile($fullPath);
            $meta = $this->parser->extractMeetMeta($fullPath);

            // Bei structure: direkt importieren — keine Meet-Auswahl nötig
            if ($type === 'structure') {
                $result = $this->parser->import($fullPath, $this->resolver);
                Storage::disk('local')->delete($path);

                return $this->redirectAfterImport($result);
            }

            // Bei entries/results: ähnliche Meets suchen und zur Auswahl anzeigen
            $candidates = $this->findMeetCandidates($meta);

            $sessionKey = uniqid('lenex_import_', true);
            Session::put($sessionKey, [
                'path' => $path,
                'type' => $type,
                'meta' => $meta,
            ]);

            return redirect()->route('lenex.import.confirm-meet', ['session' => $sessionKey])
                ->with('candidates', $candidates);

        } catch (Exception $e) {
            Storage::disk('local')->delete($path);

            return back()->withErrors([
                'lenex_file' => 'Import fehlgeschlagen: '.$e->getMessage(),
            ]);
        }
    }

    /**
     * Schritt 2: Review — unbekannte Clubs und Athleten anzeigen.
     */
    public function review(Request $request): RedirectResponse|View
    {
        $sessionKey = $request->input('session');
        $importData = Session::get($sessionKey);

        if (! $importData) {
            return redirect()->route('lenex.import')
                ->withErrors(['import' => 'Import-Session abgelaufen. Bitte Datei erneut hochladen.']);
        }

        return view('lenex.review', [
            'importSession' => $sessionKey,
            'unresolvedClubs' => $importData['unresolved_clubs'],
            'unresolvedAthletes' => $importData['unresolved_athletes'],
        ]);
    }

    /**
     * Schritt 3a: Clubs anlegen → Athleten der neu angelegten Clubs aus dem XML laden
     * und zur Review anzeigen. KEIN Import-Durchlauf hier.
     */
    public function resolveClubs(Request $request): RedirectResponse
    {
        $sessionKey = $request->input('import_session');
        $importData = Session::get($sessionKey);

        if (! $importData) {
            return redirect()->route('lenex.import')
                ->withErrors(['import' => 'Import-Session abgelaufen.']);
        }

        try {
            $newClubIds = []; // lenex_id → neue DB-Club-ID

            foreach ($request->input('clubs', []) as $clubData) {
                if (($clubData['action'] ?? 'skip') !== 'create') {
                    continue;
                }
                $club = $this->resolver->createClub([
                    'name' => $clubData['name'],
                    'short_name' => $clubData['short_name'] ?? null,
                    'code' => $clubData['code'] ?? null,
                    'nation_id' => $clubData['nation_id'],
                    'type' => $clubData['type'] ?? 'CLUB',
                    'cache_key' => $clubData['cache_key'] ?? null,
                    'regional_association' => $clubData['regional_association'] ?? null,
                ]);
                // cache_key ist der stabile Schlüssel (lenex_id oder "code:BSRO")
                // Er muss mit dem übereinstimmen der in resolveClub() berechnet wurde
                $cacheKey = $clubData['cache_key'] ?? '';
                if ($cacheKey) {
                    $newClubIds[$cacheKey] = $club->id;
                    $this->resolver->addToClubCache($cacheKey, $club->id);
                }
            }

            // Athleten der neu angelegten Clubs aus dem XML lesen
            $fullPath = Storage::disk('local')->path($importData['path']);
            $newAthletes = $this->parser->extractAthletesForClubs(
                $fullPath,
                array_keys($newClubIds),
                $newClubIds
            );

            // Session aktualisieren: bereits bekannte unresolved_athletes + neue
            $allUnresolvedAthletes = array_merge(
                $importData['unresolved_athletes'] ?? [],
                $newAthletes
            );

            $newSessionKey = uniqid('lenex_import_', true);
            Session::put($newSessionKey, [
                'path' => $importData['path'],
                'force_meet_id' => $importData['force_meet_id'] ?? null,
                'resolved_club_ids' => $newClubIds,
                'unresolved_clubs' => [],
                'unresolved_athletes' => $allUnresolvedAthletes,
            ]);
            Session::forget($sessionKey);

            if (empty($allUnresolvedAthletes)) {
                // Keine neuen Athleten — direkt finalen Import starten
                return $this->runFinalImport($newSessionKey, $newClubIds);
            }

            return redirect()->route('lenex.import.review', ['session' => $newSessionKey]);

        } catch (Exception $e) {
            return back()->withErrors([
                'resolve' => 'Fehler beim Anlegen der Vereine: '.$e->getMessage(),
            ]);
        }
    }

    /**
     * Schritt 3b: Athleten anlegen → finalen Import starten.
     * Kein weiterer Parser-Lauf für die Review — nur noch der finale Import.
     */
    public function resolveAthletes(Request $request): RedirectResponse
    {
        $sessionKey = $request->input('import_session');
        $importData = Session::get($sessionKey);

        if (! $importData) {
            return redirect()->route('lenex.import')
                ->withErrors(['import' => 'Import-Session abgelaufen.']);
        }

        try {
            // Neu angelegte Club-IDs aus der Session in den Cache laden
            foreach ($importData['resolved_club_ids'] ?? [] as $lenexId => $clubId) {
                $this->resolver->addToClubCache((string) $lenexId, $clubId);
            }

            // Athleten anlegen
            foreach ($request->input('athletes', []) as $athleteData) {
                if (($athleteData['action'] ?? 'skip') !== 'create') {
                    continue;
                }
                $this->resolver->createAthlete([
                    'first_name' => $athleteData['first_name'],
                    'last_name' => $athleteData['last_name'],
                    'birth_date' => $athleteData['birth_date'] ?? null,
                    'gender' => $athleteData['gender'] ?? 'M',
                    'nation_id' => $athleteData['nation_id'],
                    'club_id' => $athleteData['club_id'] ?? null,
                    'license' => $athleteData['license'] ?? null,
                    'license_ipc' => $athleteData['license_ipc'] ?? null,
                ]);
            }

            return $this->runFinalImport($sessionKey, $importData['resolved_club_ids'] ?? []);

        } catch (Exception $e) {
            return back()->withErrors([
                'resolve' => 'Fehler beim Anlegen der Athleten: '.$e->getMessage(),
            ]);
        }
    }

    /**
     * Sucht Meets die zum importierten Meet passen könnten.
     * Matcht auf: gleiches Datum ODER ähnlicher Name (case-insensitive Teilstring).
     */
    private function findMeetCandidates(array $meta): Collection
    {
        $name = $meta['name'] ?? '';
        $startDate = $meta['start_date'] ?? null;

        // Ersten signifikanten Teil des Namens extrahieren (vor Jahreszahl)
        // "LM Salzburg mit ÖBSV Cup 2026" → "LM Salzburg mit ÖBSV Cup"
        $nameWithoutYear = trim(preg_replace('/\b(19|20)\d{2}\b/', '', $name));

        return Meet::where(function ($q) use ($startDate, $nameWithoutYear, $name) {
            // Gleiches Datum
            if ($startDate) {
                $q->orWhere('start_date', $startDate);
            }
            // Name enthält den gekürzten Suchbegriff (ohne Jahreszahl)
            if ($nameWithoutYear) {
                $q->orWhere('name', 'like', '%'.trim($nameWithoutYear).'%');
            }
            // Oder der DB-Name ist im Import-Namen enthalten
            if ($name) {
                $q->orWhereRaw('? LIKE CONCAT(\'%\', name, \'%\')', [$name]);
            }
        })
            ->orderBy('start_date', 'desc')
            ->limit(5)
            ->get();
    }

    private function redirectAfterImport(array $result): RedirectResponse
    {
        $stats = $result['stats'];
        $message = 'LENEX ('.$result['type'].') importiert: '
            .$stats['meets'].' Wettkampf/Wettkämpfe, '
            .$stats['athletes'].' Athlet(en), '
            .$stats['entries'].' Meldungen, '
            .$stats['results'].' Ergebnisse.';

        return redirect()->route('meets.index')->with('success', $message);
    }

    // ── Private Hilfsmethoden ─────────────────────────────────────────────────

    /**
     * Finaler Import-Durchlauf — wird genau einmal aufgerufen,
     * nachdem alle Clubs und Athleten angelegt sind.
     */
    private function runFinalImport(string $sessionKey, array $resolvedClubIds): RedirectResponse
    {
        $importData = Session::get($sessionKey);

        if (! $importData) {
            return redirect()->route('lenex.import')
                ->withErrors(['import' => 'Import-Session abgelaufen.']);
        }

        // Club-Cache aus der Session wiederherstellen (cache_key → club_id)
        foreach ($resolvedClubIds as $cacheKey => $clubId) {
            $this->resolver->addToClubCache((string) $cacheKey, $clubId);
        }

        $fullPath = Storage::disk('local')->path($importData['path']);
        $forceMeetId = $importData['force_meet_id'] ?? null;

        try {
            $result = $this->parser->import($fullPath, $this->resolver, $forceMeetId);

            Session::forget($sessionKey);
            Storage::disk('local')->delete($importData['path']);

            return $this->redirectAfterImport($result);

        } catch (Exception $e) {
            return back()->withErrors([
                'import' => 'Finaler Import fehlgeschlagen: '.$e->getMessage(),
            ]);
        }
    }
}
