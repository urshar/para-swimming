<?php

namespace App\Http\Controllers;

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\SwimEvent;
use App\Services\ImportSuggestionService;
use App\Services\LenexParserService;
use App\Services\LenexResolverService;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class LenexImportController extends Controller
{
    public function __construct(
        private readonly LenexParserService $parser,
        private readonly LenexResolverService $resolver,
        private readonly ImportSuggestionService $suggestions,
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

        $nations = $importData['meta']['nations'] ?? [];

        return view('lenex.confirm-meet', [
            'importSession' => $sessionKey,
            'meta' => $importData['meta'],
            'nations' => $nations,
            // Fehlt die Nation der Veranstaltung in der Datei, wird sie für eine neue Veranstaltung abgefragt.
            'meetNations' => ($importData['meta']['meet_nation_known'] ?? true) ? collect() : Nation::orderBy('name_de')->get(['code', 'name_de']),
            // Vorauswahl Österreich, wenn die Datei mehrere Nationen enthält.
            'defaultNation' => count($nations) > 1 && in_array('AUT', $nations, true) ? 'AUT' : 'ALL',
            'type' => $importData['type'],
            'candidates' => $candidates,
        ]);
    }

    /**
     * Schritt 1c: Meet-Auswahl bestätigen. Enthält die Datei neue Einzelbewerbe ohne jede Klassenangabe (mögliche
     * Rahmenbewerbe, z. B. Schnupperbewerbe), wird vor dem ersten Lauf nachgefragt; sonst startet der Import direkt.
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

        $meetId = (int) $request->input('meet_id') ?: null; // null = neues Meet
        // Nationenfilter: nur eine Nation aus der Datei; "ALL" (Sentinel statt leerem Wert, siehe CLAUDE.md) = alle.
        $onlyNation = $request->input('only_nation');
        $onlyNation = in_array($onlyNation, $importData['meta']['nations'] ?? [], true) ? $onlyNation : null;

        // Nation der Veranstaltung fehlt in der Datei: Pflicht, wenn eine neue Veranstaltung angelegt wird.
        $meetNation = Nation::where('code', (string) $request->input('meet_nation'))->value('code');
        if ($meetId === null && ! ($importData['meta']['meet_nation_known'] ?? true) && $meetNation === null) {
            return back()->withErrors([
                'meet_nation' => 'Die Datei enthält keine bekannte Nation der Veranstaltung — bitte auswählen.',
            ]);
        }

        try {
            // Internationale Veranstaltungen (Nationenfilter) haben keine Rahmenbewerbe — keine Abfrage.
            $pendingEvents = $onlyNation === null ? $this->newUnclassifiedEvents($importData['path'], $meetId) : [];
            Session::forget($sessionKey);

            if ($pendingEvents !== []) {
                $newSessionKey = uniqid('lenex_import_', true);
                Session::put($newSessionKey, [
                    'path' => $importData['path'],
                    'meet_id' => $meetId,
                    'meet_nation' => $meetNation,
                    'pending_events' => $pendingEvents,
                ]);

                return redirect()->route('lenex.import.review', ['session' => $newSessionKey]);
            }

            return $this->startImport($importData['path'], $meetId, [], $onlyNation, $meetNation);

        } catch (Exception $e) {
            return back()->withErrors([
                'import' => 'Import fehlgeschlagen: '.$e->getMessage(),
            ]);
        }
    }

    /**
     * Schritt 1d: Rahmenbewerbe festlegen (angekreuzte Bewerbe werden als nicht gewertet angelegt) → Import starten.
     */
    public function resolveEvents(Request $request): RedirectResponse
    {
        $sessionKey = $request->input('import_session');
        $importData = Session::get($sessionKey);

        if (! $importData) {
            return redirect()->route('lenex.import')
                ->withErrors(['import' => 'Import-Session abgelaufen.']);
        }

        $offered = array_column($importData['pending_events'], 'number');
        $unscored = array_values(array_intersect(
            $offered,
            array_map('intval', (array) $request->input('unscored_events', []))
        ));

        try {
            Session::forget($sessionKey);

            return $this->startImport($importData['path'], $importData['meet_id'], $unscored, null, $importData['meet_nation'] ?? null);

        } catch (Exception $e) {
            return back()->withErrors([
                'resolve' => 'Import fehlgeschlagen: '.$e->getMessage(),
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
            $meta = $this->parser->extractMeetMeta($fullPath) + ['nations' => $this->parser->fileNations($fullPath)];
            $meta['meet_nation_known'] = Nation::where('code', $meta['nation'])->exists();

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
     * Schritt 2: Klärungsseite — unbekannte Vereine bzw. Athleten mit Vorschlägen und der Möglichkeit, sie einem
     * bestehenden Datensatz zuzuordnen (wie beim Rekord-Import).
     */
    public function review(Request $request): RedirectResponse|View
    {
        $sessionKey = $request->input('session');
        $importData = Session::get($sessionKey);

        if (! $importData) {
            return redirect()->route('lenex.import')
                ->withErrors(['import' => 'Import-Session abgelaufen. Bitte Datei erneut hochladen.']);
        }

        $clubs = Club::orderBy('name')->get(['id', 'name', 'short_name', 'code']);

        $unresolvedClubs = array_map(function (array $club) use ($clubs): array {
            $suggestions = $this->suggestions->suggestClubs($club['code'], $club['name'], $clubs);

            return $club + [
                'suggestions' => $suggestions->map(fn (Club $c): array => ['id' => $c->id, 'label' => self::clubLabel($c)])->all(),
                'preselect' => $suggestions->count() === 1 ? $suggestions->first()->id : null,
            ];
        }, $importData['unresolved_clubs'] ?? []);

        $clubNames = $clubs->pluck('name', 'id');
        $unresolvedAthletes = array_map(function (array $athlete) use ($clubNames): array {
            $suggestions = $this->suggestions->suggestAthletes(
                $athlete['last_name'], $athlete['first_name'], $athlete['birth_date'], $athlete['gender']
            );

            return $athlete + [
                'club_name' => $clubNames[$athlete['club_id']] ?? null,
                'birth_date_display' => $athlete['birth_date'] !== '' ? Carbon::parse($athlete['birth_date'])->format('d.m.Y') : '',
                'suggestions' => $suggestions->map(fn (Athlete $a): array => [
                    'id' => $a->id,
                    'label' => self::athleteLabel($a, $clubNames),
                ])->all(),
                'preselect' => $athlete['birth_date'] !== '' && $suggestions->count() === 1 ? $suggestions->first()->id : null,
            ];
        }, $importData['unresolved_athletes'] ?? []);

        $athletes = empty($unresolvedAthletes) ? collect() : Athlete::orderBy('last_name')->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'birth_date', 'license', 'club_id'])
            ->map(fn (Athlete $a): array => [
                'id' => $a->id,
                'initial' => mb_strtoupper(mb_substr($a->last_name, 0, 1)),
                'label' => self::athleteLabel($a, $clubNames),
            ]);

        return view('lenex.review', [
            'importSession' => $sessionKey,
            'pendingEvents' => $importData['pending_events'] ?? [],
            'unresolvedClubs' => $unresolvedClubs,
            'unresolvedAthletes' => $unresolvedAthletes,
            'clubOptions' => $clubs->map(fn (Club $c): array => ['id' => $c->id, 'label' => self::clubLabel($c)]),
            'athletes' => $athletes,
        ]);
    }

    /**
     * Schritt 3a: Vereine klären (neu anlegen, bestehendem zuordnen oder überspringen). Danach läuft der Import mit
     * diesen Zuordnungen erneut (wiederholbar dank Ergebnis-Abgleich) und ermittelt die noch unbekannten Athleten —
     * auch die eines zugeordneten bestehenden Vereins.
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
            $clubMap = $importData['club_map'] ?? [];
            foreach ($importData['unresolved_clubs'] ?? [] as $index => $clubData) {
                $selection = (string) $request->input("clubs.$index.selection", 'skip');
                $club = match (true) {
                    $selection === 'new' => $this->resolver->createClub($clubData),
                    ctype_digit($selection) => Club::find((int) $selection),
                    default => null,
                };
                if ($club && $clubData['cache_key'] !== '') {
                    $clubMap[$clubData['cache_key']] = $club->id;
                }
            }

            $result = $this->importWithAssignments($importData, $clubMap, []);
            $unresolvedAthletes = $this->resolver->getUnresolvedAthletes();

            if (empty($unresolvedAthletes)) {
                Session::forget($sessionKey);
                Storage::disk('local')->delete($importData['path']);

                return $this->redirectAfterImport($result);
            }

            $newSessionKey = uniqid('lenex_import_', true);
            Session::put($newSessionKey, [
                'path' => $importData['path'],
                'force_meet_id' => $importData['force_meet_id'] ?? null,
                'only_nation' => $importData['only_nation'] ?? null,
                'club_map' => $clubMap,
                'unresolved_clubs' => [],
                'unresolved_athletes' => $unresolvedAthletes,
            ]);
            Session::forget($sessionKey);

            return redirect()->route('lenex.import.review', ['session' => $newSessionKey]);

        } catch (Exception $e) {
            return back()->withErrors([
                'resolve' => 'Fehler beim Klären der Vereine: '.$e->getMessage(),
            ]);
        }
    }

    /**
     * Schritt 3b: Athleten klären (neu anlegen, bestehendem zuordnen oder überspringen) → finaler Import.
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
            $athleteMap = [];
            foreach ($importData['unresolved_athletes'] ?? [] as $index => $athleteData) {
                $selection = (string) $request->input("athletes.$index.selection", 'skip');
                $athlete = match (true) {
                    $selection === 'new' => $this->resolver->createAthlete($athleteData),
                    ctype_digit($selection) => Athlete::find((int) $selection),
                    default => null,
                };
                if ($athlete && $athleteData['lenex_id'] !== '') {
                    $athleteMap[$athleteData['lenex_id']] = $athlete->id;
                }
            }

            $result = $this->importWithAssignments($importData, $importData['club_map'] ?? [], $athleteMap);

            Session::forget($sessionKey);
            Storage::disk('local')->delete($importData['path']);

            return $this->redirectAfterImport($result);

        } catch (Exception $e) {
            return back()->withErrors([
                'resolve' => 'Fehler beim Klären der Athleten: '.$e->getMessage(),
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
            ->orderByDesc('start_date')
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
            .($stats['relay_entries'] ?? 0).' Staffelmeldungen, '
            .$stats['results'].' Ergebnisse'
            .($stats['results'] > 0 ? self::resultMatchSummary($stats) : '').', '
            .($stats['relay_results'] ?? 0).' Staffelergebnisse.'
            .(! empty($stats['without_club']) ? ' '.$stats['without_club'].' Meldung(en)/Ergebnis(se) übersprungen: Athlet ohne Verein.' : '');

        return redirect()->route('meets.index')->with('success', $message);
    }

    /** "(davon X neu, Y mit vorhandenen abgeglichen[, Z mehrdeutig])" für die Import-Rückmeldung. */
    private static function resultMatchSummary(array $stats): string
    {
        $new = $stats['results_new'] ?? 0;
        $ambiguous = $stats['results_ambiguous'] ?? 0;

        return ' (davon '.$new.' neu, '.($stats['results'] - $new).' mit vorhandenen abgeglichen'
            .($ambiguous > 0 ? ', '.$ambiguous.' mehrdeutig und neu angelegt' : '').')';
    }

    // ── Private Hilfsmethoden ─────────────────────────────────────────────────

    /**
     * Erster Import-Lauf. Gibt es unbekannte Vereine oder Athleten, folgt die Klärungsseite; die dabei angelegte bzw.
     * gewählte Veranstaltung wird gemerkt, damit die Folgeschritte keine weitere anlegen.
     *
     * @param  list<int>  $unscoredEventNumbers
     * @param  string|null  $onlyNation  Nationenfilter (nur Schwimmer dieser Nation), null = alle
     * @param  string|null  $meetNation  Nation der Veranstaltung, wenn sie in der Datei fehlt
     *
     * @throws Exception
     */
    private function startImport(
        string $path,
        ?int $meetId,
        array $unscoredEventNumbers,
        ?string $onlyNation,
        ?string $meetNation
    ): RedirectResponse {
        $result = $this->parser->import(Storage::disk('local')->path($path), $this->resolver, $meetId, [
            'unscored_events' => $unscoredEventNumbers,
            'only_nation' => $onlyNation,
            'meet_nation' => $meetNation,
        ]);

        if (! $this->resolver->hasUnresolved()) {
            Storage::disk('local')->delete($path);

            return $this->redirectAfterImport($result);
        }

        $newSessionKey = uniqid('lenex_import_', true);
        Session::put($newSessionKey, [
            'path' => $path,
            'force_meet_id' => $meetId ?: $result['meet']->id,
            'only_nation' => $onlyNation,
            'unresolved_clubs' => $this->resolver->getUnresolvedClubs(),
            'unresolved_athletes' => $this->resolver->getUnresolvedAthletes(),
        ]);

        return redirect()->route('lenex.import.review', ['session' => $newSessionKey]);
    }

    /**
     * Einzelbewerbe der Datei ohne Klassenangabe, die in der Ziel-Veranstaltung noch nicht existieren. Bestehende
     * Bewerbe behalten ihre Kennzeichnung (Disziplin-Formular), beim Nachimport wird also nicht erneut gefragt.
     *
     * @return list<array{number: int, label: string, groups: string}>
     *
     * @throws Exception
     */
    private function newUnclassifiedEvents(string $path, ?int $meetId): array
    {
        $events = $this->parser->unclassifiedEvents(Storage::disk('local')->path($path));
        if ($meetId === null || $events === []) {
            return $events;
        }

        $existing = SwimEvent::where('meet_id', $meetId)->pluck('event_number')->all();

        return array_values(array_filter($events, fn (array $e): bool => ! in_array($e['number'], $existing, true)));
    }

    /**
     * Import-Durchlauf mit den Zuordnungen der Klärungsseite: Verein (cache_key → Club-ID) und Athlet
     * (LENEX athleteid → Athleten-ID).
     *
     * @param  array<int|string, int>  $clubMap  numerische Schlüssel macht PHP zu int
     * @param  array<int|string, int>  $athleteMap
     *
     * @throws Exception
     */
    private function importWithAssignments(array $importData, array $clubMap, array $athleteMap): array
    {
        foreach ($clubMap as $cacheKey => $clubId) {
            $this->resolver->addToClubCache((string) $cacheKey, $clubId);
        }
        foreach ($athleteMap as $lenexId => $athleteId) {
            $this->resolver->assignAthlete((string) $lenexId, $athleteId);
        }

        // Session-Werte sind untypisiert — für den Parser auf ?string bringen.
        $onlyNation = isset($importData['only_nation']) ? (string) $importData['only_nation'] : null;

        return $this->parser->import(
            Storage::disk('local')->path($importData['path']),
            $this->resolver,
            $importData['force_meet_id'] ?? null,
            ['only_nation' => $onlyNation]
        );
    }

    /** "Name (Code)" für Auswahllisten — eindeutiger als der Kurzname (display_name). */
    private static function clubLabel(Club $club): string
    {
        return $club->name.($club->code ? ' ('.$club->code.')' : '');
    }

    /** "Nachname, Vorname (*TT.MM.JJJJ) · Lizenz · Verein" für Auswahllisten. */
    private static function athleteLabel(Athlete $athlete, SupportCollection $clubNames): string
    {
        return implode(' · ', array_filter([
            $athlete->display_name.($athlete->birth_date ? ' (*'.$athlete->birth_date->format('d.m.Y').')' : ''),
            $athlete->license,
            $clubNames[$athlete->club_id] ?? null,
        ]));
    }
}
