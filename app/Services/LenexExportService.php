<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Entry;
use App\Models\Meet;
use App\Models\MeetFee;
use App\Models\MeetSession;
use App\Models\RelayEntry;
use App\Models\RelayEntryMember;
use App\Models\RelayResult;
use App\Models\RelayResultMember;
use App\Models\Result;
use App\Models\ScoringGroup;
use App\Models\SwimEvent;
use App\Support\RelayNames;
use DOMDocument;
use DOMElement;
use DOMException;
use Illuminate\Support\Collection;

class LenexExportService
{
    private DOMDocument $dom;

    private string $exportType;

    /**
     * Verwendete Läufe je Bewerb (swim_event_id → sortierte Laufnummern) für EVENT > HEATS.
     *
     * @var array<int, list<int>>
     */
    private array $heatsByEvent = [];

    /**
     * Ergebnisexport: Schwimmer, die nur in Staffeln starten (club_id → Athleten). Sie stehen unter ihrem eigenen
     * Verein in ATHLETES, damit RELAYPOSITION athleteid auflösbar ist.
     *
     * @var array<int, Collection<int, Athlete>>
     */
    private array $relayOnlyAthletesByClub = [];

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

    /**
     * @throws DOMException
     */
    private function buildMeet(Meet $meet): DOMElement
    {
        $meet->load(['nation', 'swimEvents.strokeType', 'swimEvents.scoringGroups']);
        $this->heatsByEvent = $this->collectHeats($meet);

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

        $fees = $meet->fees()->get();
        $meetFees = $fees->whereNull('session_number');
        if ($meetFees->isNotEmpty()) {
            $el->appendChild($this->buildFees($meetFees));
        }

        $el->appendChild($this->buildSessions($meet, $fees));

        if (in_array($this->exportType, ['entries', 'results'])) {
            $clubsEl = $this->buildClubs($meet);
            if ($clubsEl->hasChildNodes()) {
                $el->appendChild($clubsEl);
            }
        }

        return $el;
    }

    /**
     * FEES > FEE (type, currency, value in Cent) einer Veranstaltung oder eines Abschnitts.
     *
     * @param  Collection<int, MeetFee>  $fees
     *
     * @throws DOMException
     */
    private function buildFees(Collection $fees): DOMElement
    {
        $feesEl = $this->dom->createElement('FEES');

        foreach ($fees as $fee) {
            $feeEl = $this->dom->createElement('FEE');
            $feeEl->setAttribute('type', $fee->type);
            $feeEl->setAttribute('currency', $fee->currency);
            $feeEl->setAttribute('value', (string) $fee->amount_cents);
            $feesEl->appendChild($feeEl);
        }

        return $feesEl;
    }

    /**
     * @param  Collection<int, MeetFee>  $fees  alle Gebühren der Veranstaltung; je SESSION werden die des Abschnitts
     *                                          herausgefiltert
     *
     * @throws DOMException
     */
    private function buildSessions(Meet $meet, Collection $fees): DOMElement
    {
        $sessionsEl = $this->dom->createElement('SESSIONS');
        $sessions = $meet->sessions()->get()->keyBy('number');

        foreach ($meet->swimEvents->groupBy('session_number') as $sessionNumber => $sessionEvents) {
            /** @var MeetSession|null $session */
            $session = $sessions->get($sessionNumber);

            $sessionEl = $this->dom->createElement('SESSION');
            $sessionEl->setAttribute('number', (string) $sessionNumber);
            // Datum je Abschnitt aus meet_sessions; ohne Eintrag wie bisher der Veranstaltungsbeginn.
            $sessionEl->setAttribute('date', ($session?->date ?? $meet->start_date)->format('Y-m-d'));
            if ($session?->daytime_short) {
                $sessionEl->setAttribute('daytime', $session->daytime_short);
            }

            $sessionFees = $fees->where('session_number', (int) $sessionNumber);
            if ($sessionFees->isNotEmpty()) {
                $sessionEl->appendChild($this->buildFees($sessionFees));
            }

            $eventsEl = $this->dom->createElement('EVENTS');
            foreach ($sessionEvents->sortBy('event_number') as $event) {
                $eventsEl->appendChild($this->buildEvent($event));
            }

            $sessionEl->appendChild($eventsEl);
            $sessionsEl->appendChild($sessionEl);
        }

        return $sessionsEl;
    }

    /**
     * @throws DOMException
     */
    private function buildEvent(SwimEvent $event): DOMElement
    {
        $el = $this->dom->createElement('EVENT');

        $lenexEventId = $event->lenex_event_id ?? (string) $event->id;
        $el->setAttribute('eventid', $lenexEventId);
        if ($event->event_number) {
            $el->setAttribute('number', (string) $event->event_number);
        }
        $el->setAttribute('gender', $event->gender);
        $el->setAttribute('round', $event->round);

        $styleEl = $this->dom->createElement('SWIMSTYLE');
        $styleEl->setAttribute('distance', (string) $event->distance);
        $styleEl->setAttribute('relaycount', (string) $event->relay_count);
        $styleEl->setAttribute('stroke', $event->strokeType?->lenex_code ?? 'FREE');
        $el->appendChild($styleEl);

        // EVENT > FEE: Gebühr je Meldung in diesem Bewerb (ohne type, Wert in Cent).
        if ($event->fee_cents !== null) {
            $feeEl = $this->dom->createElement('FEE');
            $feeEl->setAttribute('currency', 'EUR');
            $feeEl->setAttribute('value', (string) $event->fee_cents);
            $el->appendChild($feeEl);
        }

        $ageGroupsEl = $this->buildAgeGroups($event);
        if ($ageGroupsEl->hasChildNodes()) {
            $el->appendChild($ageGroupsEl);
        }

        // EVENT > HEATS: ENTRY/RESULT heatid verweisen auf diese Elemente, die Laufnummer steht in HEAT number.
        if (! empty($this->heatsByEvent[$event->id])) {
            $heatsEl = $this->dom->createElement('HEATS');
            foreach ($this->heatsByEvent[$event->id] as $heat) {
                $heatEl = $this->dom->createElement('HEAT');
                $heatEl->setAttribute('heatid', $this->heatId($event->id, $heat));
                $heatEl->setAttribute('number', (string) $heat);
                $heatEl->setAttribute('order', (string) $heat);
                $heatsEl->appendChild($heatEl);
            }
            $el->appendChild($heatsEl);
        }

        return $el;
    }

    /**
     * Laufnummern je Bewerb aus Meldungen (Meldeexport) bzw. Einzel- und Staffelergebnissen (Ergebnisexport).
     *
     * @return array<int, list<int>>
     */
    private function collectHeats(Meet $meet): array
    {
        $rows = match ($this->exportType) {
            'entries' => Entry::where('meet_id', $meet->id)->whereNotNull('heat')->get(['swim_event_id', 'heat']),
            'results' => Result::where('meet_id', $meet->id)->whereNotNull('heat')->get(['swim_event_id', 'heat'])
                ->concat(RelayResult::where('meet_id', $meet->id)->whereNotNull('heat')->get(['swim_event_id', 'heat'])),
            default => collect(),
        };

        return $rows->groupBy('swim_event_id')
            ->map(fn ($group) => $group->pluck('heat')->map(fn ($h) => (int) $h)->filter()->unique()->sort()->values()->all())
            ->all();
    }

    /** Eindeutige LENEX heatid je Bewerb und Lauf (z. B. Bewerb 1821, Lauf 2 → "1821002"). */
    private function heatId(int $swimEventId, int $heat): string
    {
        return (string) ($swimEventId * 1000 + $heat);
    }

    /**
     * Baut die AGEGROUPS eines Bewerbs.
     *
     * Mit Wertungsgruppen: je Gruppe eine AGEGROUP mit Name, Geschlecht, Klassen (handicap, kommagetrennt) und Alter;
     * beim Ergebnisexport zusätzlich die RANKINGS der Einzel- bzw. Staffelergebnisse je Gruppe (Platz wie in der
     * Ergebnisliste, place -1 für nicht gewertete).
     *
     * Staffelbewerbe ohne Wertungsgruppen (Ergebnisexport): je Wertung und Staffelklasse eine AGEGROUP mit handicap
     * und RANKINGS — daraus liest der Import Staffelklasse und Platz.
     *
     * Ohne Wertungsgruppen (Altbestand): pro Sportklasse eine AGEGROUP mit agegroupid, agemax/agemin=-1, handicap.
     *
     * @throws DOMException
     */
    private function buildAgeGroups(SwimEvent $event): DOMElement
    {
        $ageGroupsEl = $this->dom->createElement('AGEGROUPS');

        if ($event->scoringGroups->isNotEmpty()) {
            return $this->buildScoringGroupAgeGroups($event, $ageGroupsEl);
        }

        if ($event->relay_count > 1 && $this->exportType === 'results') {
            $this->appendRelayFallbackAgeGroups($event, $ageGroupsEl);
            if ($ageGroupsEl->hasChildNodes()) {
                return $ageGroupsEl;
            }
        }

        if (! $event->sport_classes || trim($event->sport_classes) === '') {
            return $ageGroupsEl;
        }

        $lenexEventId = $event->lenex_event_id ?? (string) $event->id;
        $classes = collect(preg_split('/[\s,]+/', trim($event->sport_classes)))
            ->filter(fn ($c) => $c !== '')
            ->values();

        foreach ($classes as $classNum) {
            $ag = $this->dom->createElement('AGEGROUP');
            $ag->setAttribute('agegroupid', $lenexEventId.'_'.$classNum);
            $ag->setAttribute('agemax', '-1');
            $ag->setAttribute('agemin', '-1');
            $ag->setAttribute('handicap', $classNum);
            $ageGroupsEl->appendChild($ag);
        }

        return $ageGroupsEl;
    }

    /**
     * @throws DOMException
     */
    private function buildScoringGroupAgeGroups(SwimEvent $event, DOMElement $ageGroupsEl): DOMElement
    {
        // Rangliste je Gruppe nur beim Ergebnisexport.
        $rowsByGroup = [];
        if ($this->exportType === 'results') {
            foreach ($this->rankedGroups($event) as $ranked) {
                if ($ranked['group'] !== null) {
                    $rowsByGroup[$ranked['group']->id] = $ranked['rows'];
                }
            }
        }

        foreach ($event->scoringGroups as $group) {
            /** @var ScoringGroup $group */
            $ag = $this->dom->createElement('AGEGROUP');
            $ag->setAttribute('agegroupid', $group->lenex_agegroup_id ?? 'G'.$group->id);
            $ag->setAttribute('name', $group->name);
            $ag->setAttribute('gender', $group->gender);
            $ag->setAttribute('agemin', (string) ($group->age_min ?? -1));
            $ag->setAttribute('agemax', (string) ($group->age_max ?? -1));
            if ($group->classNumbers() !== []) {
                $ag->setAttribute('handicap', implode(',', $group->classNumbers()));
            }

            if (! empty($rowsByGroup[$group->id])) {
                $ag->appendChild($this->buildRankings($rowsByGroup[$group->id]));
            }

            $ageGroupsEl->appendChild($ag);
        }

        return $ageGroupsEl;
    }

    /**
     * Staffelbewerb ohne Wertungsgruppen: je Wertung (M/F/X) und Staffelklasse eine AGEGROUP samt RANKINGS.
     *
     * @throws DOMException
     */
    private function appendRelayFallbackAgeGroups(SwimEvent $event, DOMElement $ageGroupsEl): void
    {
        $lenexEventId = $event->lenex_event_id ?? (string) $event->id;
        foreach ($this->rankedGroups($event) as $index => $ranked) {
            $ag = $this->dom->createElement('AGEGROUP');
            $ag->setAttribute('agegroupid', $lenexEventId.'_R'.($index + 1));
            $ag->setAttribute('name', $ranked['label']);
            $ag->setAttribute('gender', $ranked['gender'] ?? 'A');
            $ag->setAttribute('agemin', '-1');
            $ag->setAttribute('agemax', '-1');
            $classNumber = preg_replace('/\D+/', '', $ranked['name']);
            if ($classNumber !== '') {
                $ag->setAttribute('handicap', $classNumber);
            }
            $ag->appendChild($this->buildRankings($ranked['rows']));
            $ageGroupsEl->appendChild($ag);
        }
    }

    /**
     * Gewertete Gruppen eines Bewerbs (Einzel- bzw. Staffelergebnisse) wie in der Ergebnisliste.
     *
     * @return list<array{group: ?ScoringGroup, gender: ?string, name: string, label: string, missingPoints: int, rows: list<array{place: ?int, result: Result|RelayResult}>}>
     */
    private function rankedGroups(SwimEvent $event): array
    {
        $results = $event->relay_count > 1
            ? RelayResult::where('swim_event_id', $event->id)->with('members')->get()
            : Result::where('swim_event_id', $event->id)->with('athlete')->get();

        return (new ScoringGroupService)->rankedGroups($event, $results, (int) $event->meet->start_date->format('Y'));
    }

    /**
     * RANKINGS einer Gruppe: order, place (-1 = nicht gewertet), resultid.
     *
     * @param  list<array{place: ?int, result: Result|RelayResult}>  $rows
     *
     * @throws DOMException
     */
    private function buildRankings(array $rows): DOMElement
    {
        $rankingsEl = $this->dom->createElement('RANKINGS');
        foreach ($rows as $order => $row) {
            $ranking = $this->dom->createElement('RANKING');
            $ranking->setAttribute('order', (string) ($order + 1));
            $ranking->setAttribute('place', (string) ($row['place'] ?? -1));
            $ranking->setAttribute('resultid', $row['result']->lenex_result_id ?? (string) $row['result']->id);
            $rankingsEl->appendChild($ranking);
        }

        return $rankingsEl;
    }

    /**
     * @throws DOMException
     */
    private function buildClubs(Meet $meet): DOMElement
    {
        // Vereine aus den tatsächlichen Daten, nicht nur aus meet_club: Meldungen und
        // manuell erfasste Ergebnisse ordnen den Verein dort nicht zu.
        $clubIds = $this->exportType === 'entries' ? $meet->entryClubIds() : $meet->resultClubIds();
        if ($this->exportType === 'results') {
            $this->relayOnlyAthletesByClub = $this->relayOnlyAthletes($meet);
            $clubIds = $clubIds->merge(array_keys($this->relayOnlyAthletesByClub))->unique()->values();
        }

        $clubsEl = $this->dom->createElement('CLUBS');
        foreach ($meet->clubsByIds($clubIds) as $club) {
            $clubsEl->appendChild($this->buildClub($club, $meet));
        }

        return $clubsEl;
    }

    /**
     * @throws DOMException
     */
    private function buildClub(Club $club, Meet $meet): DOMElement
    {
        $el = $this->dom->createElement('CLUB');
        $el->setAttribute('name', $club->name);
        if ($club->code) {
            $el->setAttribute('code', $club->code);
        }
        if ($club->nation) {
            $el->setAttribute('nation', $club->nation->code);
        }
        if ($club->type && $club->type !== 'CLUB') {
            $el->setAttribute('type', $club->type);
        }

        $athletes = $this->collectAthletes($club, $meet);

        if ($athletes->isNotEmpty()) {
            $athletesEl = $this->dom->createElement('ATHLETES');
            foreach ($athletes as $athlete) {
                $athletesEl->appendChild($this->buildAthlete($athlete, $club, $meet));
            }
            $el->appendChild($athletesEl);
        }

        $relaysEl = $this->exportType === 'entries'
            ? $this->buildRelays($club, $meet)
            : $this->buildRelayResults($club, $meet);
        if ($relaysEl->hasChildNodes()) {
            $el->appendChild($relaysEl);
        }

        return $el;
    }

    /**
     * Schwimmer aus Staffelergebnissen ohne eigenes Einzelergebnis, gruppiert nach ihrem Verein (ohne Verein: Verein
     * der Staffel). Schwimmer ohne Athleten-Datensatz (nur Namenskopie) lassen sich nicht referenzieren.
     *
     * @return array<int, Collection<int, Athlete>>
     */
    private function relayOnlyAthletes(Meet $meet): array
    {
        $withResults = Result::where('meet_id', $meet->id)->pluck('athlete_id')->flip();
        $members = RelayResultMember::whereNotNull('athlete_id')
            ->whereHas('relayResult', fn ($q) => $q->where('meet_id', $meet->id))
            ->with(['athlete.sportClasses', 'relayResult'])
            ->get();

        $byClub = [];
        foreach ($members as $member) {
            if (! $member->athlete || $withResults->has($member->athlete_id)) {
                continue;
            }
            $byClub[$member->athlete->club_id ?? $member->relayResult->club_id][$member->athlete_id] = $member->athlete;
        }

        return array_map(fn (array $athletes) => collect(array_values($athletes)), $byClub);
    }

    /**
     * Sammelt alle Athleten des Clubs für dieses Meet (Einzel + Staffel, dedupliziert).
     *
     * @return Collection<Athlete>
     */
    private function collectAthletes(Club $club, Meet $meet): Collection
    {
        if ($this->exportType === 'entries') {
            $entryAthletes = Entry::where('meet_id', $meet->id)
                ->where('club_id', $club->id)
                ->with('athlete.sportClasses')
                ->get()->pluck('athlete')->filter();

            $relayEntryIds = RelayEntry::where('meet_id', $meet->id)
                ->where('club_id', $club->id)
                ->pluck('id');

            $relayAthletes = $relayEntryIds->isNotEmpty()
                ? RelayEntryMember::whereIn('relay_entry_id', $relayEntryIds)
                    ->with('athlete.sportClasses')->get()->pluck('athlete')->filter()
                : collect();

            return $entryAthletes->merge($relayAthletes)->unique('id')->values();
        }

        return Result::where('meet_id', $meet->id)
            ->where('club_id', $club->id)
            ->with('athlete.sportClasses')->get()
            ->pluck('athlete')->filter()
            ->merge($this->relayOnlyAthletesByClub[$club->id] ?? collect())
            ->unique('id')->values();
    }

    /**
     * @throws DOMException
     */
    private function buildAthlete($athlete, Club $club, Meet $meet): DOMElement
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
            $el->setAttribute('license_ipc', (string) $athlete->license_ipc);
        }
        if ($athlete->nation) {
            $el->setAttribute('nation', $athlete->nation->code);
        }
        if ($athlete->name_prefix) {
            $el->setAttribute('nameprefix', $athlete->name_prefix);
        }
        if ($athlete->level) {
            $el->setAttribute('level', $athlete->level);
        }
        if ($athlete->swrid) {
            $el->setAttribute('swrid', (string) $athlete->swrid);
        }

        if ($athlete->sportClasses->isNotEmpty()) {
            $el->appendChild($this->buildHandicap($athlete));
        }

        if ($this->exportType === 'entries') {
            $entries = Entry::where('meet_id', $meet->id)
                ->where('athlete_id', $athlete->id)
                ->where('club_id', $club->id)
                ->with('swimEvent')->get();

            if ($entries->isNotEmpty()) {
                $entriesEl = $this->dom->createElement('ENTRIES');
                foreach ($entries as $entry) {
                    $entriesEl->appendChild($this->buildEntry($entry));
                }
                $el->appendChild($entriesEl);
            }
        } else {
            $results = Result::where('meet_id', $meet->id)
                ->where('athlete_id', $athlete->id)
                ->where('club_id', $club->id)
                ->with(['splits', 'swimEvent'])->get();

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

    /**
     * @throws DOMException
     */
    private function buildHandicap($athlete): DOMElement
    {
        $el = $this->dom->createElement('HANDICAP');
        $classes = $athlete->sportClasses->keyBy('category');
        $el->setAttribute('free', $classes->get('S')?->class_number ?? '0');
        $el->setAttribute('breast', $classes->get('SB')?->class_number ?? '0');
        $el->setAttribute('medley', $classes->get('SM')?->class_number ?? '0');
        if (($s = $classes->get('S')) && $s->status) {
            $el->setAttribute('freestatus', $s->status);
        }
        if (($sb = $classes->get('SB')) && $sb->status) {
            $el->setAttribute('breaststatus', $sb->status);
        }
        if (($sm = $classes->get('SM')) && $sm->status) {
            $el->setAttribute('medleystatus', $sm->status);
        }

        return $el;
    }

    /**
     * @throws DOMException
     */
    private function buildEntry($entry): DOMElement
    {
        $el = $this->dom->createElement('ENTRY');
        $lenexEventId = $entry->swimEvent?->lenex_event_id ?? (string) $entry->swim_event_id;
        $el->setAttribute('eventid', $lenexEventId);
        $el->setAttribute('entrytime', $entry->entry_time ? $this->formatTime($entry->entry_time) : 'NT');
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
            $el->setAttribute('heatid', $this->heatId($entry->swim_event_id, $entry->heat));
        }
        if ($entry->lane) {
            $el->setAttribute('lane', (string) $entry->lane);
        }

        return $el;
    }

    /**
     * Hundertstelsekunden → LENEX Zeitformat "HH:MM:SS.ss"
     */
    private function formatTime(int $centiseconds): string
    {
        return sprintf('%02d:%02d:%02d.%02d',
            intdiv($centiseconds, 360000),
            intdiv($centiseconds % 360000, 6000),
            intdiv($centiseconds % 6000, 100),
            $centiseconds % 100
        );
    }

    /**
     * @throws DOMException
     */
    private function buildResult(Result $result): DOMElement
    {
        $el = $this->dom->createElement('RESULT');
        $this->applyResultAttributes($el, $result);
        if ($result->sport_class) {
            $el->setAttribute('handicap', $result->sport_class);
        }
        if ($result->place) {
            $el->setAttribute('place', (string) $result->place);
        }
        if ($result->reaction_time !== null) {
            $el->setAttribute('reactiontime', ($result->reaction_time >= 0 ? '+' : '').$result->reaction_time);
        }
        $this->appendSplits($el, $result);

        return $el;
    }

    /** Gemeinsame RESULT-Attribute von Einzel- und Staffelergebnis. */
    private function applyResultAttributes(DOMElement $el, Result|RelayResult $result): void
    {
        $el->setAttribute('eventid', $result->swimEvent?->lenex_event_id ?? (string) $result->swim_event_id);
        $el->setAttribute('resultid', $result->lenex_result_id ?? (string) $result->id);
        $el->setAttribute('swimtime', $result->swim_time ? $this->formatTime($result->swim_time) : 'NT');
        if ($result->status) {
            $el->setAttribute('status', $result->status);
        }
        if ($result->points) {
            $el->setAttribute('points', (string) $result->points);
        }
        if ($result->heat) {
            $el->setAttribute('heatid', $this->heatId($result->swim_event_id, $result->heat));
        }
        if ($result->lane) {
            $el->setAttribute('lane', (string) $result->lane);
        }
        if ($result->comment) {
            $el->setAttribute('comment', $result->comment);
        }

        $records = array_keys(array_filter([
            'WR' => $result->is_world_record,
            'ER' => $result->is_european_record,
            'NR' => $result->is_national_record,
        ]));
        if ($records !== []) {
            $el->setAttribute('recordtype', implode(' ', $records));
        }
    }

    /**
     * @throws DOMException
     */
    private function appendSplits(DOMElement $el, Result|RelayResult $result): void
    {
        if ($result->splits->isEmpty()) {
            return;
        }

        $splitsEl = $this->dom->createElement('SPLITS');
        foreach ($result->splits as $split) {
            $splitEl = $this->dom->createElement('SPLIT');
            $splitEl->setAttribute('distance', (string) $split->distance);
            $splitEl->setAttribute('swimtime', $this->formatTime($split->split_time));
            $splitsEl->appendChild($splitEl);
        }
        $el->appendChild($splitsEl);
    }

    /**
     * Ergebnisexport: RELAYS des Vereins mit je einem RESULT (Zeit, Status, Punkte, Lauf/Bahn, Zwischenzeiten,
     * RELAYPOSITIONS). Die Staffelklasse steht in RELAY handicap und in der AGEGROUP der Rangliste.
     *
     * @throws DOMException
     */
    private function buildRelayResults(Club $club, Meet $meet): DOMElement
    {
        $relaysEl = $this->dom->createElement('RELAYS');
        $relayResults = RelayResult::where('meet_id', $meet->id)
            ->where('club_id', $club->id)
            ->with(['swimEvent', 'members.athlete', 'splits'])
            ->orderBy('swim_event_id')->orderBy('relay_number')->orderBy('id')->get();

        $eventCounters = [];
        foreach ($relayResults as $relayResult) {
            $eid = $relayResult->swim_event_id;
            $eventCounters[$eid] = ($eventCounters[$eid] ?? 0) + 1;

            $relayEl = $this->dom->createElement('RELAY');
            $relayEl->setAttribute('number', (string) ($relayResult->relay_number ?? $eventCounters[$eid]));
            if ($relayResult->name) {
                $relayEl->setAttribute('name', $relayResult->name);
            }
            $relayEl->setAttribute('gender', $relayResult->gender);
            $relayEl->setAttribute('agemax', '-1');
            $relayEl->setAttribute('agemin', '-1');
            $relayEl->setAttribute('agetotalmax', '-1');
            $relayEl->setAttribute('agetotalmin', '-1');
            $classNumber = preg_replace('/\D+/', '', (string) $relayResult->relay_class);
            if ($classNumber !== '') {
                $relayEl->setAttribute('handicap', $classNumber);
            }

            $resultEl = $this->dom->createElement('RESULT');
            $this->applyResultAttributes($resultEl, $relayResult);
            $this->appendSplits($resultEl, $relayResult);

            // Nur Schwimmer mit Athleten-Datensatz sind über athleteid referenzierbar.
            $members = $relayResult->members->filter(fn (RelayResultMember $m) => $m->athlete !== null);
            if ($members->isNotEmpty()) {
                $positionsEl = $this->dom->createElement('RELAYPOSITIONS');
                foreach ($members as $member) {
                    $positionEl = $this->dom->createElement('RELAYPOSITION');
                    $positionEl->setAttribute('number', (string) $member->position);
                    $positionEl->setAttribute('athleteid', $member->athlete->lenex_athlete_id ?? (string) $member->athlete_id);
                    if ($member->reaction_time !== null) {
                        $positionEl->setAttribute('reactiontime', ($member->reaction_time >= 0 ? '+' : '').$member->reaction_time);
                    }
                    $positionsEl->appendChild($positionEl);
                }
                $resultEl->appendChild($positionsEl);
            }

            $resultsEl = $this->dom->createElement('RESULTS');
            $resultsEl->appendChild($resultEl);
            $relayEl->appendChild($resultsEl);
            $relaysEl->appendChild($relayEl);
        }

        return $relaysEl;
    }

    /**
     * @throws DOMException
     */
    private function buildRelays(Club $club, Meet $meet): DOMElement
    {
        $relaysEl = $this->dom->createElement('RELAYS');
        $relayEntries = RelayEntry::where('meet_id', $meet->id)
            ->where('club_id', $club->id)
            ->with(['swimEvent', 'members.athlete'])
            ->orderBy('swim_event_id')->orderBy('id')->get();
        // club-Relation für den Anzeigenamen setzen statt je Staffel nachzuladen — alle gehören zu $club.
        $relayEntries->each(fn (RelayEntry $r) => $r->setRelation('club', $club));
        $relayNames = RelayNames::for($relayEntries);

        $eventCounters = [];
        foreach ($relayEntries as $relayEntry) {
            $eid = $relayEntry->swim_event_id;
            $eventCounters[$eid] = ($eventCounters[$eid] ?? 0) + 1;
            // Importierte Meldungen behalten ihre RELAY number, App-Meldungen zählen nach Anlage.
            $relaysEl->appendChild(
                $this->buildRelay($relayEntry, $relayEntry->relay_number ?? $eventCounters[$eid], $relayNames[$relayEntry->id])
            );
        }

        return $relaysEl;
    }

    /**
     * @throws DOMException
     */
    private function buildRelay(RelayEntry $relayEntry, int $number, string $name): DOMElement
    {
        $el = $this->dom->createElement('RELAY');
        $el->setAttribute('number', (string) $number);
        // Anzeigename wie in den Meldelisten (App\Support\RelayNames) — "number" bleibt die technische
        // laufende Nummer aller Staffeln des Vereins im Bewerb.
        if ($name !== '') {
            $el->setAttribute('name', $name);
        }
        $el->setAttribute('agemax', '-1');
        $el->setAttribute('agemin', '-1');
        $el->setAttribute('agetotalmax', '-1');
        $el->setAttribute('agetotalmin', '-1');

        if ($relayEntry->swimEvent?->gender) {
            $el->setAttribute('gender', $relayEntry->swimEvent->gender);
        }

        $lenexEventId = $relayEntry->swimEvent?->lenex_event_id
            ?? (string) $relayEntry->swim_event_id;

        $entryEl = $this->dom->createElement('ENTRY');
        $entryEl->setAttribute('eventid', $lenexEventId);
        $entryEl->setAttribute('entrytime',
            $relayEntry->entry_time ? $this->formatTime($relayEntry->entry_time) : 'NT'
        );
        if ($relayEntry->entry_course) {
            $entryEl->setAttribute('entrycourse', $relayEntry->entry_course);
        }
        // Außer Konkurrenz — wie bei Einzelmeldungen (entries.status) als LENEX-Status EXH.
        if ($relayEntry->is_exhibition) {
            $entryEl->setAttribute('status', 'EXH');
        }

        $members = $relayEntry->members;
        if ($members->isNotEmpty()) {
            $positionsEl = $this->dom->createElement('RELAYPOSITIONS');
            foreach ($members as $index => $member) {
                $positionsEl->appendChild(
                    $this->buildRelayPosition($member, $member->position ?? ($index + 1))
                );
            }
            $entryEl->appendChild($positionsEl);
        }

        $entriesEl = $this->dom->createElement('ENTRIES');
        $entriesEl->appendChild($entryEl);
        $el->appendChild($entriesEl);

        return $el;
    }

    /**
     * @throws DOMException
     */
    private function buildRelayPosition(RelayEntryMember $member, int $position): DOMElement
    {
        $el = $this->dom->createElement('RELAYPOSITION');
        $el->setAttribute('number', (string) $position);
        $el->setAttribute('athleteid',
            $member->athlete?->lenex_athlete_id ?? (string) $member->athlete_id
        );
        if ($member->sport_class) {
            $el->setAttribute('handicap', $member->sport_class);
        }

        return $el;
    }
}
