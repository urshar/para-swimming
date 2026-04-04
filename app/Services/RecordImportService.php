<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Nation;
use App\Models\RecordSplit;
use App\Models\StrokeType;
use App\Models\SwimRecord;
use App\Support\TimeParser;
use Exception;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SimpleXMLElement;
use Throwable;
use ZipArchive;

/**
 * RecordImportService
 *
 * Importiert LENEX 3.0 Rekord-Dateien (.lxf oder .xml).
 *
 * Ablauf:
 *   1. parse()      — Datei lesen, Rekorde + unbekannte Entities extrahieren
 *   2. preview()    — Vorschau für Bestätigungsseite (unbekannte Clubs/Athleten)
 *   3. import()     — nach Bestätigung: Clubs/Athleten anlegen + Rekorde speichern
 *
 * Besonderheiten:
 *   - swimtime="NT" wird übersprungen
 *   - AUT.JG → AUT.JR automatisch gemappt
 *   - Athleten-Matching: license → name + birthdate + gender
 *   - Club-Matching: code + nation → name + nation
 *   - Rekorde ohne Athlet (Staffeln) werden direkt importiert
 *
 * Stroke-Mapping (LENEX → internal code):
 *   FREE → FREE, BACK → BACK, BREAST → BREAST, FLY → FLY, MEDLEY → MEDLEY
 */
class RecordImportService
{
    /** LENEX record_type → interner record_type */
    private const array TYPE_MAP = [
        'AUT.JG' => 'AUT.JR',
    ];

    /** LENEX SWIMSTYLE.stroke → StrokeType.lenex_code */
    private const array STROKE_MAP = [
        'FREE' => 'FREE',
        'BACK' => 'BACK',
        'BREAST' => 'BREAST',
        'FLY' => 'FLY',
        'MEDLEY' => 'MEDLEY',
    ];

    /** StrokeType Cache: lenex_code → id */
    private array $strokeTypeCache = [];

    /** Nation Cache: code → id */
    private array $nationCache = [];

    // ── Öffentliche API ───────────────────────────────────────────────────────

    /**
     * Führt den Import durch.
     *
     * $approvedClubs:    ['club_key' => club_id|'new'|'skip']
     * $approvedAthletes: ['athlete_key' => athlete_id|'new'|'skip']
     * $newClubData:      ['club_key' => ['name'=>...,'code'=>...,'nation'=>...]]
     * $newAthleteData:   ['athlete_key' => ['first_name'=>...,'last_name'=>...,...]]
     *
     * @throws RuntimeException wenn die Datei nicht gelesen werden kann
     * @throws Exception wenn der XML-Inhalt ungültig ist
     * @throws Throwable
     */
    public function import(
        string $filePath,
        array $approvedClubs,
        array $approvedAthletes,
        array $newClubData,
        array $newAthleteData,
    ): array {
        $preview = $this->preview($filePath);

        // Clubs anlegen die als 'new' markiert wurden
        $clubIdMap = [];
        foreach ($preview['unknown_clubs'] as $club) {
            $key = $club['key'];
            $decision = $approvedClubs[$key] ?? 'skip';

            if ($decision === 'skip') {
                $clubIdMap[$key] = null;

                continue;
            }

            if ($decision === 'new') {
                $data = $newClubData[$key] ?? $club;
                $nationId = $this->getNationId($data['nation'] ?? 'AUT');
                $newClub = Club::create([
                    'name' => $data['name'],
                    'code' => $data['code'] ?? null,
                    'nation_id' => $nationId,
                    'type' => 'CLUB',
                ]);
                $clubIdMap[$key] = $newClub->id;
            } else {
                // Existierende Club-ID übergeben
                $clubIdMap[$key] = (int) $decision;
            }
        }

        // Athleten anlegen die als 'new' markiert wurden
        $athleteIdMap = [];
        foreach ($preview['unknown_athletes'] as $athlete) {
            $key = $athlete['key'];
            $decision = $approvedAthletes[$key] ?? 'skip';

            if ($decision === 'skip') {
                $athleteIdMap[$key] = null;

                continue;
            }

            if ($decision === 'new') {
                $data = $newAthleteData[$key] ?? $athlete;
                $clubKey = $athlete['club_key'];
                $clubId = $clubKey ? ($clubIdMap[$clubKey] ?? null) : null;
                $nationId = $this->getNationId('AUT');

                $newAthlete = Athlete::create([
                    'first_name' => $data['first_name'] ?? $athlete['first_name'],
                    'last_name' => $data['last_name'] ?? $athlete['last_name'],
                    'birth_date' => $athlete['birth_date'] ?: null,
                    'gender' => $athlete['gender'] ?: 'M',
                    'nation_id' => $nationId,
                    'club_id' => $clubId,
                    'license' => $athlete['license'] ?: null,
                ]);
                $athleteIdMap[$key] = $newAthlete->id;
            } else {
                $athleteIdMap[$key] = (int) $decision;
            }
        }

        // Rekorde importieren
        $imported = 0;
        $skipped = 0;

        DB::transaction(function () use ($preview, $athleteIdMap, &$imported, &$skipped) {
            foreach ($preview['records'] as $rec) {
                $athleteId = null;
                $nationId = $this->getNationId('AUT');

                // Athlet auflösen
                if ($rec['athlete']) {
                    $athKey = $rec['athlete']['key'];
                    if ($rec['athlete']['db_id']) {
                        $athleteId = $rec['athlete']['db_id'];
                    } elseif (isset($athleteIdMap[$athKey])) {
                        $athleteId = $athleteIdMap[$athKey];
                    }

                    // Wenn Athlet abgelehnt → Rekord überspringen
                    if ($rec['athlete']['db_id'] === null && ($athleteIdMap[$athKey] ?? null) === null) {
                        $skipped++;

                        continue;
                    }
                }

                // Aktuellen Rekord suchen
                $current = SwimRecord::where('record_type', $rec['record_type'])
                    ->where('stroke_type_id', $rec['stroke_type_id'])
                    ->where('sport_class', $rec['sport_class'])
                    ->where('gender', $rec['gender'])
                    ->where('course', $rec['course'])
                    ->where('distance', $rec['distance'])
                    ->where('relay_count', $rec['relay_count'])
                    ->where('is_current', true)
                    ->first();

                // Nur importieren wenn besser als aktueller Rekord
                if ($current && $current->swim_time <= $rec['swim_time']) {
                    $skipped++;

                    continue;
                }

                $newRecord = SwimRecord::create([
                    'stroke_type_id' => $rec['stroke_type_id'],
                    'nation_id' => $nationId,
                    'athlete_id' => $athleteId,
                    'supersedes_id' => $current?->id,
                    'record_type' => $rec['record_type'],
                    'sport_class' => $rec['sport_class'],
                    'gender' => $rec['gender'],
                    'course' => $rec['course'],
                    'distance' => $rec['distance'],
                    'relay_count' => $rec['relay_count'],
                    'swim_time' => $rec['swim_time'],
                    'record_status' => 'APPROVED',
                    'is_current' => true,
                    'set_date' => TimeParser::sanitizeDate($rec['set_date']),
                    'meet_name' => $rec['meet_name'],
                    'meet_city' => $rec['meet_city'],
                    'meet_course' => $rec['meet_course'],
                ]);

                // Alten Rekord auf historisch setzen
                $current?->markAsSupersededBy($newRecord);

                // Splits speichern
                foreach ($rec['splits'] as $split) {
                    RecordSplit::create([
                        'swim_record_id' => $newRecord->id,
                        'distance' => $split['distance'],
                        'split_time' => $split['split_time'],
                    ]);
                }

                $imported++;
            }
        });

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * Liest eine LXF/XML-Datei und gibt eine Vorschau zurück.
     *
     * @return array{
     *     records: array,
     *     unknown_clubs: array,
     *     unknown_athletes: array,
     *     skipped: int,
     * }
     *
     * @throws RuntimeException wenn die Datei nicht gelesen werden kann
     * @throws Exception wenn der XML-Inhalt ungültig ist
     */
    public function preview(string $filePath): array
    {
        $xml = $this->loadXml($filePath);

        $records = [];
        $unknownClubs = [];
        $unknownAthletes = [];
        $skipped = 0;

        $seenClubKeys = [];
        $seenAthleteKeys = [];

        foreach ($xml->RECORDLISTS->RECORDLIST as $recordList) {
            $recordType = $this->mapRecordType((string) $recordList['type']);
            $course = (string) $recordList['course'];
            $gender = (string) $recordList['gender'];

            foreach ($recordList->RECORDS->RECORD as $rec) {
                $swimtime = (string) $rec['swimtime'];
                if ($swimtime === 'NT' || $swimtime === '') {
                    $skipped++;

                    continue;
                }

                $style = $rec->SWIMSTYLE;
                $strokeCode = (string) ($style['stroke'] ?? '');
                $distance = (int) ($style['distance'] ?? 0);
                $relayCount = (int) ($style['relaycount'] ?? 1);
                $sportClass = 'S'.$recordList['handicap'];

                $strokeTypeId = $this->resolveStrokeType($strokeCode);
                if (! $strokeTypeId) {
                    $skipped++;

                    continue;
                }

                // Athlet + Club aus ATHLETE-Kindelement
                $athleteData = null;
                $clubData = null;
                $athleteXml = $rec->ATHLETE ?? null;

                if ($athleteXml !== null) {
                    $clubXml = $athleteXml->CLUB ?? null;

                    if ($clubXml !== null) {
                        $clubCode = (string) ($clubXml['code'] ?? '');
                        $clubName = (string) ($clubXml['name'] ?? '');
                        $clubNation = (string) ($clubXml['nation'] ?? 'AUT');
                        $clubKey = $clubCode ?: $clubName;

                        $club = $this->findClub($clubCode, $clubName, $clubNation);

                        if (! $club && $clubKey && ! isset($seenClubKeys[$clubKey])) {
                            $seenClubKeys[$clubKey] = true;
                            $unknownClubs[$clubKey] = [
                                'key' => $clubKey,
                                'code' => $clubCode,
                                'name' => $clubName,
                                'nation' => $clubNation,
                            ];
                        }

                        $clubData = [
                            'key' => $clubKey,
                            'code' => $clubCode,
                            'name' => $clubName,
                            'nation' => $clubNation,
                            'db_id' => $club?->id,
                        ];
                    }

                    $lastName = (string) ($athleteXml['lastname'] ?? '');
                    $firstName = (string) ($athleteXml['firstname'] ?? '');
                    $birthDate = (string) ($athleteXml['birthdate'] ?? '');
                    $athGender = (string) ($athleteXml['gender'] ?? $gender);
                    $license = (string) ($athleteXml['license'] ?? '');
                    $athKey = $lastName.'|'.$firstName.'|'.$birthDate;

                    if ($lastName || $firstName) {
                        $athlete = $this->findAthlete($license, $lastName, $firstName, $birthDate, $athGender);

                        if (! $athlete && ! isset($seenAthleteKeys[$athKey])) {
                            $seenAthleteKeys[$athKey] = true;
                            $unknownAthletes[$athKey] = [
                                'key' => $athKey,
                                'last_name' => $lastName,
                                'first_name' => $firstName,
                                'birth_date' => $birthDate,
                                'gender' => $athGender,
                                'license' => $license,
                                'club_key' => $clubData['key'] ?? null,
                                'club_name' => $clubName ?? null,
                                'db_id' => null,
                            ];
                        }

                        $athleteData = [
                            'key' => $athKey,
                            'last_name' => $lastName,
                            'first_name' => $firstName,
                            'birth_date' => $birthDate,
                            'gender' => $athGender,
                            'license' => $license,
                            'db_id' => $athlete?->id,
                        ];
                    }
                }

                // MEETINFO
                $meetInfo = $rec->MEETINFO ?? null;

                // Splits
                $splits = [];
                foreach ($rec->SPLITS->SPLIT ?? [] as $split) {
                    $splits[] = [
                        'distance' => (int) $split['distance'],
                        'split_time' => self::parseLenexTime((string) $split['swimtime']),
                    ];
                }

                $records[] = [
                    'record_type' => $recordType,
                    'course' => $course,
                    'gender' => $gender,
                    'sport_class' => $sportClass,
                    'stroke_type_id' => $strokeTypeId,
                    'distance' => $distance,
                    'relay_count' => $relayCount,
                    'swim_time' => self::parseLenexTime($swimtime),
                    'meet_name' => $meetInfo ? ((string) ($meetInfo['name'] ?? '') ?: null) : null,
                    'meet_city' => $meetInfo ? ((string) ($meetInfo['city'] ?? '') ?: null) : null,
                    'set_date' => $meetInfo ? ((string) ($meetInfo['date'] ?? '') ?: null) : null,
                    'meet_course' => $course,
                    'athlete' => $athleteData,
                    'club' => $clubData,
                    'splits' => $splits,
                ];
            }
        }

        return [
            'records' => $records,
            'unknown_clubs' => array_values($unknownClubs),
            'unknown_athletes' => array_values($unknownAthletes),
            'skipped' => $skipped,
        ];
    }

    // ── Private Hilfsmethoden ─────────────────────────────────────────────────

    /**
     * @throws RuntimeException wenn die Datei nicht gelesen werden kann
     * @throws Exception wenn der XML-Inhalt ungültig ist (von SimpleXMLElement)
     */
    private function loadXml(string $filePath): SimpleXMLElement
    {
        // LXF ist ein ZIP mit einer XML-Datei darin
        $zip = new ZipArchive;
        if ($zip->open($filePath) === true) {
            $xmlContent = $zip->getFromIndex(0);
            $zip->close();

            if ($xmlContent === false) {
                throw new RuntimeException('Konnte XML-Inhalt aus LXF-Datei nicht lesen.');
            }

            return new SimpleXMLElement($xmlContent);
        }

        // Fallback: direkte XML-Datei
        $xmlContent = file_get_contents($filePath);
        if ($xmlContent === false) {
            throw new RuntimeException('Konnte Datei nicht lesen: '.$filePath);
        }

        return new SimpleXMLElement($xmlContent);
    }

    private function mapRecordType(string $type): string
    {
        return self::TYPE_MAP[$type] ?? $type;
    }

    private function resolveStrokeType(string $lenexCode): ?int
    {
        if (isset($this->strokeTypeCache[$lenexCode])) {
            return $this->strokeTypeCache[$lenexCode];
        }

        $internalCode = self::STROKE_MAP[$lenexCode] ?? null;
        if (! $internalCode) {
            return null;
        }

        $strokeType = StrokeType::where('lenex_code', $internalCode)->first();
        if (! $strokeType) {
            return null;
        }

        $this->strokeTypeCache[$lenexCode] = $strokeType->id;

        return $strokeType->id;
    }

    private function findClub(string $code, string $name, string $nationCode): ?Club
    {
        $nationId = $this->getNationId($nationCode);

        if ($code) {
            $club = Club::where('code', $code)->where('nation_id', $nationId)->first();
            if ($club) {
                return $club;
            }
        }

        if ($name) {
            return Club::where(DB::raw('LOWER(TRIM(name))'), mb_strtolower(trim($name)))
                ->where('nation_id', $nationId)
                ->first();
        }

        return null;
    }

    private function getNationId(string $code): ?int
    {
        if (isset($this->nationCache[$code])) {
            return $this->nationCache[$code];
        }

        $nation = Nation::where('code', $code)->first();
        $this->nationCache[$code] = $nation?->id;

        return $this->nationCache[$code];
    }

    private function findAthlete(
        string $license,
        string $lastName,
        string $firstName,
        string $birthDate,
        string $gender
    ): ?Athlete {
        if ($license) {
            $athlete = Athlete::where('license', $license)->whereNull('deleted_at')->first();
            if ($athlete) {
                return $athlete;
            }
        }

        if ($lastName && $firstName && $birthDate) {
            return Athlete::where(DB::raw('LOWER(last_name)'), mb_strtolower($lastName))
                ->where(DB::raw('LOWER(first_name)'), mb_strtolower($firstName))
                ->where('birth_date', $birthDate)
                ->where('gender', $gender)
                ->whereNull('deleted_at')
                ->first();
        }

        return null;
    }

    /**
     * Konvertiert LENEX Zeitformat (HH:MM:SS.cs oder MM:SS.cs) in Hundertstelsekunden.
     * Beispiel: "00:01:51.44" → 11144, "00:34.45" → 3445
     */
    private static function parseLenexTime(string $time): int
    {
        if ($time === 'NT' || $time === '') {
            return 0;
        }

        $parts = explode(':', $time);

        if (count($parts) === 3) {
            [$h, $m, $s] = $parts;
            [$sec, $cs] = array_pad(explode('.', $s), 2, '0');

            return ((int) $h * 3600 + (int) $m * 60 + (int) $sec) * 100 + (int) $cs;
        }

        [$m, $s] = $parts;
        [$sec, $cs] = array_pad(explode('.', $s), 2, '0');

        return ((int) $m * 60 + (int) $sec) * 100 + (int) $cs;
    }
}
