<?php

namespace App\Services;

use App\Models\Meet;
use App\Models\SwimEvent;
use DOMDocument;
use DOMElement;
use DOMException;

/**
 * LenexExportService
 *
 * Baut eine LENEX 3.0 konforme XML-Datei für drei Export-Typen:
 *   structure → Meet + Sessions + Events
 *   entries   → Structure + Clubs + Athletes + Entries
 *   results   → Structure + Clubs + Athletes + Results + Splits
 */
class LenexExportService
{
    private DOMDocument $dom;

    private string $exportType;

    /**
     * @throws DOMException
     */
    public function build(Meet $meet, string $exportType): string
    {
        $this->exportType = $exportType;
        $this->dom = new DOMDocument('1.0', 'UTF-8');
        $this->dom->formatOutput = true;

        $root = $this->buildRoot();
        $this->buildConstructor($root);
        $meetsEl = $this->dom->createElement('MEETS');
        $root->appendChild($meetsEl);
        $meetsEl->appendChild($this->buildMeet($meet));

        return $this->dom->saveXML();
    }

    // ── Root + Constructor ────────────────────────────────────────────────────

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

    /**
     * @throws DOMException
     */
    private function buildConstructor(DOMElement $parent): void
    {
        $constructor = $this->dom->createElement('CONSTRUCTOR');
        $constructor->setAttribute('name', 'Para Swimming NatDB');
        $constructor->setAttribute('version', '1.0');

        $contact = $this->dom->createElement('CONTACT');
        $contact->setAttribute('email', 'admin@paraswimming.at');
        $constructor->appendChild($contact);

        $parent->appendChild($constructor);
    }

    // ── Meet ──────────────────────────────────────────────────────────────────

    /**
     * @throws DOMException
     */
    private function buildMeet(Meet $meet): DOMElement
    {
        $meet->load(['nation', 'clubs.athletes.sportClasses', 'swimEvents.strokeType']);

        $el = $this->dom->createElement('MEET');
        $el->setAttribute('name', $meet->name);
        $el->setAttribute('city', $meet->city ?? '');
        $el->setAttribute('nation', $meet->nation?->code ?? '');
        $el->setAttribute('course', $meet->course);
        $el->setAttribute('startdate', $meet->start_date->format('Y-m-d'));

        if ($meet->end_date) {
            $el->setAttribute('stopdate', $meet->end_date->format('Y-m-d'));
        }
        if ($meet->organizer) {
            $el->setAttribute('organizer', $meet->organizer);
        }
        if ($meet->altitude > 0) {
            $el->setAttribute('altitude', (string) $meet->altitude);
        }
        if ($meet->timing) {
            $el->setAttribute('timing', $meet->timing);
        }
        if ($meet->lenex_meet_id) {
            $el->setAttribute('meetid', $meet->lenex_meet_id);
        }

        // Sessions + Events
        $el->appendChild($this->buildSessions($meet));

        // Clubs + Athletes + Entries/Results
        if (in_array($this->exportType, ['entries', 'results'])) {
            $clubsEl = $this->buildClubs($meet);
            if ($clubsEl->hasChildNodes()) {
                $el->appendChild($clubsEl);
            }
        }

        return $el;
    }

    // ── Sessions ──────────────────────────────────────────────────────────────

    /**
     * @throws DOMException
     */
    private function buildSessions(Meet $meet): DOMElement
    {
        $sessionsEl = $this->dom->createElement('SESSIONS');

        $events = $meet->swimEvents->groupBy('session_number');

        foreach ($events as $sessionNumber => $sessionEvents) {
            $sessionEl = $this->dom->createElement('SESSION');
            $sessionEl->setAttribute('number', (string) $sessionNumber);

            // Datum aus dem Meet nehmen (vereinfacht)
            $sessionEl->setAttribute('date', $meet->start_date->format('Y-m-d'));

            $eventsEl = $this->dom->createElement('EVENTS');

            foreach ($sessionEvents->sortBy('event_number') as $event) {
                $eventsEl->appendChild($this->buildEvent($event));
            }

            $sessionEl->appendChild($eventsEl);
            $sessionsEl->appendChild($sessionEl);
        }

        return $sessionsEl;
    }

    // ── Event ─────────────────────────────────────────────────────────────────

    /**
     * @throws DOMException
     */
    private function buildEvent(SwimEvent $event): DOMElement
    {
        $el = $this->dom->createElement('EVENT');

        if ($event->event_number) {
            $el->setAttribute('number', (string) $event->event_number);
        }
        $el->setAttribute('gender', $event->gender === 'A' ? 'A' : $event->gender);
        $el->setAttribute('round', $event->round);

        if ($event->lenex_event_id) {
            $el->setAttribute('eventid', $event->lenex_event_id);
        } else {
            $el->setAttribute('eventid', (string) $event->id);
        }

        if ($event->sport_classes) {
            $el->setAttribute('sportclasses', $event->sport_classes);
        }

        // SWIMSTYLE
        $styleEl = $this->dom->createElement('SWIMSTYLE');
        $styleEl->setAttribute('distance', (string) $event->distance);
        $styleEl->setAttribute('relaycount', (string) $event->relay_count);
        $styleEl->setAttribute('stroke', $event->strokeType?->lenex_code ?? 'UNKNOWN');

        if ($event->technique) {
            $styleEl->setAttribute('technique', $event->technique);
        }
        if ($event->style_code) {
            $styleEl->setAttribute('code', $event->style_code);
        }
        if ($event->style_name) {
            $styleEl->setAttribute('name', $event->style_name);
        }

        $el->appendChild($styleEl);

        return $el;
    }

    // ── Clubs ─────────────────────────────────────────────────────────────────

    /**
     * @throws DOMException
     */
    private function buildClubs(Meet $meet): DOMElement
    {
        $clubsEl = $this->dom->createElement('CLUBS');

        foreach ($meet->clubs as $club) {
            $clubEl = $this->dom->createElement('CLUB');
            $clubEl->setAttribute('name', $club->name);

            if ($club->code) {
                $clubEl->setAttribute('code', $club->code);
            }
            if ($club->nation) {
                $clubEl->setAttribute('nation', $club->nation->code);
            }
            if ($club->type !== 'CLUB') {
                $clubEl->setAttribute('type', $club->type);
            }
            if ($club->lenex_club_id) {
                $clubEl->setAttribute('id', $club->lenex_club_id);
            }

            // Athletes
            $athletes = $this->exportType === 'entries'
                ? $meet->entries()->where('club_id', $club->id)
                    ->with('athlete.sportClasses')->get()
                    ->pluck('athlete')->unique('id')
                : $meet->results()->where('club_id', $club->id)
                    ->with('athlete.sportClasses')->get()
                    ->pluck('athlete')->unique('id');

            if ($athletes->isNotEmpty()) {
                $athletesEl = $this->dom->createElement('ATHLETES');
                foreach ($athletes as $athlete) {
                    if (! $athlete) {
                        continue;
                    }
                    $athletesEl->appendChild(
                        $this->buildAthlete($athlete, $club, $meet)
                    );
                }
                $clubEl->appendChild($athletesEl);
            }

            $clubsEl->appendChild($clubEl);
        }

        return $clubsEl;
    }

    // ── Athlete ───────────────────────────────────────────────────────────────

    /**
     * @throws DOMException
     */
    private function buildAthlete($athlete, $club, Meet $meet): DOMElement
    {
        $el = $this->dom->createElement('ATHLETE');

        $el->setAttribute('athleteid', $athlete->lenex_athlete_id ?? (string) $athlete->id);
        $el->setAttribute('lastname', $athlete->last_name);
        $el->setAttribute('firstname', $athlete->first_name);
        $el->setAttribute('gender', $athlete->gender);

        if ($athlete->birth_date) {
            $el->setAttribute('birthdate', $athlete->birth_date->format('Y-m-d'));
        }
        if ($athlete->license) {
            $el->setAttribute('license', $athlete->license);
        }
        if ($athlete->license_ipc) {
            $el->setAttribute('license_ipc', $athlete->license_ipc);
        }
        if ($athlete->nation) {
            $el->setAttribute('nation', $athlete->nation->code);
        }
        if ($athlete->name_prefix) {
            $el->setAttribute('nameprefix', $athlete->name_prefix);
        }

        // HANDICAP
        if ($athlete->sportClasses->isNotEmpty()) {
            $el->appendChild($this->buildHandicap($athlete));
        }

        // Entries oder Results
        if ($this->exportType === 'entries') {
            $entries = $meet->entries()
                ->where('athlete_id', $athlete->id)
                ->where('club_id', $club->id)
                ->get();

            if ($entries->isNotEmpty()) {
                $entriesEl = $this->dom->createElement('ENTRIES');
                foreach ($entries as $entry) {
                    $entriesEl->appendChild($this->buildEntry($entry));
                }
                $el->appendChild($entriesEl);
            }
        } else {
            $results = $meet->results()
                ->where('athlete_id', $athlete->id)
                ->where('club_id', $club->id)
                ->with('splits')
                ->get();

            if ($results->isNotEmpty()) {
                $resultsEl = $this->dom->createElement('RESULTS');
                foreach ($results as $result) {
                    $resultsEl->appendChild($this->buildResult($result));
                }
                $el->appendChild($resultsEl);
            }
        }

        return $el;
    }

    // ── Handicap ──────────────────────────────────────────────────────────────

    /**
     * @throws DOMException
     */
    private function buildHandicap($athlete): DOMElement
    {
        $el = $this->dom->createElement('HANDICAP');

        $classes = $athlete->sportClasses->keyBy('category');

        // LENEX erwartet alle drei Attribute als Pflichtfeld
        $el->setAttribute('free', $classes->get('S')?->class_number ?? '0');
        $el->setAttribute('breast', $classes->get('SB')?->class_number ?? '0');
        $el->setAttribute('medley', $classes->get('SM')?->class_number ?? '0');

        // Status-Attribute (optional, neu in LENEX 3.0)
        if ($s = $classes->get('S')) {
            if ($s->status) {
                $el->setAttribute('freestatus', $s->status);
            }
        }
        if ($sb = $classes->get('SB')) {
            if ($sb->status) {
                $el->setAttribute('breaststatus', $sb->status);
            }
        }
        if ($sm = $classes->get('SM')) {
            if ($sm->status) {
                $el->setAttribute('medleystatus', $sm->status);
            }
        }

        return $el;
    }

    // ── Entry ─────────────────────────────────────────────────────────────────

    /**
     * @throws DOMException
     */
    private function buildEntry($entry): DOMElement
    {
        $el = $this->dom->createElement('ENTRY');

        $lenexEventId = $entry->swimEvent?->lenex_event_id ?? (string) $entry->swim_event_id;
        $el->setAttribute('eventid', $lenexEventId);

        if ($entry->entry_time) {
            $el->setAttribute('entrytime', $this->formatTime($entry->entry_time));
        } else {
            $el->setAttribute('entrytime', 'NT');
        }

        if ($entry->entry_course) {
            $el->setAttribute('entrycourse', $entry->entry_course);
        }
        if ($entry->sport_class) {
            $el->setAttribute('handicap', $entry->sport_class);
        }
        if ($entry->status) {
            $el->setAttribute('status', $entry->status);
        }
        if ($entry->heat) {
            $el->setAttribute('heatid', (string) $entry->heat);
        }
        if ($entry->lane) {
            $el->setAttribute('lane', (string) $entry->lane);
        }

        return $el;
    }

    // ── Result ────────────────────────────────────────────────────────────────

    /**
     * @throws DOMException
     */
    private function buildResult($result): DOMElement
    {
        $el = $this->dom->createElement('RESULT');

        $lenexEventId = $result->swimEvent?->lenex_event_id ?? (string) $result->swim_event_id;
        $el->setAttribute('eventid', $lenexEventId);
        $el->setAttribute('resultid', $result->lenex_result_id ?? (string) $result->id);

        if ($result->swim_time) {
            $el->setAttribute('swimtime', $this->formatTime($result->swim_time));
        } else {
            $el->setAttribute('swimtime', 'NT');
        }

        if ($result->status) {
            $el->setAttribute('status', $result->status);
        }
        if ($result->sport_class) {
            $el->setAttribute('handicap', $result->sport_class);
        }
        if ($result->points) {
            $el->setAttribute('points', (string) $result->points);
        }
        if ($result->place) {
            $el->setAttribute('place', (string) $result->place);
        }
        if ($result->heat) {
            $el->setAttribute('heatid', (string) $result->heat);
        }
        if ($result->lane) {
            $el->setAttribute('lane', (string) $result->lane);
        }
        if ($result->reaction_time !== null) {
            $sign = $result->reaction_time >= 0 ? '+' : '';
            $el->setAttribute('reactiontime', $sign.$result->reaction_time);
        }
        if ($result->comment) {
            $el->setAttribute('comment', $result->comment);
        }

        // Rekord-Flags
        $records = [];
        if ($result->is_world_record) {
            $records[] = 'WR';
        }
        if ($result->is_european_record) {
            $records[] = 'ER';
        }
        if ($result->is_national_record) {
            $records[] = 'NR';
        }
        if (! empty($records)) {
            $el->setAttribute('recordtype', implode(' ', $records));
        }

        // Splits
        if ($result->splits->isNotEmpty()) {
            $splitsEl = $this->dom->createElement('SPLITS');
            foreach ($result->splits as $split) {
                $splitEl = $this->dom->createElement('SPLIT');
                $splitEl->setAttribute('distance', (string) $split->distance);
                $splitEl->setAttribute('swimtime', $this->formatTime($split->split_time));
                $splitsEl->appendChild($splitEl);
            }
            $el->appendChild($splitsEl);
        }

        return $el;
    }

    // ── Zeit-Formatierung ─────────────────────────────────────────────────────

    /**
     * Hundertstelsekunden → LENEX Zeitformat "HH:MM:SS.ss"
     */
    private function formatTime(int $centiseconds): string
    {
        $hours = intdiv($centiseconds, 360000);
        $minutes = intdiv($centiseconds % 360000, 6000);
        $seconds = intdiv($centiseconds % 6000, 100);
        $cs = $centiseconds % 100;

        return sprintf('%02d:%02d:%02d.%02d', $hours, $minutes, $seconds, $cs);
    }
}
