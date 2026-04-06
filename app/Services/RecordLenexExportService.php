<?php

namespace App\Services;

use App\Models\Club;
use App\Models\SwimRecord;
use App\Support\TimeParser;
use DOMDocument;
use DOMElement;
use DOMException;
use Illuminate\Database\Eloquent\Collection;

/**
 * RecordLenexExportService
 *
 * Exportiert SwimRecords als LENEX 3.0 konforme XML-Datei.
 *
 * LENEX-Struktur für Rekorde:
 *   LENEX > RECORDLISTS > RECORDLIST[type, course, gender, handicap, nation, updated]
 *                       > RECORDS > RECORD[swimtime]
 *                                 > SWIMSTYLE[distance, relaycount, stroke]
 *                                 > MEETINFO[name, city, date, nation]
 *                                 > ATHLETE (Einzel) | RELAY (Staffel)
 *                                 > SPLITS > SPLIT
 *
 * Filter-Optionen:
 *   record_types  → z.B. ['AUT', 'AUT.JR'] oder ['WR', 'ER'] oder ['AUT.WBSV']
 *   courses       → z.B. ['LCM'], ['SCM'], ['LCM', 'SCM'] (leer = alle)
 *   gender        → 'M', 'F', '' (leer = beide)
 *
 * Übersprungen werden:
 *   - Rekorde ohne swim_time (NT)
 *   - Rekorde mit swim_time = 0 ("00:00.00")
 *   - record_status INVALID oder TARGETTIME
 *
 * Gruppierung:
 *   Eine RECORDLIST pro (record_type, course, gender, sport_class).
 *   LENEX erwartet pro RECORDLIST genau ein handicap-Attribut.
 *   Das updated-Attribut trägt das set_date des zuletzt aufgestellten Records der Gruppe.
 */
class RecordLenexExportService
{
    private DOMDocument $dom;

    // ── Öffentliche API ───────────────────────────────────────────────────────

    /**
     * Baut dem LENEX-XML und gibt den fertigen String zurück.
     *
     * @param  string[]  $recordTypes  z.B. ['AUT', 'AUT.JR'] — leer = alle
     * @param  string[]  $courses  z.B. ['LCM', 'SCM']    — leer = alle
     * @param  string  $gender  'M', 'F' oder ''        — leer = beide
     *
     * @throws DOMException
     */
    public function build(
        array $recordTypes = [],
        array $courses = [],
        string $gender = ''
    ): string {
        $this->dom = new DOMDocument('1.0', 'UTF-8');
        $this->dom->formatOutput = true;

        $root = $this->buildRoot();
        $this->buildConstructor($root);

        $records = $this->fetchRecords($recordTypes, $courses, $gender);

        $recordListsEl = $this->dom->createElement('RECORDLISTS');
        $root->appendChild($recordListsEl);

        $grouped = $this->groupRecords($records);

        foreach ($grouped as $group) {
            $recordListEl = $this->buildRecordList($group);
            if ($recordListEl->hasChildNodes()) {
                $recordListsEl->appendChild($recordListEl);
            }
        }

        return $this->dom->saveXML();
    }

    // ── Daten laden ───────────────────────────────────────────────────────────

    /**
     * @throws DOMException
     */
    private function buildRoot(): DOMElement
    {
        $root = $this->dom->createElement('LENEX');
        $root->setAttribute('version', '3.0');
        $this->dom->appendChild($root);

        return $root;
    }

    // ── Gruppierung ───────────────────────────────────────────────────────────

    /**
     * @throws DOMException
     */
    private function buildConstructor(DOMElement $parent): void
    {
        $constructor = $this->dom->createElement('CONSTRUCTOR');
        $constructor->setAttribute('name', 'Para Swimming NatDB');
        $constructor->setAttribute('version', '1.0');

        $contact = $this->dom->createElement('CONTACT');
        $contact->setAttribute('name', 'a-timing.wien');
        $contact->setAttribute('email', 'a.steiner@a-timing.wien');
        $constructor->appendChild($contact);

        $parent->appendChild($constructor);
    }

    /**
     * Lädt alle aktuellen, gültigen Rekorde mit den nötigen Relations.
     * NT und Nullzeiten werden bereits auf DB-Ebene ausgeschlossen.
     *
     * @return Collection<SwimRecord>
     */
    private function fetchRecords(array $recordTypes, array $courses, string $gender): Collection
    {
        $query = SwimRecord::with([
            'strokeType',
            'athlete.nation',
            'athlete.club',
            'club',
            'nation',
            'relayTeam',
            'splits',
        ])
            ->where('is_current', true)
            ->whereNotIn('record_status', ['INVALID', 'TARGETTIME'])
            ->whereNotNull('swim_time')   // kein NT
            ->where('swim_time', '>', 0); // kein 00:00.00

        if (! empty($recordTypes)) {
            $query->whereIn('record_type', $recordTypes);
        }

        if (! empty($courses)) {
            $query->whereIn('course', $courses);
        }

        if ($gender !== '') {
            $query->where('gender', $gender);
        }

        return $query
            ->orderBy('record_type')
            ->orderBy('course')
            ->orderBy('gender')
            ->orderBy('sport_class')
            ->orderBy('distance')
            ->orderBy('relay_count')
            ->get();
    }

    // ── LENEX Aufbau ──────────────────────────────────────────────────────────

    /**
     * Gruppiert Records nach (record_type, course, gender, sport_class).
     * Berechnet dabei das updated-Datum als Max(set_date) der Gruppe —
     * d.h. das Datum des zuletzt aufgestellten Rekords in dieser Liste.
     *
     * @return array<int, array{
     *     record_type: string,
     *     course: string,
     *     gender: string,
     *     sport_class: string,
     *     handicap_number: string,
     *     stroke_prefix: string,
     *     updated: string,
     *     records: Collection,
     * }>
     */
    private function groupRecords(Collection $records): array
    {
        $groups = [];

        foreach ($records as $record) {
            $key = implode('|', [
                $record->record_type,
                $record->course,
                $record->gender,
                $record->sport_class,
            ]);

            if (! isset($groups[$key])) {
                [$prefix, $number] = $this->parseSportClass($record->sport_class);
                $groups[$key] = [
                    'record_type' => $record->record_type,
                    'course' => $record->course,
                    'gender' => $record->gender,
                    'sport_class' => $record->sport_class,
                    'handicap_number' => $number,
                    'stroke_prefix' => $prefix,
                    'updated' => $record->set_date,
                    'records' => collect(),
                ];
            } else {
                // Jüngstes set_date der Gruppe merken
                if ($record->set_date && $record->set_date > $groups[$key]['updated']) {
                    $groups[$key]['updated'] = $record->set_date;
                }
            }

            $groups[$key]['records']->push($record);
        }

        return array_values($groups);
    }

    /**
     * Zerlegt "SB9" → ['SB', '9'], "S14" → ['S', '14'], "SM14" → ['SM', '14'].
     * Staffel-Sammelklassen z.B. "49" → ['', '49'].
     *
     * @return array{0: string, 1: string}
     */
    private function parseSportClass(string $sportClass): array
    {
        if (preg_match('/^(SB|SM|S)(\d+)$/', $sportClass, $m)) {
            return [$m[1], $m[2]];
        }

        return ['', $sportClass];
    }

    /**
     * Baut ein RECORDLIST-Element für eine Gruppe.
     * Attribute: type, course, gender, handicap, nation, updated
     *
     * nation  → aus record_type abgeleitet: AUT.* → AUT, WR/ER/OR → leer
     * updated → Max(set_date) der Records in dieser Gruppe (YYYY-MM-DD)
     *
     * @throws DOMException
     */
    private function buildRecordList(array $group): DOMElement
    {
        $el = $this->dom->createElement('RECORDLIST');
        $el->setAttribute('type', $group['record_type']);
        $el->setAttribute('course', $group['course']);
        $el->setAttribute('gender', $group['gender']);
        $el->setAttribute('handicap', $group['handicap_number']);

        // nation: bei AUT-Typen immer "AUT", bei internationalen Typen leer lassen
        $nation = $this->nationFromRecordType($group['record_type']);
        if ($nation !== '') {
            $el->setAttribute('nation', $nation);
        }

        // updated: Datum der letzten Änderung in dieser Gruppe
        if ($group['updated']) {
            $el->setAttribute('updated', $group['updated']->format('Y-m-d'));
        }

        $recordsEl = $this->dom->createElement('RECORDS');

        foreach ($group['records'] as $record) {
            $recordsEl->appendChild($this->buildRecord($record));
        }

        $el->appendChild($recordsEl);

        return $el;
    }

    /**
     * Leitet den Nationscode aus dem record_type ab.
     * AUT, AUT.JR, AUT.WBSV, AUT.WBSV.JR → "AUT"
     * WR, ER, OR                            → ""
     */
    private function nationFromRecordType(string $recordType): string
    {
        if (str_starts_with($recordType, 'AUT')) {
            return 'AUT';
        }

        return '';
    }

    /**
     * Baut ein einzelnes RECORD-Element.
     * Records ohne gültige swim_time werden übersprungen (NT / 0).
     *
     * @throws DOMException
     */
    private function buildRecord(SwimRecord $record): DOMElement
    {
        $el = $this->dom->createElement('RECORD');
        $el->setAttribute('swimtime', TimeParser::format($record->swim_time));

        // SWIMSTYLE
        $el->appendChild($this->buildSwimStyle($record));

        // MEETINFO
        if ($record->meet_name || $record->set_date) {
            $el->appendChild($this->buildMeetInfo($record));
        }

        // Einzel vs. Staffel
        if ($record->relay_count > 1) {
            $el->appendChild($this->buildRelay($record));
        } elseif ($record->athlete) {
            $el->appendChild($this->buildAthlete($record));
        }

        // SPLITS
        if ($record->splits->isNotEmpty()) {
            $el->appendChild($this->buildSplits($record));
        }

        return $el;
    }

    /**
     * @throws DOMException
     */
    private function buildSwimStyle(SwimRecord $record): DOMElement
    {
        $el = $this->dom->createElement('SWIMSTYLE');
        $el->setAttribute('distance', (string) $record->distance);
        $el->setAttribute('relaycount', (string) $record->relay_count);
        $el->setAttribute('stroke', $record->strokeType?->lenex_code ?? 'UNKNOWN');

        return $el;
    }

    /**
     * @throws DOMException
     */
    private function buildMeetInfo(SwimRecord $record): DOMElement
    {
        $el = $this->dom->createElement('MEETINFO');

        if ($record->meet_name) {
            $el->setAttribute('name', $record->meet_name);
        }
        if ($record->meet_city) {
            $el->setAttribute('city', $record->meet_city);
        }
        if ($record->set_date) {
            $el->setAttribute('date', $record->set_date->format('Y-m-d'));
        }
        if ($record->nation) {
            $el->setAttribute('nation', $record->nation->code);
        } elseif ($record->athlete?->nation) {
            $el->setAttribute('nation', $record->athlete->nation->code);
        }

        return $el;
    }

    /**
     * Staffel: RELAY > CLUB + RELAYPOSITIONS > RELAYPOSITION > ATHLETE
     *
     * @throws DOMException
     */
    private function buildRelay(SwimRecord $record): DOMElement
    {
        $el = $this->dom->createElement('RELAY');

        $club = $record->club ?? $record->athlete?->club;
        if ($club) {
            $el->appendChild($this->buildClub($club));
        }

        if ($record->relayTeam->isNotEmpty()) {
            $positionsEl = $this->dom->createElement('RELAYPOSITIONS');

            foreach ($record->relayTeam as $member) {
                $posEl = $this->dom->createElement('RELAYPOSITION');
                $posEl->setAttribute('number', (string) $member->position);

                $athEl = $this->dom->createElement('ATHLETE');
                $athEl->setAttribute('lastname', $member->last_name);
                $athEl->setAttribute('firstname', $member->first_name);

                if ($member->birth_date) {
                    $athEl->setAttribute('birthdate', $member->birth_date->format('Y-m-d'));
                }
                if ($member->gender) {
                    $athEl->setAttribute('gender', $member->gender);
                }

                $posEl->appendChild($athEl);
                $positionsEl->appendChild($posEl);
            }

            $el->appendChild($positionsEl);
        }

        return $el;
    }

    /**
     * @throws DOMException
     */
    private function buildClub(Club $club): DOMElement
    {
        $el = $this->dom->createElement('CLUB');
        $el->setAttribute('name', $club->name);

        if ($club->code) {
            $el->setAttribute('code', $club->code);
        }
        if ($club->nation) {
            $el->setAttribute('nation', $club->nation->code);
        }

        return $el;
    }

    /**
     * Einzel-Athlet: ATHLETE > CLUB
     *
     * @throws DOMException
     */
    private function buildAthlete(SwimRecord $record): DOMElement
    {
        $athlete = $record->athlete;

        $el = $this->dom->createElement('ATHLETE');
        $el->setAttribute('lastname', $athlete->last_name);
        $el->setAttribute('firstname', $athlete->first_name);
        $el->setAttribute('birthdate', $athlete->birth_date?->format('Y-m-d') ?? '');
        $el->setAttribute('gender', $athlete->gender);

        if ($athlete->license) {
            $el->setAttribute('license', $athlete->license);
        }

        // Club zum Zeitpunkt des Rekords (record->club) oder aktueller Club des Athleten
        $club = $record->club ?? $athlete->club;
        if ($club) {
            $el->appendChild($this->buildClub($club));
        }

        return $el;
    }

    /**
     * @throws DOMException
     */
    private function buildSplits(SwimRecord $record): DOMElement
    {
        $el = $this->dom->createElement('SPLITS');

        foreach ($record->splits as $split) {
            $splitEl = $this->dom->createElement('SPLIT');
            $splitEl->setAttribute('distance', (string) $split->distance);
            $splitEl->setAttribute('swimtime', TimeParser::format($split->split_time));
            $el->appendChild($splitEl);
        }

        return $el;
    }
}
