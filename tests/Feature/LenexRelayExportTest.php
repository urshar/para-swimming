<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Entry;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\RelayEntry;
use App\Models\RelayEntryMember;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Services\LenexExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('lenex-relay-export');

// ── Helper-Funktionen (Phase-Suffix _p7) ─────────────────────────────────────

function makeNation_p7(): Nation
{
    return Nation::forceCreate([
        'code' => 'AUT',
        'name_de' => 'Österreich',
        'name_en' => 'Austria',
        'is_active' => true,
    ]);
}

function makeClub_p7(Nation $nation, string $code = 'BSV'): Club
{
    return Club::create([
        'name' => 'BSV Spittal',
        'short_name' => $code,
        'code' => $code,
        'nation_id' => $nation->id,
        'type' => 'CLUB',
    ]);
}

function makeMeet_p7(Nation $nation, array $clubs = []): Meet
{
    $meet = Meet::create([
        'name' => 'Österreichische Meisterschaften',
        'city' => 'Wien',
        'course' => 'SCM',
        'nation_id' => $nation->id,
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-02',
    ]);

    if (! empty($clubs)) {
        $meet->clubs()->attach(array_map(fn ($c) => $c->id, $clubs));
    }

    return $meet;
}

function makeStrokeType_p7(string $code = 'IMRELAY'): StrokeType
{
    return StrokeType::create([
        'lenex_code' => $code,
        'name_de' => 'Lagen-Staffel',
        'name_en' => 'Medley Relay',
        'code' => 'IMREL',
    ]);
}

function makeRelayEvent_p7(Meet $meet, StrokeType $strokeType, int $session = 1): SwimEvent
{
    return SwimEvent::create([
        'meet_id' => $meet->id,
        'stroke_type_id' => $strokeType->id,
        'distance' => 100,
        'relay_count' => 4,
        'gender' => 'X',
        'sport_classes' => '20',
        'event_number' => 10,
        'session_number' => $session,
        'round' => 'FIN',
        'lenex_event_id' => 'EVT-10',
    ]);
}

function makeAthlete_p7(Club $club, Nation $nation, string $lastName = 'Muster'): Athlete
{
    static $counter = 0;
    $counter++;

    return Athlete::create([
        'club_id' => $club->id,
        'first_name' => 'Max',
        'last_name' => $lastName.$counter,
        'gender' => 'M',
        'nation_id' => $nation->id,
    ]);
}

function makeRelayEntry_p7(Meet $meet, SwimEvent $event, Club $club, array $options = []): RelayEntry
{
    return RelayEntry::create(array_merge([
        'meet_id' => $meet->id,
        'swim_event_id' => $event->id,
        'club_id' => $club->id,
        'relay_class' => 'S20',
        'status' => 'pending',
    ], $options));
}

function addMember_p7(RelayEntry $relay, Athlete $athlete, int $position, string $sportClass): RelayEntryMember
{
    return RelayEntryMember::create([
        'relay_entry_id' => $relay->id,
        'athlete_id' => $athlete->id,
        'position' => $position,
        'sport_class' => $sportClass,
    ]);
}

// ── Hilfsfunktion: XML parsen ─────────────────────────────────────────────────

function parseXml_p7(string $xml): SimpleXMLElement
{
    $el = simplexml_load_string($xml);
    expect($el)->not->toBeFalse('XML konnte nicht geparst werden');

    return $el;
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('LENEX Relay Export', function () {

    // ── Grundstruktur ─────────────────────────────────────────────────────────

    it('exports a RELAY element with correct attributes', function () {
        $nation = makeNation_p7();
        $club = makeClub_p7($nation);
        $meet = makeMeet_p7($nation, [$club]);
        $stroke = makeStrokeType_p7();
        $event = makeRelayEvent_p7($meet, $stroke);
        $relay = makeRelayEntry_p7($meet, $event, $club, [
            'entry_time' => 27025,
            'entry_course' => 'SCM',
            'relay_class' => 'S20',
        ]);
        for ($i = 1; $i <= 4; $i++) {
            addMember_p7($relay, makeAthlete_p7($club, $nation, "Schwimmer$i"), $i, 'S'.($i + 4));
        }

        $xml = app(LenexExportService::class)->build($meet, 'entries');
        $root = parseXml_p7($xml);

        $relayEl = $root->MEETS->MEET->CLUBS->CLUB->RELAYS->RELAY;

        expect((string) $relayEl['eventid'])->toBe('EVT-10')
            ->and((string) $relayEl['number'])->toBe('1')
            ->and((string) $relayEl['entrytime'])->toBe('00:04:30.25')
            ->and((string) $relayEl['entrycourse'])->toBe('SCM')
            ->and((string) $relayEl['handicap'])->toBe('S20');
    });

    it('exports NT when entry_time is null', function () {
        $nation = makeNation_p7();
        $club = makeClub_p7($nation, 'NT1');
        $meet = makeMeet_p7($nation, [$club]);
        $stroke = makeStrokeType_p7();
        $event = makeRelayEvent_p7($meet, $stroke);
        $relay = makeRelayEntry_p7($meet, $event, $club, ['entry_time' => null]);

        for ($i = 1; $i <= 4; $i++) {
            addMember_p7($relay, makeAthlete_p7($club, $nation, "AthlNT$i"), $i, 'S5');
        }

        $xml = app(LenexExportService::class)->build($meet, 'entries');
        $root = parseXml_p7($xml);

        $relayEl = $root->MEETS->MEET->CLUBS->CLUB->RELAYS->RELAY;
        expect((string) $relayEl['entrytime'])->toBe('NT');
    });

    // ── RELAYPOSITIONS ────────────────────────────────────────────────────────

    it('exports RELAYPOSITIONS with correct number and athleteid', function () {
        $nation = makeNation_p7();
        $club = makeClub_p7($nation, 'POS');
        $meet = makeMeet_p7($nation, [$club]);
        $stroke = makeStrokeType_p7();
        $event = makeRelayEvent_p7($meet, $stroke);
        $relay = makeRelayEntry_p7($meet, $event, $club);
        $athletes = [];

        for ($i = 1; $i <= 4; $i++) {
            $a = makeAthlete_p7($club, $nation, "PosMember$i");
            addMember_p7($relay, $a, $i, 'S'.$i);
            $athletes[] = $a;
        }

        $xml = app(LenexExportService::class)->build($meet, 'entries');
        $root = parseXml_p7($xml);

        $positionList = $root->MEETS->MEET->CLUBS->CLUB->RELAYS->RELAY->RELAYPOSITIONS->RELAYPOSITION;
        $positions = iterator_to_array($positionList, false);
        expect(count($positions))->toBe(4);

        foreach ($positions as $idx => $pos) {
            expect((string) $pos['number'])->toBe((string) ($idx + 1))
                ->and((string) $pos['athleteid'])->toBe((string) $athletes[$idx]->id)
                ->and((string) $pos['handicap'])->toBe('S'.($idx + 1));
        }
    });

    it('uses lenex_athlete_id when available', function () {
        $nation = makeNation_p7();
        $club = makeClub_p7($nation, 'LXA');
        $meet = makeMeet_p7($nation, [$club]);
        $stroke = makeStrokeType_p7();
        $event = makeRelayEvent_p7($meet, $stroke);
        $relay = makeRelayEntry_p7($meet, $event, $club);

        // lenex_athlete_id existiert nicht in der athletes-Migration →
        // Wir testen den Fallback: member->athlete_id wird direkt verwendet
        // wenn athlete->lenex_athlete_id null ist.
        $athlete = makeAthlete_p7($club, $nation, 'LenexAth');
        addMember_p7($relay, $athlete, 1, 'S7');
        for ($i = 2; $i <= 4; $i++) {
            addMember_p7($relay, makeAthlete_p7($club, $nation, "Fill$i"), $i, 'S5');
        }

        $xml = app(LenexExportService::class)->build($meet, 'entries');
        $root = parseXml_p7($xml);

        $firstPos = $root->MEETS->MEET->CLUBS->CLUB->RELAYS->RELAY->RELAYPOSITIONS->RELAYPOSITION[0];
        // Fallback: DB-ID wird als athleteid verwendet
        expect((string) $firstPos['athleteid'])->toBe((string) $athlete->id);
    });

    // ── Mehrere Staffeln pro Event ─────────────────────────────────────────────

    it('numbers multiple relays in the same event sequentially', function () {
        $nation = makeNation_p7();
        $club = makeClub_p7($nation, 'SEQ');
        $meet = makeMeet_p7($nation, [$club]);
        $stroke = makeStrokeType_p7();
        $event = makeRelayEvent_p7($meet, $stroke);

        $relayA = makeRelayEntry_p7($meet, $event, $club, ['relay_class' => 'S20']);
        for ($i = 1; $i <= 4; $i++) {
            addMember_p7($relayA, makeAthlete_p7($club, $nation, "A$i"), $i, 'S5');
        }

        $relayB = makeRelayEntry_p7($meet, $event, $club, ['relay_class' => 'S20']);
        for ($i = 1; $i <= 4; $i++) {
            addMember_p7($relayB, makeAthlete_p7($club, $nation, "B$i"), $i, 'S6');
        }

        $xml = app(LenexExportService::class)->build($meet, 'entries');
        $root = parseXml_p7($xml);

        $relays = $root->MEETS->MEET->CLUBS->CLUB->RELAYS->RELAY;
        expect(count($relays))->toBe(2)
            ->and((string) $relays[0]['number'])->toBe('1')
            ->and((string) $relays[1]['number'])->toBe('2');
    });

    // ── Athleten im ATHLETES-Block ────────────────────────────────────────────

    it('includes relay-only athletes in the ATHLETES block', function () {
        $nation = makeNation_p7();
        $club = makeClub_p7($nation, 'RLY');
        $meet = makeMeet_p7($nation, [$club]);
        $stroke = makeStrokeType_p7();
        $event = makeRelayEvent_p7($meet, $stroke);
        $relay = makeRelayEntry_p7($meet, $event, $club);

        $athletes = [];
        for ($i = 1; $i <= 4; $i++) {
            $a = makeAthlete_p7($club, $nation, "RelayOnly$i");
            addMember_p7($relay, $a, $i, 'S5');
            $athletes[] = $a;
        }

        $xml = app(LenexExportService::class)->build($meet, 'entries');
        $root = parseXml_p7($xml);

        $athleteIds = collect(iterator_to_array($root->MEETS->MEET->CLUBS->CLUB->ATHLETES->ATHLETE, false))
            ->map(fn ($a) => (string) $a['athleteid'])
            ->all();

        foreach ($athletes as $athlete) {
            expect($athleteIds)->toContain((string) $athlete->id);
        }
    });

    it('does not duplicate athletes that have both an entry and a relay membership', function () {
        $nation = makeNation_p7();
        $club = makeClub_p7($nation, 'DUP');
        $meet = makeMeet_p7($nation, [$club]);
        $stroke = makeStrokeType_p7();
        $event = makeRelayEvent_p7($meet, $stroke);
        $sharedAthlete = makeAthlete_p7($club, $nation, 'Shared');

        Entry::create([
            'meet_id' => $meet->id,
            'swim_event_id' => $event->id,
            'athlete_id' => $sharedAthlete->id,
            'club_id' => $club->id,
            'entry_time' => null,
            'sport_class' => 'S5',
        ]);

        $relay = makeRelayEntry_p7($meet, $event, $club);
        addMember_p7($relay, $sharedAthlete, 1, 'S5');
        for ($i = 2; $i <= 4; $i++) {
            addMember_p7($relay, makeAthlete_p7($club, $nation, "Extra$i"), $i, 'S5');
        }

        $xml = app(LenexExportService::class)->build($meet, 'entries');
        $root = parseXml_p7($xml);

        $athleteIds = collect(iterator_to_array($root->MEETS->MEET->CLUBS->CLUB->ATHLETES->ATHLETE, false))
            ->map(fn ($a) => (string) $a['athleteid'])
            ->all();
        $occurrences = array_count_values($athleteIds)[(string) $sharedAthlete->id] ?? 0;
        expect($occurrences)->toBe(1);
    });

    // ── Kein RELAYS-Block wenn keine Staffeln vorhanden ───────────────────────

    it('omits RELAYS element when no relay entries exist', function () {
        $nation = makeNation_p7();
        $club = makeClub_p7($nation, 'NOR');
        $meet = makeMeet_p7($nation, [$club]);
        $stroke = makeStrokeType_p7();
        $event = makeRelayEvent_p7($meet, $stroke);
        $athlete = makeAthlete_p7($club, $nation, 'SoloAth');

        Entry::create([
            'meet_id' => $meet->id,
            'swim_event_id' => $event->id,
            'athlete_id' => $athlete->id,
            'club_id' => $club->id,
            'entry_time' => null,
            'sport_class' => 'S5',
        ]);

        $xml = app(LenexExportService::class)->build($meet, 'entries');
        $root = parseXml_p7($xml);

        $relays = $root->MEETS->MEET->CLUBS->CLUB->RELAYS;
        expect((array) $relays)->toBeEmpty();
    });

    // ── results-Export bleibt unverändert ─────────────────────────────────────

    it('does not include RELAYS in results export', function () {
        $nation = makeNation_p7();
        $club = makeClub_p7($nation, 'RES');
        $meet = makeMeet_p7($nation, [$club]);
        $stroke = makeStrokeType_p7();
        $event = makeRelayEvent_p7($meet, $stroke);
        $relay = makeRelayEntry_p7($meet, $event, $club);

        for ($i = 1; $i <= 4; $i++) {
            addMember_p7($relay, makeAthlete_p7($club, $nation, "ResAth$i"), $i, 'S5');
        }

        $xml = app(LenexExportService::class)->build($meet, 'results');
        expect($xml)->not->toContain('<RELAYS>');
    });

    // ── lenex_event_id Fallback ───────────────────────────────────────────────

    it('falls back to swim_event id when lenex_event_id is null', function () {
        $nation = makeNation_p7();
        $club = makeClub_p7($nation, 'FBK');
        $meet = makeMeet_p7($nation, [$club]);
        $stroke = makeStrokeType_p7();

        $event = SwimEvent::create([
            'meet_id' => $meet->id,
            'stroke_type_id' => $stroke->id,
            'distance' => 100,
            'relay_count' => 4,
            'gender' => 'X',
            'event_number' => 5,
            'session_number' => 1,
            'round' => 'FIN',
            'lenex_event_id' => null,
        ]);

        $relay = makeRelayEntry_p7($meet, $event, $club);
        for ($i = 1; $i <= 4; $i++) {
            addMember_p7($relay, makeAthlete_p7($club, $nation, "FbkAth$i"), $i, 'S5');
        }

        $xml = app(LenexExportService::class)->build($meet, 'entries');
        $root = parseXml_p7($xml);

        $relayEl = $root->MEETS->MEET->CLUBS->CLUB->RELAYS->RELAY;
        expect((string) $relayEl['eventid'])->toBe((string) $event->id);
    });

});
