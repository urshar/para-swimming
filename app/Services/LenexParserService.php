<?php

namespace App\Services;

use App\Models\Club;
use App\Models\Entry;
use App\Models\Meet;
use App\Models\MeetFee;
use App\Models\MeetSession;
use App\Models\Nation;
use App\Models\RelayEntry;
use App\Models\RelayEntryMember;
use App\Models\RelayResult;
use App\Models\RelayResultMember;
use App\Models\Result;
use App\Models\ScoringGroup;
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
 *   results   → + Results, Splits, Staffelergebnisse (CLUB > RELAYS)
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
        'results_new' => 0,
        'results_ambiguous' => 0,
        'relay_results' => 0,
        'relay_entries' => 0,
    ];

    /**
     * resultid → Wertungsklasse der AGEGROUP, in der das Ergebnis platziert ist (z. B. handicap 14 →
     * Staffelklasse S14). Wird für Staffelergebnisse gebraucht; erste Platzierung gewinnt wie beim rankingIndex.
     *
     * @var array<string, array{handicap: string}>
     */
    private array $rankingGroupIndex = [];

    /**
     * LENEX athleteid → Athlet der Datenbank (bzw. null, wenn nicht zuordenbar) samt Kopie von Name, Geschlecht und
     * S-Klasse. Grundlage für die Staffelpositionen, die nur per athleteid auf Athleten verweisen, auch auf
     * Athleten eines anderen Vereins.
     *
     * @var array<string, array{athlete_id: ?int, first_name: string, last_name: string, gender: ?string, sport_class: ?string}>
     */
    private array $athleteIndex = [];

    /**
     * resultid → place (erste Platzierung die gefunden wird).
     * Wird aus EVENT > AGEGROUP > RANKINGS > RANKING aufgebaut.
     * place = -1 bedeutet DSQ/ungültig → wird als null gespeichert.
     */
    private array $rankingIndex = [];

    /**
     * LENEX heatid → Laufnummer aus EVENT > HEATS > HEAT. ENTRY/RESULT verweisen per heatid auf den Lauf, die
     * Laufnummer steht nur im HEAT-Element (z. B. heatid 2168 = Lauf 1).
     *
     * @var array<string, int>
     */
    private array $heatIndex = [];

    /**
     * Nummern der Bewerbe, die beim Anlegen als Rahmenbewerb (nicht gewertet) markiert werden — Auswahl auf der
     * Klärungsseite des Imports.
     *
     * @var list<int>
     */
    private array $unscoredEventNumbers = [];

    /**
     * IDs der nicht gewerteten Bewerbe der Veranstaltung (swim_event_id → true). Ihre Ergebnisse und Meldungen werden
     * nicht importiert.
     *
     * @var array<int, true>
     */
    private array $unscoredEventIds = [];

    // LenexResolverService wird als Parameter an import() übergeben,
    // nicht per Constructor — der Import ist zustandslos pro Aufruf.

    // ── Öffentliche Methoden ──────────────────────────────────────────────────

    /**
     * @param  list<int>  $unscoredEventNumbers  Bewerbe, die beim Anlegen als nicht gewertet markiert werden
     *
     * @throws Exception
     */
    public function import(
        string $filePath,
        LenexResolverService $resolver,
        ?int $forceMeetId = null,
        array $unscoredEventNumbers = []
    ): array {
        $this->unscoredEventNumbers = $unscoredEventNumbers;
        $this->unscoredEventIds = [];
        // Stats und Index für jeden Import-Aufruf zurücksetzen
        $this->stats = [
            'meets' => 0, 'clubs' => 0, 'athletes' => 0,
            'events' => 0, 'entries' => 0, 'results' => 0, 'results_new' => 0, 'results_ambiguous' => 0,
            'relay_results' => 0, 'relay_entries' => 0,
        ];
        $this->rankingIndex = [];
        $this->rankingGroupIndex = [];
        $this->athleteIndex = [];
        $this->heatIndex = [];

        $xml = $this->loadXml($filePath);
        $type = $this->detectType($xml);
        $this->buildHeatIndex($xml->MEETS->MEET[0]);

        // Ranking-Index aufbauen bevor Clubs/Results importiert werden
        if ($type === 'results') {
            $this->buildRankingIndex($xml->MEETS->MEET[0]);
        }

        $meet = $this->importMeet($xml, $resolver, $type, $forceMeetId);

        return [
            'type' => $type,
            'meet' => $meet,
            'stats' => $this->stats,
        ];
    }

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
     * Einzelbewerbe der Datei ohne jede Klassenangabe (keine AGEGROUP mit handicap) — mögliche Rahmenbewerbe, die auf
     * der Klärungsseite abgefragt werden. Staffeln bleiben außen vor.
     *
     * @return list<array{number: int, label: string, groups: string}>
     *
     * @throws Exception
     */
    public function unclassifiedEvents(string $filePath): array
    {
        $meetXml = $this->loadXml($filePath)->MEETS->MEET[0];
        $events = [];

        foreach ($meetXml->SESSIONS->SESSION ?? [] as $sessionXml) {
            foreach ($sessionXml->EVENTS->EVENT ?? [] as $eventXml) {
                $styleXml = $eventXml->SWIMSTYLE ?? null;
                if (! $styleXml || (int) ($styleXml['relaycount'] ?? 1) > 1) {
                    continue;
                }

                $groupNames = [];
                $hasClasses = false;
                foreach ($eventXml->AGEGROUPS->AGEGROUP ?? [] as $groupXml) {
                    $groupNames[] = (string) ($groupXml['name'] ?? '');
                    $hasClasses = $hasClasses || trim((string) ($groupXml['handicap'] ?? '')) !== '';
                }
                if ($hasClasses) {
                    continue;
                }

                $stroke = StrokeType::findByLenexCode((string) ($styleXml['stroke'] ?? ''));
                $events[] = [
                    'number' => (int) ($eventXml['number'] ?? 0),
                    'label' => trim(((int) ($styleXml['distance'] ?? 0)).' m '.($stroke?->name_de ?? (string) ($styleXml['stroke'] ?? ''))),
                    'groups' => implode(', ', array_filter($groupNames)),
                ];
            }
        }

        return $events;
    }

    // ── Typ-Erkennung ─────────────────────────────────────────────────────────

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

    /**
     * Extrahiert den XML-Inhalt aus einer LXF (ZIP) oder LEF/XML Datei.
     *
     * @throws Exception
     */
    private function extractXmlContent(string $filePath): string
    {
        // Prüfe ob es eine ZIP-Datei ist (LXF)
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

        // Über alle Vereine, Athleten und Staffeln suchen: SimpleXMLs Kettenzugriff ($meet->CLUBS->CLUB->...) prüft
        // nur jeweils das erste Element — hat der erste Athlet keine Ergebnisse, wäre die ganze Datei "structure".
        if ($meet->xpath('CLUBS/CLUB/ATHLETES/ATHLETE/RESULTS/RESULT | CLUBS/CLUB/RELAYS/RELAY/RESULTS/RESULT')) {
            return 'results';
        }

        if ($meet->xpath('CLUBS/CLUB/ATHLETES/ATHLETE/ENTRIES/ENTRY | CLUBS/CLUB/RELAYS/RELAY/ENTRIES/ENTRY')) {
            return 'entries';
        }

        return 'structure';
    }

    /**
     * true, wenn der Athlet Ergebnisse (bzw. bei Meldedateien Meldungen) hat und alle in nicht gewerteten Bewerben
     * liegen. Ohne Ergebnisse/Meldungen wird er normal behandelt.
     */
    private function onlyInUnscoredEvents(
        Meet $meet,
        SimpleXMLElement $athleteXml,
        string $type,
        LenexResolverService $resolver
    ): bool {
        if ($this->unscoredEventIds === []) {
            return false;
        }

        $items = $type === 'entries' ? ($athleteXml->ENTRIES->ENTRY ?? []) : ($athleteXml->RESULTS->RESULT ?? []);
        $count = 0;
        foreach ($items as $itemXml) {
            $count++;
            $swimEventId = $this->resolveSwimEventId($meet, (string) ($itemXml['eventid'] ?? ''), $resolver);
            if (! $swimEventId || ! isset($this->unscoredEventIds[$swimEventId])) {
                return false;
            }
        }

        return $count > 0;
    }

    /** Baut den heatid → Laufnummer Index aus EVENT > HEATS > HEAT. */
    private function buildHeatIndex(SimpleXMLElement $meetXml): void
    {
        foreach ($meetXml->SESSIONS->SESSION ?? [] as $sessionXml) {
            foreach ($sessionXml->EVENTS->EVENT ?? [] as $eventXml) {
                foreach ($eventXml->HEATS->HEAT ?? [] as $heatXml) {
                    $heatId = (string) ($heatXml['heatid'] ?? '');
                    $number = (int) ($heatXml['number'] ?? 0);
                    if ($heatId !== '' && $number > 0) {
                        $this->heatIndex[$heatId] = $number;
                    }
                }
            }
        }
    }

    /**
     * Laufnummer zu ENTRY/RESULT heatid. Ohne passendes HEAT-Element (Datei ohne HEATS) bleibt der Rohwert, wie ihn
     * manche Programme als Laufnummer schreiben.
     */
    private function heatNumber(SimpleXMLElement $xml): ?int
    {
        $heatId = (string) ($xml['heatid'] ?? '');

        return $this->heatIndex[$heatId] ?? ((int) $heatId ?: null);
    }

    /**
     * Baut einen resultid → place Index aus EVENT > AGEGROUP > RANKINGS > RANKING.
     *
     * Ein Result kann in mehreren AGEGROUPs auftauchen (Gesamtwertung + Klassenwertung).
     * Die erste gefundene Platzierung gewinnt (spezifischere AGEGROUP kommt zuerst).
     * place = -1 (DSQ/ungültig) → null.
     */
    private function buildRankingIndex(SimpleXMLElement $meetXml): void
    {
        if (! isset($meetXml->SESSIONS)) {
            return;
        }

        foreach ($meetXml->SESSIONS->SESSION as $sessionXml) {
            if (! isset($sessionXml->EVENTS)) {
                continue;
            }
            foreach ($sessionXml->EVENTS->EVENT as $eventXml) {
                if (! isset($eventXml->AGEGROUPS)) {
                    continue;
                }
                foreach ($eventXml->AGEGROUPS->AGEGROUP as $agegroupXml) {
                    if (! isset($agegroupXml->RANKINGS)) {
                        continue;
                    }
                    foreach ($agegroupXml->RANKINGS->RANKING as $rankingXml) {
                        $resultId = (string) ($rankingXml['resultid'] ?? '');
                        $place = (int) ($rankingXml['place'] ?? -1);

                        if ($resultId === '') {
                            continue;
                        }

                        // Erste gefundene Platzierung gewinnt — nicht überschreiben
                        if (! isset($this->rankingIndex[$resultId])) {
                            $this->rankingIndex[$resultId] = $place > 0 ? $place : null;
                        }
                        if (! isset($this->rankingGroupIndex[$resultId])) {
                            $this->rankingGroupIndex[$resultId] = [
                                'handicap' => trim((string) ($agegroupXml['handicap'] ?? '')),
                            ];
                        }
                    }
                }
            }
        }
    }

    private function importMeet(
        SimpleXMLElement $xml,
        LenexResolverService $resolver,
        string $type,
        ?int $forceMeetId = null
    ): Meet {
        $meetXml = $xml->MEETS->MEET[0];

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

        // Wenn ein bestehendes Meet erzwungen wird (aus der Meet-Auswahl),
        // dieses direkt laden statt updateOrCreate — vermeidet doppelte Meets.
        if ($forceMeetId) {
            $meet = Meet::findOrFail($forceMeetId);
        } else {
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
        }
        $this->stats['meets']++;

        // Meldegelder der Veranstaltung (MEET > FEES)
        if (isset($meetXml->FEES)) {
            $this->importFees($meet, $meetXml->FEES, null);
        }

        // Sessions + Events
        if (isset($meetXml->SESSIONS)) {
            $this->importSessions($meet, $meetXml->SESSIONS, $resolver);
        }

        // Rahmenbewerbe (nicht gewertet): deren Ergebnisse/Meldungen und Schwimmer werden übersprungen.
        $this->unscoredEventIds = SwimEvent::where('meet_id', $meet->id)->where('is_scored', false)
            ->pluck('id')->mapWithKeys(fn (int $id) => [$id => true])->all();

        // Clubs + Athletes + Entries/Results
        if (in_array($type, ['entries', 'results']) && isset($meetXml->CLUBS)) {
            $this->importClubs($meet, $meetXml->CLUBS, $resolver, $type);
        }

        return $meet;
    }

    // ── Meet importieren ─────────────────────────────────────────────────────

    private function mapCourse(string $course): ?string
    {
        $valid = ['LCM', 'SCM', 'SCY', 'SCM16', 'SCM20', 'SCM33', 'SCY20', 'SCY27', 'SCY33', 'SCY36', 'OPEN'];
        $upper = strtoupper(trim($course));

        return in_array($upper, $valid) ? $upper : null;
    }

    // ── Sessions + Events ─────────────────────────────────────────────────────

    private function mapTiming(string $timing): ?string
    {
        $valid = ['AUTOMATIC', 'SEMIAUTOMATIC', 'MANUAL3', 'MANUAL2', 'MANUAL1'];
        $upper = strtoupper(trim($timing));

        return in_array($upper, $valid) ? $upper : null;
    }

    private function importSessions(
        Meet $meet,
        SimpleXMLElement $sessionsXml,
        LenexResolverService $resolver
    ): void {
        foreach ($sessionsXml->SESSION as $sessionXml) {
            $sessionNumber = (int) ($sessionXml['number'] ?? 1);

            $this->importSessionDate($meet, $sessionNumber, $sessionXml);
            if (isset($sessionXml->FEES)) {
                $this->importFees($meet, $sessionXml->FEES, $sessionNumber);
            }

            if (! isset($sessionXml->EVENTS)) {
                continue;
            }

            foreach ($sessionXml->EVENTS->EVENT as $eventXml) {
                $this->importEvent($meet, $eventXml, $sessionNumber, $resolver);
            }
        }
    }

    /**
     * Datum und Startzeit eines Abschnitts (SESSION@date "YYYY-MM-DD", SESSION@daytime "HH:MM") in meet_sessions
     * übernehmen. Ungültige oder fehlende Werte werden nicht gespeichert; fehlt beides, bleibt ein bereits
     * gepflegter Eintrag unverändert (kein Überschreiben mit leeren Werten).
     */
    private function importSessionDate(Meet $meet, int $sessionNumber, SimpleXMLElement $sessionXml): void
    {
        $date = (string) ($sessionXml['date'] ?? '');
        $daytime = (string) ($sessionXml['daytime'] ?? '');

        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
        $daytime = preg_match('/^\d{2}:\d{2}/', $daytime) ? substr($daytime, 0, 5) : null;

        if ($date === null && $daytime === null) {
            return;
        }

        MeetSession::updateOrCreate(
            ['meet_id' => $meet->id, 'number' => $sessionNumber],
            ['date' => $date, 'daytime' => $daytime]
        );
    }

    /**
     * FEES > FEE einer Veranstaltung (sessionNumber null) bzw. eines Abschnitts in meet_fees übernehmen. Unbekannte
     * Typen und Gebühren ohne gültigen Betrag werden übersprungen; vorhandene Typen werden überschrieben.
     */
    private function importFees(Meet $meet, SimpleXMLElement $feesXml, ?int $sessionNumber): void
    {
        foreach ($feesXml->FEE as $feeXml) {
            $type = (string) ($feeXml['type'] ?? '');
            $cents = $this->feeCents($feeXml);

            if (! array_key_exists($type, MeetFee::TYPES) || $cents === null) {
                continue;
            }

            MeetFee::updateOrCreate(
                ['meet_id' => $meet->id, 'session_number' => $sessionNumber, 'type' => $type],
                ['amount_cents' => $cents, 'currency' => (string) ($feeXml['currency'] ?? '') ?: 'EUR']
            );
        }
    }

    /** FEE@value (LENEX: ganze Cent) → int, oder null bei fehlendem/ungültigem Wert. */
    private function feeCents(SimpleXMLElement $feeXml): ?int
    {
        $value = (string) ($feeXml['value'] ?? '');

        return ctype_digit($value) ? (int) $value : null;
    }

    // ── Clubs + Athletes ──────────────────────────────────────────────────────

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

        // EVENT > FEE (Gebühr je Meldung) nur übernehmen, wenn vorhanden — sonst bleibt ein gepflegter Wert stehen.
        $feeCents = isset($eventXml->FEE) ? $this->feeCents($eventXml->FEE) : null;

        $swimEvent = SwimEvent::updateOrCreate(
            [
                'meet_id' => $meet->id,
                'event_number' => $eventNumber,
            ],
            ($feeCents !== null ? ['fee_cents' => $feeCents] : []) + [
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
            ] + (in_array($eventNumber, $this->unscoredEventNumbers, true) ? ['is_scored' => false] : [])
        );

        if (isset($eventXml->AGEGROUPS)) {
            $this->importScoringGroups($swimEvent, $eventXml->AGEGROUPS);
        }

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
     * Legt die Wertungsgruppen eines Bewerbs aus seinen AGEGROUPs an bzw. aktualisiert sie (über die agegroupid).
     * Manuell angelegte Gruppen (ohne LENEX-ID) bleiben unberührt.
     *
     * - Geschlecht aus gender (fehlt es: A = alle), Klassen aus handicap ("1,2,3" bzw. Leerzeichen-getrennt),
     *   Alter aus agemin/agemax (-1 = keine Grenze).
     * - Titel aus dem Namen: "ÖSTM…" → Staatsmeisterschaft, "ÖM…" → Meisterschaft.
     */
    private function importScoringGroups(SwimEvent $swimEvent, SimpleXMLElement $ageGroupsXml): void
    {
        $order = 0;
        foreach ($ageGroupsXml->AGEGROUP as $ageGroupXml) {
            $order++;
            $lenexId = trim((string) ($ageGroupXml['agegroupid'] ?? ''));
            $name = trim((string) ($ageGroupXml['name'] ?? ''));
            $gender = strtoupper(trim((string) ($ageGroupXml['gender'] ?? '')));
            $ageMin = (int) ($ageGroupXml['agemin'] ?? -1);
            $ageMax = (int) ($ageGroupXml['agemax'] ?? -1);
            $classes = ScoringGroup::parseClassNumbers((string) ($ageGroupXml['handicap'] ?? ''));
            $normalizedName = mb_strtoupper(str_replace(' ', '', $name));

            $values = [
                'name' => $name !== '' ? $name : ($classes !== [] ? 'Klassen '.implode(', ', $classes) : 'Alle'),
                'gender' => in_array($gender, ['M', 'F', 'X'], true) ? $gender : 'A',
                'sport_classes' => $classes !== [] ? implode(',', $classes) : null,
                'age_min' => $ageMin >= 0 ? $ageMin : null,
                'age_max' => $ageMax >= 0 ? $ageMax : null,
                'title' => match (true) {
                    str_starts_with($normalizedName, 'ÖSTM') => ScoringGroup::TITLE_STATE,
                    str_starts_with($normalizedName, 'ÖM') => ScoringGroup::TITLE_NATIONAL,
                    default => null,
                },
                'sort_order' => $order,
            ];

            if ($lenexId !== '') {
                ScoringGroup::updateOrCreate(['swim_event_id' => $swimEvent->id, 'lenex_agegroup_id' => $lenexId], $values);
            } else {
                ScoringGroup::create(['swim_event_id' => $swimEvent->id] + $values);
            }
        }
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

    // ── Entries ───────────────────────────────────────────────────────────────

    private function mapGender(string $gender): string
    {
        return match (strtoupper(trim($gender))) {
            'M' => 'M',
            'F' => 'F',
            'X' => 'X',
            default => 'A',
        };
    }

    // ── Results ───────────────────────────────────────────────────────────────

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

    // ── Sport-Klasse aus HANDICAP + Event-Stroke ableiten ────────────────────

    private function importClubs(
        Meet $meet,
        SimpleXMLElement $clubsXml,
        LenexResolverService $resolver,
        string $type
    ): void {
        // Staffeln erst nach allen Athleten: ihre Positionen verweisen per athleteid auch auf Athleten anderer Vereine.
        $relayClubs = [];

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

            if (in_array($type, ['entries', 'results'], true) && isset($clubXml->RELAYS)) {
                $relayClubs[] = [$clubXml, $club];
            }

            if (! isset($clubXml->ATHLETES)) {
                continue;
            }

            foreach ($clubXml->ATHLETES->ATHLETE as $athleteXml) {
                $this->importAthlete($meet, $athleteXml, $club, $resolver, $type);
            }
        }

        // Meldedatei: Staffelmeldungen (RELAY > ENTRIES), Ergebnisdatei: Staffelergebnisse (RELAY > RESULTS).
        foreach ($relayClubs as [$clubXml, $club]) {
            foreach ($clubXml->RELAYS->RELAY as $relayXml) {
                if ($type === 'entries') {
                    foreach ($relayXml->ENTRIES->ENTRY ?? [] as $entryXml) {
                        $this->importRelayEntry($meet, $relayXml, $entryXml, $club, $resolver);
                    }

                    continue;
                }
                foreach ($relayXml->RESULTS->RESULT ?? [] as $resultXml) {
                    $this->importRelayResult($meet, $relayXml, $resultXml, $club, $resolver);
                }
            }
        }
    }

    // ── Event-ID Auflösung ────────────────────────────────────────────────────

    private function importAthlete(
        Meet $meet,
        SimpleXMLElement $athleteXml,
        Club $club,
        LenexResolverService $resolver,
        string $type
    ): void {
        // Schwimmt jemand nur in Rahmenbewerben (z. B. Schnupperbewerb), wird er weder gesucht noch zur Klärung
        // vorgemerkt — diese Schwimmer kommen nicht ins System.
        if ($this->onlyInUnscoredEvents($meet, $athleteXml, $type, $resolver)) {
            return;
        }

        $nationCode = (string) ($athleteXml['nation'] ?? $club->nation?->code ?? '');
        $nation = Nation::where('code', $nationCode)->first();

        $athlete = $resolver->resolveAthlete($athleteXml, $club->id, $nation?->id ?? 0);

        $lenexAthleteId = (string) ($athleteXml['athleteid'] ?? '');
        if ($lenexAthleteId !== '') {
            $gender = strtoupper((string) ($athleteXml['gender'] ?? ''));
            $this->athleteIndex[$lenexAthleteId] = [
                'athlete_id' => $athlete?->id,
                'first_name' => (string) ($athleteXml['firstname'] ?? ''),
                'last_name' => (string) ($athleteXml['lastname'] ?? ''),
                'gender' => in_array($gender, ['M', 'F'], true) ? $gender : null,
                'sport_class' => isset($athleteXml->HANDICAP)
                    ? $this->extractPrimaryClassFromHandicap($athleteXml->HANDICAP)
                    : null,
            ];
        }

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
                $this->importResult(
                    $meet, $resultXml, $athlete->id, $club->id, $resolver,
                    $athleteXml->HANDICAP ?? null
                );
            }
        }
    }

    // ── Ranking-Index ─────────────────────────────────────────────────────────

    private function importEntry(
        Meet $meet,
        SimpleXMLElement $entryXml,
        int $athleteId,
        int $clubId,
        LenexResolverService $resolver
    ): void {
        $swimEventId = $this->resolveSwimEventId($meet, (string) ($entryXml['eventid'] ?? ''), $resolver);

        if (! $swimEventId || isset($this->unscoredEventIds[$swimEventId])) {
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
                'heat' => $this->heatNumber($entryXml),
                'lane' => (int) ($entryXml['lane'] ?? 0) ?: null,
            ]
        );

        $this->stats['entries']++;
    }

    // ── Sport-Klassen aus AGEGROUPS extrahieren ───────────────────────────────

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

    // ── XML laden ────────────────────────────────────────────────────────────

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

    // ── Format-Konvertierungen ────────────────────────────────────────────────

    private function mapEntryStatus(string $status): ?string
    {
        $valid = ['EXH', 'RJC', 'SICK', 'WDR'];
        $upper = strtoupper(trim($status));

        return in_array($upper, $valid) ? $upper : null;
    }

    private function importResult(
        Meet $meet,
        SimpleXMLElement $resultXml,
        int $athleteId,
        int $clubId,
        LenexResolverService $resolver,
        ?SimpleXMLElement $handicapXml = null
    ): void {
        $header = $this->resultHeader($meet, $resultXml, $resolver);
        if ($header === null || isset($this->unscoredEventIds[$header['swimEventId']])) {
            return;
        }
        ['swimEventId' => $swimEventId, 'swimTime' => $swimTime, 'status' => $statusCode, 'lenexResultId' => $lenexResultId] = $header;

        // place steht nicht im RESULT-Element sondern in EVENT > AGEGROUP > RANKING.
        $place = $this->rankingIndex[$lenexResultId]
            ?? ((int) ($resultXml['place'] ?? 0) ?: null);

        // sport_class: Splash speichert kein handicap-Attribut im RESULT.
        // Ableitung aus ATHLETE.HANDICAP + Stroke des Events:
        //   FREE/BACK/FLY → S (free-Attribut), BREAST → SB (breast), MEDLEY → SM (medley)
        $sportClass = $this->deriveSportClass($swimEventId, $handicapXml)
            ?: ((string) ($resultXml['handicap'] ?? '') ?: null); // Fallback standard LENEX

        $heat = $this->heatNumber($resultXml);
        $lane = (int) ($resultXml['lane'] ?? 0) ?: null;

        $values = [
            'swim_event_id' => $swimEventId,
            'athlete_id' => $athleteId,
            'club_id' => $clubId,
            'heat' => $heat,
            'lane' => $lane,
            'swim_time' => $swimTime,
            'status' => $statusCode,
            'sport_class' => $sportClass,
            'place' => $place,
            'reaction_time' => $this->parseReactionTime((string) ($resultXml['reactiontime'] ?? '')),
            'lenex_result_id' => $lenexResultId,
        ] + $this->sharedResultValues($resultXml);

        $result = $this->findExistingResult($meet, $swimEventId, $athleteId, $heat, $lane, $lenexResultId);
        if ($result) {
            // Die Datei gewinnt; fehlen darin Punkte, Platz oder Sportklasse, bleiben die vorhandenen Werte stehen.
            foreach (['points', 'place', 'sport_class'] as $keep) {
                if ($values[$keep] === null) {
                    unset($values[$keep]);
                }
            }
            $result->update($values);
        } else {
            $result = Result::create(['meet_id' => $meet->id] + $values);
            $this->stats['results_new']++;
        }

        // Splits importieren
        if (isset($resultXml->SPLITS)) {
            $this->replaceSplits($result, $resultXml->SPLITS);
        }

        $this->stats['results']++;
    }

    /**
     * Vorhandenes Einzelergebnis zu einem importierten RESULT, in dieser Reihenfolge:
     *   1. Veranstaltung + LENEX-resultid (erneuter Import derselben Datei),
     *   2. Veranstaltung + Bewerb + Athlet + Lauf + Bahn,
     *   3. Veranstaltung + Bewerb + Athlet, wenn das vorhandene Ergebnis keinen Lauf und keine Bahn hat (Altbestand
     *      aus anderer Quelle) — nur bei genau einem Treffer; mehrere Treffer werden gezählt und nicht zugeordnet.
     * Vorlauf, Finale und Stechen sind in LENEX eigene Bewerbe, je Bewerb kommt ein Athlet also nur einmal vor.
     */
    private function findExistingResult(
        Meet $meet,
        int $swimEventId,
        int $athleteId,
        ?int $heat,
        ?int $lane,
        ?string $lenexResultId
    ): ?Result {
        if ($lenexResultId !== null) {
            $byLenexId = Result::where('meet_id', $meet->id)
                ->where('swim_event_id', $swimEventId)
                ->where('lenex_result_id', $lenexResultId)
                ->first();
            if ($byLenexId) {
                return $byLenexId;
            }
        }

        $sameAthlete = Result::where('meet_id', $meet->id)
            ->where('swim_event_id', $swimEventId)
            ->where('athlete_id', $athleteId);

        $exact = (clone $sameAthlete)->where('heat', $heat)->where('lane', $lane)->first();
        if ($exact) {
            return $exact;
        }

        $withoutHeatAndLane = (clone $sameAthlete)->whereNull('heat')->whereNull('lane')->limit(2)->get();
        if ($withoutHeatAndLane->count() > 1) {
            $this->stats['results_ambiguous']++;

            return null;
        }

        return $withoutHeatAndLane->first();
    }

    /**
     * Importiert eine Staffelmeldung (CLUB > RELAYS > RELAY > ENTRIES > ENTRY) samt Schwimmern.
     *
     * - Abgleich: Veranstaltung + Bewerb + Verein + RELAY number; sonst die n-te Meldung des Vereins im Bewerb ohne
     *   Nummer (nach Anlage sortiert, wie der Meldeexport nummeriert) — so erzeugt das Zurückspielen einer
     *   exportierten Meldedatei keine Doppelten.
     * - Staffelklasse aus RELAY handicap, sonst aus den Klassen der Schwimmer (RelayClassValidator).
     * - Neue Meldungen sind bestätigt; status="EXH" = außer Konkurrenz.
     * - Schwimmer ohne Athleten-Datensatz (unbekannt, nicht aufgelöst) werden ausgelassen.
     */
    private function importRelayEntry(
        Meet $meet,
        SimpleXMLElement $relayXml,
        SimpleXMLElement $entryXml,
        Club $club,
        LenexResolverService $resolver
    ): void {
        $swimEventId = $this->resolveSwimEventId($meet, (string) ($entryXml['eventid'] ?? ''), $resolver);
        if (! $swimEventId || isset($this->unscoredEventIds[$swimEventId])) {
            return;
        }

        $relayNumber = (int) ($relayXml['number'] ?? 0) ?: null;
        $members = [];
        foreach ($entryXml->RELAYPOSITIONS->RELAYPOSITION ?? [] as $positionXml) {
            $position = (int) ($positionXml['number'] ?? 0);
            $athlete = $this->athleteIndex[(string) ($positionXml['athleteid'] ?? '')] ?? null;
            if ($position < 1 || ! ($athlete['athlete_id'] ?? null)) {
                continue;
            }
            $members[$athlete['athlete_id']] = [
                'athlete_id' => $athlete['athlete_id'],
                'position' => $position,
                'sport_class' => $athlete['sport_class'],
            ];
        }

        $handicap = trim((string) ($relayXml['handicap'] ?? ''));
        $relayClass = is_numeric($handicap) && (int) $handicap > 0
            ? 'S'.(int) $handicap
            : (new RelayClassValidator)->resolveRelayClass(array_values(array_filter(array_column($members, 'sport_class'))));

        $relayEntry = $this->findRelayEntry($meet->id, $swimEventId, $club->id, $relayNumber)
            ?? new RelayEntry(['meet_id' => $meet->id, 'swim_event_id' => $swimEventId, 'club_id' => $club->id, 'status' => 'confirmed']);
        $entryTime = (string) ($entryXml['entrytime'] ?? '');
        $relayEntry->fill([
            'relay_number' => $relayNumber,
            'name' => (string) ($relayXml['name'] ?? '') ?: $relayEntry->name,
            'relay_class' => $relayClass ?? $relayEntry->relay_class,
            'entry_time' => $this->parseTime($entryTime),
            'entry_time_code' => $this->parseTimeCode($entryTime),
            'entry_course' => $this->mapCourse((string) ($entryXml['entrycourse'] ?? '')),
            'is_exhibition' => strtoupper((string) ($entryXml['status'] ?? '')) === 'EXH',
        ])->save();

        if ($members !== []) {
            $relayEntry->members()->delete();
            foreach ($members as $member) {
                RelayEntryMember::create(['relay_entry_id' => $relayEntry->id] + $member);
            }
        }

        $this->stats['relay_entries']++;
    }

    /** Vorhandene Staffelmeldung zu RELAY number (siehe importRelayEntry). */
    private function findRelayEntry(int $meetId, int $swimEventId, int $clubId, ?int $relayNumber): ?RelayEntry
    {
        if ($relayNumber === null) {
            return null;
        }

        $query = RelayEntry::where('meet_id', $meetId)->where('swim_event_id', $swimEventId)->where('club_id', $clubId);
        $byNumber = (clone $query)->where('relay_number', $relayNumber)->first();
        if ($byNumber) {
            return $byNumber;
        }

        // App-Meldung ohne Nummer: die n-te nach Anlage — genau so zählt der Meldeexport (buildRelays).
        $nth = $query->orderBy('id')->offset($relayNumber - 1)->first();

        return $nth !== null && $nth->relay_number === null ? $nth : null;
    }

    /**
     * Importiert ein Staffelergebnis (CLUB > RELAYS > RELAY > RESULTS > RESULT) samt Positionen und Zwischenzeiten.
     *
     * - Geschlecht der Mannschaft aus RELAY gender (M/F/X), Staffelklasse aus der AGEGROUP, in der das Ergebnis
     *   platziert ist (handicap 14 → S14), Platz aus deren RANKING.
     * - Staffeln ohne Zeit und ohne Status (nicht angetreten, nichts erfasst) werden übersprungen.
     * - Erneuter Import aktualisiert über meet + lenex_result_id, statt doppelt anzulegen.
     */
    private function importRelayResult(
        Meet $meet,
        SimpleXMLElement $relayXml,
        SimpleXMLElement $resultXml,
        Club $club,
        LenexResolverService $resolver
    ): void {
        $header = $this->resultHeader($meet, $resultXml, $resolver);
        if ($header === null || (! $header['swimTime'] && $header['status'] === null)
            || isset($this->unscoredEventIds[$header['swimEventId']])) {
            return;
        }
        ['swimEventId' => $swimEventId, 'swimTime' => $swimTime, 'status' => $statusCode, 'lenexResultId' => $lenexResultId] = $header;

        $handicap = $lenexResultId !== null ? ($this->rankingGroupIndex[$lenexResultId]['handicap'] ?? '') : '';
        // Gruppe mit mehreren oder ohne Klassen: Staffelklasse aus RELAY handicap (schreibt auch der eigene Export).
        if (! is_numeric($handicap)) {
            $handicap = trim((string) ($relayXml['handicap'] ?? ''));
        }
        $relayNumber = (int) ($relayXml['number'] ?? 0) ?: null;

        $members = [];
        foreach ($resultXml->RELAYPOSITIONS->RELAYPOSITION ?? [] as $positionXml) {
            $position = (int) ($positionXml['number'] ?? 0);
            if ($position < 1) {
                continue;
            }
            $athlete = $this->athleteIndex[(string) ($positionXml['athleteid'] ?? '')] ?? null;
            $members[$position] = [
                'position' => $position,
                'athlete_id' => $athlete['athlete_id'] ?? null,
                'first_name' => $athlete['first_name'] ?? null,
                'last_name' => $athlete['last_name'] ?? null,
                'gender' => $athlete['gender'] ?? null,
                'sport_class' => $athlete['sport_class'] ?? null,
                'reaction_time' => $this->parseReactionTime((string) ($positionXml['reactiontime'] ?? '')),
            ];
        }

        $relayGender = strtoupper((string) ($relayXml['gender'] ?? ''));
        if (! in_array($relayGender, ['M', 'F', 'X'], true)) {
            $relayGender = RelayResult::genderFromMembers(array_column($members, 'gender'));
        }

        $identity = $lenexResultId !== null
            ? ['meet_id' => $meet->id, 'lenex_result_id' => $lenexResultId]
            : ['meet_id' => $meet->id, 'swim_event_id' => $swimEventId, 'club_id' => $club->id, 'relay_number' => $relayNumber];

        $relayResult = RelayResult::updateOrCreate($identity, [
            'swim_event_id' => $swimEventId,
            'club_id' => $club->id,
            'relay_number' => $relayNumber,
            'name' => (string) ($relayXml['name'] ?? '') ?: null,
            'gender' => $relayGender,
            'relay_class' => is_numeric($handicap) && (int) $handicap > 0 ? 'S'.(int) $handicap : null,
            'swim_time' => $swimTime,
            'status' => $statusCode,
            'place' => $lenexResultId !== null ? ($this->rankingIndex[$lenexResultId] ?? null) : null,
            'heat' => $this->heatNumber($resultXml),
            'lane' => (int) ($resultXml['lane'] ?? 0) ?: null,
        ] + $this->sharedResultValues($resultXml));

        $relayResult->members()->delete();
        foreach ($members as $member) {
            RelayResultMember::create(['relay_result_id' => $relayResult->id] + $member);
        }

        $this->replaceSplits($relayResult, $resultXml->SPLITS ?? null);

        $this->stats['relay_results']++;
    }

    private function mapResultStatus(string $status): ?string
    {
        $valid = ['EXH', 'DSQ', 'DNS', 'DNF', 'SICK', 'WDR'];
        $upper = strtoupper(trim($status));

        return in_array($upper, $valid) ? $upper : null;
    }

    // ── Enum-Mappings ─────────────────────────────────────────────────────────

    /**
     * Leitet die persönliche Sport-Klasse eines Athleten für ein bestimmtes Event ab.
     *
     * Splash speichert kein handicap-Attribut im RESULT — die Klasse steht
     * im ATHLETE.HANDICAP-Element und wird über den Stroke des Events zugeordnet:
     *   FREE / BACK / FLY / UNKNOWN → S  (HANDICAP.free)
     *   BREAST                      → SB (HANDICAP.breast)
     *   MEDLEY / IMRELAY            → SM (HANDICAP.medley)
     */
    private function deriveSportClass(int $swimEventId, ?SimpleXMLElement $handicapXml): ?string
    {
        if ($handicapXml === null) {
            return null;
        }

        // Stroke des Events aus DB holen (ist bereits importiert)
        $strokeCode = SwimEvent::where('id', $swimEventId)
            ->with('strokeType')
            ->first()
            ?->strokeType
            ?->lenex_code;

        if (! $strokeCode) {
            return null;
        }

        $strokeUpper = strtoupper($strokeCode);

        $classNumber = match (true) {
            $strokeUpper === 'BREAST' => trim((string) ($handicapXml['breast'] ?? '')),
            in_array($strokeUpper, ['MEDLEY', 'IMRELAY']) => trim((string) ($handicapXml['medley'] ?? '')),
            default => trim((string) ($handicapXml['free'] ?? '')),
        };

        if ($classNumber === '' || $classNumber === '0') {
            return null;
        }

        $prefix = match (true) {
            $strokeUpper === 'BREAST' => 'SB',
            in_array($strokeUpper, ['MEDLEY', 'IMRELAY']) => 'SM',
            default => 'S',
        };

        return $prefix.$classNumber;
    }

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

    /** Ersetzt die Zwischenzeiten eines Einzel- oder Staffelergebnisses durch die aus SPLITS. */
    private function replaceSplits(Result|RelayResult $result, ?SimpleXMLElement $splitsXml): void
    {
        $result->splits()->delete();

        foreach ($splitsXml->SPLIT ?? [] as $splitXml) {
            $splitTime = $this->parseTime((string) ($splitXml['swimtime'] ?? ''));
            if ($splitTime) {
                $result->splits()->create([
                    'distance' => (int) ($splitXml['distance'] ?? 0),
                    'split_time' => $splitTime,
                ]);
            }
        }
    }

    /**
     * Gemeinsamer Kopf von Einzel- und Staffelergebnis: Bewerb auflösen, Zeit, Status, LENEX-resultid.
     * null, wenn der Bewerb nicht zuordenbar ist.
     *
     * @return array{swimEventId: int, swimTime: ?int, status: ?string, lenexResultId: ?string}|null
     */
    private function resultHeader(Meet $meet, SimpleXMLElement $resultXml, LenexResolverService $resolver): ?array
    {
        $swimEventId = $this->resolveSwimEventId($meet, (string) ($resultXml['eventid'] ?? ''), $resolver);
        if (! $swimEventId) {
            return null;
        }

        return [
            'swimEventId' => $swimEventId,
            'swimTime' => $this->parseTime((string) ($resultXml['swimtime'] ?? '')),
            'status' => $this->mapResultStatus((string) ($resultXml['status'] ?? '')),
            'lenexResultId' => (string) ($resultXml['resultid'] ?? '') ?: null,
        ];
    }

    /**
     * Felder, die Einzel- und Staffelergebnis gleich aus dem RESULT übernehmen: Punkte, Kommentar, Rekordkürzel.
     *
     * @return array<string, mixed>
     */
    private function sharedResultValues(SimpleXMLElement $resultXml): array
    {
        $recordType = strtoupper((string) ($resultXml['recordtype'] ?? ''));

        return [
            'points' => (int) ($resultXml['points'] ?? 0) ?: null,
            'comment' => (string) ($resultXml['comment'] ?? '') ?: null,
            'is_world_record' => str_contains($recordType, 'WR'),
            'is_european_record' => str_contains($recordType, 'ER'),
            'is_national_record' => str_contains($recordType, 'NR'),
        ];
    }

    /**
     * Primäre Sport-Klasse aus HANDICAP-Element lesen (S-Klasse = free-Attribut).
     */
    private function extractPrimaryClassFromHandicap(SimpleXMLElement $handicapXml): ?string
    {
        $free = trim((string) ($handicapXml['free'] ?? ''));

        return $free && $free !== '0' ? 'S'.$free : null;
    }
}
