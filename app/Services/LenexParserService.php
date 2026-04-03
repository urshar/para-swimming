<?php

namespace App\Services;

use App\Models\Club;
use App\Models\Entry;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\ResultSplit;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Support\TimeParser;
use Exception;
use SimpleXMLElement;
use ZipArchive;

/**
 * LenexParserService
 *
 * Erkennt den LENEX-Typ automatisch und importiert:
 *   structure → Meet, Sessions, SwimEvents
 *   entries   → + Clubs, Athletes, Entries
 *   results   → + Results, Splits
 */
class LenexParserService
{
    private array $stats = [
        'meets' => 0,
        'clubs' => 0,
        'athletes' => 0,
        'events' => 0,
        'entries' => 0,
        'results' => 0,
    ];

    // LenexResolverService wird als Parameter an import() übergeben,
    // nicht per Constructor — der Import ist zustandslos pro Aufruf.

    // ── Öffentliche Methoden ──────────────────────────────────────────────────

    /**
     * @throws Exception
     */
    /**
     * @throws Exception
     */
    public function import(string $filePath, LenexResolverService $resolver, ?int $forceMeetId = null): array
    {
        $xml = $this->loadXml($filePath);
        $type = $this->detectType($xml);

        $meet = $this->importMeet($xml, $resolver, $type, $forceMeetId);

        return [
            'type' => $type,
            'meet' => $meet,
            'stats' => $this->stats,
        ];
    }

    // ── Entries ───────────────────────────────────────────────────────────────

    /**
     * Nur den LENEX-Typ erkennen ohne zu importieren.
     * Wird vom Controller für die Meet-Auswahl benötigt.
     *
     * @throws Exception
     */
    public function detectTypeFromFile(string $filePath): string
    {
        $xml = $this->loadXml($filePath);

        return $this->detectType($xml);
    }

    // ── Results ───────────────────────────────────────────────────────────────

    /**
     * Meet-Metadaten aus der Datei lesen ohne zu importieren.
     * Wird für die Vorschau in der Meet-Auswahl benötigt.
     *
     * @throws Exception
     */
    public function extractMeetMeta(string $filePath): array
    {
        $xml = $this->loadXml($filePath);
        $meetXml = $xml->MEETS->MEET[0];

        $startDate = (string) ($meetXml['startdate'] ?? '');
        if (empty($startDate) && isset($meetXml->SESSIONS->SESSION)) {
            $startDate = (string) ($meetXml->SESSIONS->SESSION[0]['date'] ?? '');
        }

        return [
            'name' => (string) ($meetXml['name'] ?? ''),
            'city' => preg_replace('/\s*\/\s*/', '/', (string) ($meetXml['city'] ?? '')),
            'course' => (string) ($meetXml['course'] ?? ''),
            'start_date' => $startDate ?: null,
            'nation' => (string) ($meetXml['nation'] ?? ''),
        ];
    }

    /**
     * Liest Athleten aus dem XML für die angegebenen Club-lenex_ids.
     * Wird nach dem Anlegen neuer Clubs aufgerufen, um deren Athleten
     * für die Review-Seite zu sammeln — ohne Import-Durchlauf.
     *
     * @param  string[]  $clubLenexIds  lenex_ids der neu angelegten Clubs
     * @param  array<string,int>  $clubIdMap  lenex_id → DB-Club-ID
     * @return array Liste von unresolved_athlete Arrays (gleiche Struktur wie in LenexResolverService)
     *
     * @throws Exception
     */
    public function extractAthletesForClubs(
        string $filePath,
        array $clubLenexIds,
        array $clubIdMap
    ): array {
        if (empty($clubLenexIds)) {
            return [];
        }

        $xml = $this->loadXml($filePath);
        $meetXml = $xml->MEETS->MEET[0];

        if (! isset($meetXml->CLUBS)) {
            return [];
        }

        $athletes = [];

        foreach ($meetXml->CLUBS->CLUB as $clubXml) {
            // Splash verwendet 'clubid', LENEX-Standard wäre 'id'
            $lenexClubId = (string) ($clubXml['clubid'] ?? $clubXml['id'] ?? '');
            $code = (string) ($clubXml['code'] ?? '');
            // Denselben cache_key wie resolveClub() berechnen
            $cacheKey = $lenexClubId ?: ('code:'.$code);

            if (! isset($clubIdMap[$cacheKey])) {
                continue;
            }

            $dbClubId = $clubIdMap[$cacheKey];
            $nationCode = (string) ($clubXml['nation'] ?? '');
            $nation = Nation::where('code', $nationCode)->first();
            $nationId = $nation?->id ?? 0;

            if (! isset($clubXml->ATHLETES)) {
                continue;
            }

            foreach ($clubXml->ATHLETES->ATHLETE as $athleteXml) {
                $athletes[] = [
                    'lenex_id' => (string) ($athleteXml['athleteid'] ?? ''),
                    'license' => (string) ($athleteXml['license'] ?? ''),
                    'license_ipc' => (string) ($athleteXml['license_ipc'] ?? ''),
                    'last_name' => (string) ($athleteXml['lastname'] ?? ''),
                    'first_name' => (string) ($athleteXml['firstname'] ?? ''),
                    'birth_date' => (string) ($athleteXml['birthdate'] ?? ''),
                    'gender' => (string) ($athleteXml['gender'] ?? ''),
                    'nation_id' => $nationId,
                    'club_id' => $dbClubId,
                    'sport_class' => isset($athleteXml->HANDICAP)
                        ? $this->extractPrimaryClassFromHandicap($athleteXml->HANDICAP)
                        : null,
                ];
            }
        }

        return $athletes;
    }

    // ── Event-ID Auflösung ────────────────────────────────────────────────────

    /**
     * Lädt eine LENEX-Datei als SimpleXMLElement.
     * Unterstützt sowohl .lxf (ZIP-komprimiert) als auch .xml/.lef (plain XML).
     *
     * @throws Exception
     */
    private function loadXml(string $filePath): SimpleXMLElement
    {
        if (! file_exists($filePath)) {
            throw new Exception('Datei nicht gefunden: '.$filePath);
        }

        // LXF = ZIP-Archiv das eine LEF-Datei enthält
        $xmlContent = $this->extractXmlContent($filePath);

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlContent, 'SimpleXMLElement', LIBXML_NOCDATA);

        if ($xml === false) {
            $errors = array_map(fn ($e) => $e->message, libxml_get_errors());
            libxml_clear_errors();
            throw new Exception('XML-Fehler: '.implode(', ', $errors));
        }

        return $xml;
    }

    // ── Sport-Klassen aus AGEGROUPS extrahieren ───────────────────────────────

    /**
     * Extrahiert den XML-Inhalt aus einer LXF (ZIP) oder LEF/XML Datei.
     *
     * @throws Exception
     */
    private function extractXmlContent(string $filePath): string
    {
        // Prüfe, ob es eine ZIP-Datei ist (LXF)
        $zip = new ZipArchive;
        if ($zip->open($filePath) === true) {
            // Suche die erste .lef oder .xml Datei im ZIP
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (str_ends_with(strtolower($name), '.lef') || str_ends_with(strtolower($name), '.xml')) {
                    $content = $zip->getFromIndex($i);
                    $zip->close();
                    if ($content === false) {
                        throw new Exception('Konnte Datei aus ZIP nicht lesen: '.$name);
                    }

                    return $content;
                }
            }
            $zip->close();
            throw new Exception('Keine LEF/XML-Datei im LXF-Archiv gefunden.');
        }

        // Keine ZIP-Datei — direkt als XML lesen
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new Exception('Datei konnte nicht gelesen werden: '.$filePath);
        }

        return $content;
    }

    // ── XML laden ────────────────────────────────────────────────────────────

    /**
     * @throws Exception
     */
    private function detectType(SimpleXMLElement $xml): string
    {
        $meet = $xml->MEETS->MEET ?? null;
        if (! $meet) {
            throw new Exception('Keine MEET-Elemente in der LENEX-Datei gefunden.');
        }

        $meet = $meet[0];

        // Results vorhanden?
        if (isset($meet->CLUBS->CLUB->ATHLETES->ATHLETE->RESULTS->RESULT)) {
            return 'results';
        }

        // Entries vorhanden?
        if (isset($meet->CLUBS->CLUB->ATHLETES->ATHLETE->ENTRIES->ENTRY)) {
            return 'entries';
        }

        return 'structure';
    }

    private function importMeet(
        SimpleXMLElement $xml,
        LenexResolverService $resolver,
        string $type,
        ?int $forceMeetId = null
    ): Meet {
        $meetXml = $xml->MEETS->MEET[0];

        // Wenn ein bestehendes Meet erzwungen wird, direkt laden und nur Events/Clubs importieren
        if ($forceMeetId) {
            $meet = Meet::findOrFail($forceMeetId);
            $this->stats['meets']++;

            if (isset($meetXml->SESSIONS)) {
                $this->importSessions($meet, $meetXml->SESSIONS, $resolver);
            }
            if (in_array($type, ['entries', 'results']) && isset($meetXml->CLUBS)) {
                $this->importClubs($meet, $meetXml->CLUBS, $resolver, $type);
            }

            return $meet;
        }

        $nationCode = (string) ($meetXml['nation'] ?? '');
        $nation = Nation::where('code', $nationCode)->first();

        // startdate kann fehlen (z.B. Splash Entries-Export) — Fallback auf erstes Session-Datum
        $startDate = (string) ($meetXml['startdate'] ?? '');
        if (empty($startDate) && isset($meetXml->SESSIONS->SESSION)) {
            $startDate = (string) ($meetXml->SESSIONS->SESSION[0]['date'] ?? '');
        }

        // stopdate kann fehlen — Fallback auf letztes Session-Datum
        $endDate = (string) ($meetXml['stopdate'] ?? '') ?: null;
        if (empty($endDate) && isset($meetXml->SESSIONS->SESSION)) {
            $sessions = $meetXml->SESSIONS->SESSION;
            $lastDate = (string) ($sessions[count($sessions) - 1]['date'] ?? '');
            $endDate = $lastDate ?: null;
        }

        // City normalisieren: Splash schreibt manchmal "Rif / Hallein" und manchmal "Rif/Hallein"
        $city = preg_replace('/\s*\/\s*/', '/', (string) ($meetXml['city'] ?? ''));

        // Meet-Matching: name + start_date reicht — meetid fehlt in Splash Entries-Exporten
        $meet = Meet::updateOrCreate(
            [
                'name' => (string) ($meetXml['name'] ?? ''),
                'start_date' => $startDate,
            ],
            [
                'city' => $city,
                'nation_id' => $nation?->id,
                'course' => $this->mapCourse((string) ($meetXml['course'] ?? 'LCM')),
                'end_date' => $endDate,
                'organizer' => (string) ($meetXml['organizer'] ?? '') ?: null,
                'altitude' => (int) ($meetXml['altitude'] ?? 0),
                'timing' => $this->mapTiming((string) ($meetXml['timing'] ?? 'AUTOMATIC')),
                'lenex_meet_id' => (string) ($meetXml['meetid'] ?? '') ?: null,
            ]
        );
        $this->stats['meets']++;

        // Sessions + Events
        if (isset($meetXml->SESSIONS)) {
            $this->importSessions($meet, $meetXml->SESSIONS, $resolver);
        }

        // Clubs + Athletes + Entries/Results
        if (in_array($type, ['entries', 'results']) && isset($meetXml->CLUBS)) {
            $this->importClubs($meet, $meetXml->CLUBS, $resolver, $type);
        }

        return $meet;
    }

    // ── Format-Konvertierungen ────────────────────────────────────────────────

    private function importSessions(
        Meet $meet,
        SimpleXMLElement $sessionsXml,
        LenexResolverService $resolver
    ): void {
        foreach ($sessionsXml->SESSION as $sessionXml) {
            $sessionNumber = (int) ($sessionXml['number'] ?? 1);

            if (! isset($sessionXml->EVENTS)) {
                continue;
            }

            foreach ($sessionXml->EVENTS->EVENT as $eventXml) {
                $this->importEvent($meet, $eventXml, $sessionNumber, $resolver);
            }
        }
    }

    private function importEvent(
        Meet $meet,
        SimpleXMLElement $eventXml,
        int $sessionNumber,
        LenexResolverService $resolver
    ): void {
        $swimStyleXml = $eventXml->SWIMSTYLE ?? null;
        if (! $swimStyleXml) {
            return;
        }

        $lenexStroke = (string) ($swimStyleXml['stroke'] ?? 'UNKNOWN');
        $strokeType = StrokeType::findByLenexCode($lenexStroke)
            ?? StrokeType::where('code', 'UNKNOWN')->first();

        if (! $strokeType) {
            return;
        }

        $lenexEventId = (string) ($eventXml['eventid'] ?? '');
        $eventNumber = (int) ($eventXml['number'] ?? 0);

        // Sport-Klassen: aus AGEGROUPS extrahieren (Struktur-Import).
        // Wenn keine AGEGROUPs vorhanden (Entries-Export), bestehenden DB-Wert beibehalten.
        $sportClassesFromXml = $this->extractSportClasses($eventXml);
        if ($sportClassesFromXml === null) {
            $existing = SwimEvent::where('meet_id', $meet->id)
                ->where('event_number', $eventNumber)
                ->value('sport_classes');
            $sportClasses = $existing;
        } else {
            $sportClasses = $sportClassesFromXml;
        }

        $swimEvent = SwimEvent::updateOrCreate(
            [
                'meet_id' => $meet->id,
                'event_number' => $eventNumber,
            ],
            [
                'stroke_type_id' => $strokeType->id,
                'session_number' => $sessionNumber,
                'gender' => $this->mapGender((string) ($eventXml['gender'] ?? 'A')),
                'round' => $this->mapRound((string) ($eventXml['round'] ?? 'TIM')),
                'distance' => (int) ($swimStyleXml['distance'] ?? 0),
                'relay_count' => (int) ($swimStyleXml['relaycount'] ?? 1),
                'technique' => $this->mapTechnique((string) ($swimStyleXml['technique'] ?? '')),
                'style_code' => (string) ($swimStyleXml['code'] ?? '') ?: null,
                'style_name' => (string) ($swimStyleXml['name'] ?? '') ?: null,
                'sport_classes' => $sportClasses,
                'timing' => $this->mapTiming((string) ($eventXml['timing'] ?? '')),
                'lenex_event_id' => $lenexEventId ?: null,
            ]
        );

        // Resolver-Cache für spätere Entry/Result-Zuordnung.
        // Splash verwendet in Entries-Exporten eventid = number * 10 (10, 20, 30, ...)
        // daher befüllen wir den Cache mit beiden Keys.
        if ($lenexEventId) {
            $resolver->addToEventCache($lenexEventId, $swimEvent->id);
        }
        // Fallback-Key per event_number (z.B. "num:4")
        $resolver->addToEventCache('num:'.$eventNumber, $swimEvent->id);

        $this->stats['events']++;
    }

    /**
     * Liest alle handicap-Werte aus AGEGROUP-Elementen eines EVENTs.
     *
     * Splash Meet Manager:
     *   - Trennt Klassen mit Komma:  handicap="1,2,3,4,5,6,7,8,9"
     *   - Exportiert AGEGROUPs redundant (Gesamtliste + einzelne Untergruppen)
     *   - Kein S-Prefix — nur Zahlen: "1", "14", "21"
     *
     * Ergebnis: leerzeichen-getrennte, deduplizierte, numerisch sortierte Liste.
     * Gibt null zurück wenn keine AGEGROUPs vorhanden (z.B. Entries-Export).
     */
    private function extractSportClasses(SimpleXMLElement $eventXml): ?string
    {
        if (! isset($eventXml->AGEGROUPS)) {
            return null;
        }

        $classes = [];
        foreach ($eventXml->AGEGROUPS->AGEGROUP as $agegroup) {
            $handicap = trim((string) ($agegroup['handicap'] ?? ''));
            if ($handicap === '') {
                continue;
            }
            // Splash verwendet Komma als Trennzeichen, LENEX-Standard wäre Leerzeichen
            $separator = str_contains($handicap, ',') ? ',' : ' ';
            foreach (explode($separator, $handicap) as $class) {
                $class = trim($class);
                if ($class !== '') {
                    $classes[$class] = true; // Key-basiert = automatisch dedupliziert
                }
            }
        }

        if (empty($classes)) {
            return null;
        }

        // Numerisch sortieren (1, 2, 3, ... 9, 10, 11, ... 21)
        uksort($classes, fn ($a, $b) => (int) $a <=> (int) $b);

        return implode(' ', array_keys($classes));
    }

    // ── Enum-Mappings ─────────────────────────────────────────────────────────

    private function mapGender(string $gender): string
    {
        return match (strtoupper(trim($gender))) {
            'M' => 'M',
            'F' => 'F',
            'X' => 'X',
            default => 'A',
        };
    }

    private function mapRound(string $round): string
    {
        $valid = ['TIM', 'FHT', 'FIN', 'SEM', 'QUA', 'PRE', 'SOP', 'SOS', 'SOQ', 'TIMETRIAL'];
        $upper = strtoupper(trim($round));

        return in_array($upper, $valid) ? $upper : 'TIM';
    }

    private function mapTechnique(string $technique): ?string
    {
        $valid = ['DIVE', 'GLIDE', 'KICK', 'PULL', 'START', 'TURN'];
        $upper = strtoupper(trim($technique));

        return in_array($upper, $valid) ? $upper : null;
    }

    private function mapTiming(string $timing): ?string
    {
        $valid = ['AUTOMATIC', 'SEMIAUTOMATIC', 'MANUAL3', 'MANUAL2', 'MANUAL1'];
        $upper = strtoupper(trim($timing));

        return in_array($upper, $valid) ? $upper : null;
    }

    private function importClubs(
        Meet $meet,
        SimpleXMLElement $clubsXml,
        LenexResolverService $resolver,
        string $type
    ): void {
        foreach ($clubsXml->CLUB as $clubXml) {
            $nationCode = (string) ($clubXml['nation'] ?? '');
            $nation = Nation::where('code', $nationCode)->first();

            $club = $resolver->resolveClub($clubXml, $nation?->id ?? 0);

            if (! $club) {
                // Unbekannter Club — wird in Review-Seite aufgelöst
                continue;
            }

            // Club dem Meet zuordnen
            $meet->clubs()->syncWithoutDetaching([$club->id]);
            $this->stats['clubs']++;

            if (! isset($clubXml->ATHLETES)) {
                continue;
            }

            foreach ($clubXml->ATHLETES->ATHLETE as $athleteXml) {
                $this->importAthlete($meet, $athleteXml, $club, $resolver, $type);
            }
        }
    }

    private function importAthlete(
        Meet $meet,
        SimpleXMLElement $athleteXml,
        Club $club,
        LenexResolverService $resolver,
        string $type
    ): void {
        $nationCode = (string) ($athleteXml['nation'] ?? $club->nation?->code ?? '');
        $nation = Nation::where('code', $nationCode)->first();

        $athlete = $resolver->resolveAthlete($athleteXml, $club->id, $nation?->id ?? 0);

        if (! $athlete) {
            return;
        }

        $this->stats['athletes']++;

        if ($type === 'entries' && isset($athleteXml->ENTRIES)) {
            foreach ($athleteXml->ENTRIES->ENTRY as $entryXml) {
                $this->importEntry($meet, $entryXml, $athlete->id, $club->id, $resolver);
            }
        }

        if ($type === 'results' && isset($athleteXml->RESULTS)) {
            foreach ($athleteXml->RESULTS->RESULT as $resultXml) {
                $this->importResult($meet, $resultXml, $athlete->id, $club->id, $resolver);
            }
        }
    }

    private function importEntry(
        Meet $meet,
        SimpleXMLElement $entryXml,
        int $athleteId,
        int $clubId,
        LenexResolverService $resolver
    ): void {
        $swimEventId = $this->resolveSwimEventId($meet, (string) ($entryXml['eventid'] ?? ''), $resolver);

        if (! $swimEventId) {
            return;
        }

        Entry::updateOrCreate(
            [
                'meet_id' => $meet->id,
                'swim_event_id' => $swimEventId,
                'athlete_id' => $athleteId,
            ],
            [
                'club_id' => $clubId,
                'entry_time' => $this->parseTime((string) ($entryXml['entrytime'] ?? '')),
                'entry_time_code' => $this->parseTimeCode((string) ($entryXml['entrytime'] ?? '')),
                'entry_course' => $this->mapCourse((string) ($entryXml['entrycourse'] ?? '')),
                'sport_class' => (string) ($entryXml['handicap'] ?? '') ?: null,
                'status' => $this->mapEntryStatus((string) ($entryXml['status'] ?? '')),
                'heat' => (int) ($entryXml['heatid'] ?? 0) ?: null,
                'lane' => (int) ($entryXml['lane'] ?? 0) ?: null,
            ]
        );

        $this->stats['entries']++;
    }

    /**
     * Löst eine LENEX eventid zur internen SwimEvent-ID auf.
     *
     * Drei Stufen:
     *   1. Direkt aus dem Resolver-Cache (Normalfall bei Struktur-Import)
     *   2. Splash-Quirk: Entries-Export verwendet eventid = event_number * 10
     *      → Cache-Key "num:N" mit umgerechnetem event_number
     *   3. DB-Fallback: direkte Suche über lenex_event_id oder event_number
     */
    private function resolveSwimEventId(
        Meet $meet,
        string $lenexEventId,
        LenexResolverService $resolver
    ): ?int {
        // 1. Cache direkt
        $swimEventId = $resolver->getEventIdFromCache($lenexEventId);

        // 2. Splash: eventid = number * 10
        if (! $swimEventId && is_numeric($lenexEventId) && (int) $lenexEventId % 10 === 0) {
            $swimEventId = $resolver->getEventIdFromCache('num:'.((int) $lenexEventId / 10));
        }

        // 3. DB-Fallback
        if (! $swimEventId) {
            $swimEventId = SwimEvent::where('meet_id', $meet->id)
                ->where(function ($q) use ($lenexEventId) {
                    $q->where('lenex_event_id', $lenexEventId)
                        ->orWhere('event_number', is_numeric($lenexEventId) ? (int) $lenexEventId / 10 : -1);
                })
                ->value('id');
        }

        return $swimEventId;
    }

    private function parseTime(string $time): ?int
    {
        return TimeParser::parse($time);
    }

    private function parseTimeCode(string $time): ?string
    {
        $upper = TimeParser::normalize($time);
        if ($upper === null) {
            return null;
        }

        return in_array($upper, ['NT', 'NAT', 'EXH']) ? $upper : null;
    }

    // ── Typ-Erkennung ─────────────────────────────────────────────────────────

    private function mapCourse(string $course): ?string
    {
        $valid = ['LCM', 'SCM', 'SCY', 'SCM16', 'SCM20', 'SCM33', 'SCY20', 'SCY27', 'SCY33', 'SCY36', 'OPEN'];
        $upper = strtoupper(trim($course));

        return in_array($upper, $valid) ? $upper : null;
    }

    // ── Meet importieren ─────────────────────────────────────────────────────

    private function mapEntryStatus(string $status): ?string
    {
        $valid = ['EXH', 'RJC', 'SICK', 'WDR'];
        $upper = strtoupper(trim($status));

        return in_array($upper, $valid) ? $upper : null;
    }

    // ── Sessions + Events ─────────────────────────────────────────────────────

    private function importResult(
        Meet $meet,
        SimpleXMLElement $resultXml,
        int $athleteId,
        int $clubId,
        LenexResolverService $resolver
    ): void {
        $swimEventId = $this->resolveSwimEventId($meet, (string) ($resultXml['eventid'] ?? ''), $resolver);

        if (! $swimEventId) {
            return;
        }

        $swimTime = $this->parseTime((string) ($resultXml['swimtime'] ?? ''));
        $statusCode = $this->mapResultStatus((string) ($resultXml['status'] ?? ''));
        $recordType = strtoupper((string) ($resultXml['recordtype'] ?? ''));

        $result = Result::updateOrCreate(
            [
                'meet_id' => $meet->id,
                'swim_event_id' => $swimEventId,
                'athlete_id' => $athleteId,
                'heat' => (int) ($resultXml['heatid'] ?? 0) ?: null,
                'lane' => (int) ($resultXml['lane'] ?? 0) ?: null,
            ],
            [
                'club_id' => $clubId,
                'swim_time' => $swimTime,
                'status' => $statusCode,
                'sport_class' => (string) ($resultXml['handicap'] ?? '') ?: null,
                'points' => (int) ($resultXml['points'] ?? 0) ?: null,
                'place' => (int) ($resultXml['place'] ?? 0) ?: null,
                'reaction_time' => $this->parseReactionTime((string) ($resultXml['reactiontime'] ?? '')),
                'comment' => (string) ($resultXml['comment'] ?? '') ?: null,
                'is_world_record' => str_contains($recordType, 'WR'),
                'is_european_record' => str_contains($recordType, 'ER'),
                'is_national_record' => str_contains($recordType, 'NR'),
                'lenex_result_id' => (string) ($resultXml['resultid'] ?? '') ?: null,
            ]
        );

        // Splits importieren
        if (isset($resultXml->SPLITS)) {
            $this->importSplits($result, $resultXml->SPLITS);
        }

        $this->stats['results']++;
    }

    private function mapResultStatus(string $status): ?string
    {
        $valid = ['EXH', 'DSQ', 'DNS', 'DNF', 'SICK', 'WDR'];
        $upper = strtoupper(trim($status));

        return in_array($upper, $valid) ? $upper : null;
    }

    // ── Clubs + Athletes ──────────────────────────────────────────────────────

    /**
     * LENEX Reaktionszeit "+14" oder "-5" → Hundertstelsekunden (int, kann negativ sein)
     * "0" oder leer → null
     */
    private function parseReactionTime(string $rt): ?int
    {
        $trimmed = trim($rt);
        if ($trimmed === '' || $trimmed === '0') {
            return null;
        }

        return (int) $trimmed;
    }

    private function importSplits(Result $result, SimpleXMLElement $splitsXml): void
    {
        $result->splits()->delete();

        foreach ($splitsXml->SPLIT as $splitXml) {
            $splitTime = $this->parseTime((string) ($splitXml['swimtime'] ?? ''));
            if (! $splitTime) {
                continue;
            }
            ResultSplit::create([
                'result_id' => $result->id,
                'distance' => (int) ($splitXml['distance'] ?? 0),
                'split_time' => $splitTime,
            ]);
        }
    }

    /**
     * Hilfsmethode: Primäre Sport-Klasse aus HANDICAP-Element lesen
     * (S-Klasse = free-Attribut).
     */
    private function extractPrimaryClassFromHandicap(SimpleXMLElement $handicapXml): ?string
    {
        $free = trim((string) ($handicapXml['free'] ?? ''));

        return $free && $free !== '0' ? 'S'.$free : null;
    }
}
