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
    public function import(string $filePath, LenexResolverService $resolver): array
    {
        $xml = $this->loadXml($filePath);
        $type = $this->detectType($xml);

        $meet = $this->importMeet($xml, $resolver, $type);

        return [
            'type' => $type,
            'meet' => $meet,
            'stats' => $this->stats,
        ];
    }

    // ── Typ-Erkennung ─────────────────────────────────────────────────────────

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

    // ── Meet importieren ─────────────────────────────────────────────────────

    private function importMeet(
        SimpleXMLElement $xml,
        LenexResolverService $resolver,
        string $type
    ): Meet {
        $meetXml = $xml->MEETS->MEET[0];

        $nationCode = (string) ($meetXml['nation'] ?? '');
        $nation = Nation::where('code', $nationCode)->first();

        $meet = Meet::updateOrCreate(
            [
                'lenex_meet_id' => (string) ($meetXml['meetid'] ?? ''),
                'name' => (string) ($meetXml['name'] ?? ''),
                'start_date' => (string) ($meetXml['startdate'] ?? ''),
            ],
            [
                'city' => (string) ($meetXml['city'] ?? ''),
                'nation_id' => $nation?->id,
                'course' => $this->mapCourse((string) ($meetXml['course'] ?? 'LCM')),
                'end_date' => (string) ($meetXml['stopdate'] ?? '') ?: null,
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

    // ── Sessions + Events ─────────────────────────────────────────────────────

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

        $swimEvent = SwimEvent::updateOrCreate(
            [
                'meet_id' => $meet->id,
                'lenex_event_id' => $lenexEventId ?: null,
                'event_number' => (int) ($eventXml['number'] ?? 0),
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
                'sport_classes' => (string) ($eventXml['sportclasses'] ?? '') ?: null,
                'timing' => $this->mapTiming((string) ($eventXml['timing'] ?? '')),
                'lenex_event_id' => $lenexEventId ?: null,
            ]
        );

        // Resolver-Cache für spätere Entry/Result-Zuordnung
        if ($lenexEventId) {
            $resolver->addToEventCache($lenexEventId, $swimEvent->id);
        }

        $this->stats['events']++;
    }

    // ── Clubs + Athletes ──────────────────────────────────────────────────────

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

    // ── Entries ───────────────────────────────────────────────────────────────

    private function importEntry(
        Meet $meet,
        SimpleXMLElement $entryXml,
        int $athleteId,
        int $clubId,
        LenexResolverService $resolver
    ): void {
        $lenexEventId = (string) ($entryXml['eventid'] ?? '');
        $swimEventId = $resolver->getEventIdFromCache($lenexEventId);

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

    // ── Results ───────────────────────────────────────────────────────────────

    private function importResult(
        Meet $meet,
        SimpleXMLElement $resultXml,
        int $athleteId,
        int $clubId,
        LenexResolverService $resolver
    ): void {
        $lenexEventId = (string) ($resultXml['eventid'] ?? '');
        $swimEventId = $resolver->getEventIdFromCache($lenexEventId);

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

    // ── XML laden ────────────────────────────────────────────────────────────

    /**
     * @throws Exception
     */
    private function loadXml(string $filePath): SimpleXMLElement
    {
        if (! file_exists($filePath)) {
            throw new Exception('Datei nicht gefunden: '.$filePath);
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($filePath, 'SimpleXMLElement', LIBXML_NOCDATA);

        if ($xml === false) {
            $errors = array_map(fn ($e) => $e->message, libxml_get_errors());
            libxml_clear_errors();
            throw new Exception('XML-Fehler: '.implode(', ', $errors));
        }

        return $xml;
    }

    // ── Format-Konvertierungen ────────────────────────────────────────────────

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

    // ── Enum-Mappings ─────────────────────────────────────────────────────────

    private function mapCourse(string $course): ?string
    {
        $valid = ['LCM', 'SCM', 'SCY', 'SCM16', 'SCM20', 'SCM33', 'SCY20', 'SCY27', 'SCY33', 'SCY36', 'OPEN'];
        $upper = strtoupper(trim($course));

        return in_array($upper, $valid) ? $upper : null;
    }

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

    private function mapTiming(string $timing): ?string
    {
        $valid = ['AUTOMATIC', 'SEMIAUTOMATIC', 'MANUAL3', 'MANUAL2', 'MANUAL1'];
        $upper = strtoupper(trim($timing));

        return in_array($upper, $valid) ? $upper : null;
    }

    private function mapTechnique(string $technique): ?string
    {
        $valid = ['DIVE', 'GLIDE', 'KICK', 'PULL', 'START', 'TURN'];
        $upper = strtoupper(trim($technique));

        return in_array($upper, $valid) ? $upper : null;
    }

    private function mapEntryStatus(string $status): ?string
    {
        $valid = ['EXH', 'RJC', 'SICK', 'WDR'];
        $upper = strtoupper(trim($status));

        return in_array($upper, $valid) ? $upper : null;
    }

    private function mapResultStatus(string $status): ?string
    {
        $valid = ['EXH', 'DSQ', 'DNS', 'DNF', 'SICK', 'WDR'];
        $upper = strtoupper(trim($status));

        return in_array($upper, $valid) ? $upper : null;
    }
}
