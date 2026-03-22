<?php

namespace App\Http\Controllers;

use App\Services\LenexParserService;
use App\Services\LenexResolverService;
use Exception;
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
     * Schritt 3: Entscheidungen des Benutzers verarbeiten und Import abschließen.
     */
    public function resolve(Request $request): RedirectResponse
    {
        $sessionKey = $request->input('import_session');
        $importData = Session::get($sessionKey);

        if (! $importData) {
            return redirect()->route('lenex.import')
                ->withErrors(['import' => 'Import-Session abgelaufen.']);
        }

        try {
            // Clubs verarbeiten
            foreach ($request->input('clubs', []) as $clubData) {
                if (($clubData['action'] ?? 'skip') === 'create') {
                    $club = $this->resolver->createClub([
                        'name' => $clubData['name'],
                        'short_name' => $clubData['short_name'] ?? null,
                        'code' => $clubData['code'] ?? null,
                        'nation_id' => $clubData['nation_id'],
                        'type' => $clubData['type'] ?? 'CLUB',
                        'lenex_club_id' => $clubData['lenex_id'] ?? null,
                    ]);
                    // In Cache aufnehmen für Athleten-Zuordnung
                    if (! empty($clubData['lenex_id'])) {
                        $this->resolver->addToClubCache($clubData['lenex_id'], $club->id);
                    }
                }
            }

            // Athleten verarbeiten
            foreach ($request->input('athletes', []) as $athleteData) {
                if (($athleteData['action'] ?? 'skip') === 'create') {
                    $this->resolver->createAthlete([
                        'first_name' => $athleteData['first_name'],
                        'last_name' => $athleteData['last_name'],
                        'birth_date' => $athleteData['birth_date'] ?? null,
                        'gender' => $athleteData['gender'] ?? 'M',
                        'nation_id' => $athleteData['nation_id'],
                        'club_id' => $athleteData['club_id'] ?? null,
                        'license' => $athleteData['license'] ?? null,
                        'sport_class' => $athleteData['sport_class'] ?? null,
                        'lenex_athlete_id' => $athleteData['lenex_id'] ?? null,
                    ]);
                }
            }

            // Import mit vollständig aufgelöstem Resolver fortsetzen
            $fullPath = Storage::disk('local')->path($importData['path']);
            $result = $this->parser->import($fullPath, $this->resolver);

            Session::forget($sessionKey);
            Storage::disk('local')->delete($importData['path']);

            return $this->redirectAfterImport($result);

        } catch (Exception $e) {
            return back()->withErrors([
                'resolve' => 'Fehler beim Fortsetzen des Imports: '.$e->getMessage(),
            ]);
        }
    }

    /**
     * Schritt 1: LENEX Datei hochladen und parsen.
     * Wenn unbekannte Clubs/Athleten gefunden werden → Review-Seite anzeigen.
     * Wenn alles bekannt → direkt importieren.
     */
    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'lenex_file' => 'required|file|mimes:lxf,xml|max:20480',
        ]);

        $path = $request->file('lenex_file')->store('lenex-imports', 'local');
        $fullPath = Storage::disk('local')->path($path);

        try {
            $result = $this->parser->import($fullPath, $this->resolver);

            // Gibt es unaufgelöste Clubs oder Athleten?
            if ($this->resolver->hasUnresolved()) {
                // Import-Session speichern für Schritt 2
                $sessionKey = uniqid('lenex_import_', true);
                Session::put($sessionKey, [
                    'path' => $path,
                    'partial_result' => $result,
                    'unresolved_clubs' => $this->resolver->getUnresolvedClubs(),
                    'unresolved_athletes' => $this->resolver->getUnresolvedAthletes(),
                ]);

                return redirect()->route('lenex.import.review', ['session' => $sessionKey]);
            }

            // Alles aufgelöst — direkt fertig
            return $this->redirectAfterImport($result);

        } catch (Exception $e) {
            Storage::disk('local')->delete($path);

            return back()->withErrors([
                'lenex_file' => 'Import fehlgeschlagen: '.$e->getMessage(),
            ]);
        }
    }

    // ── Private Hilfsmethoden ─────────────────────────────────────────────────

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
}
