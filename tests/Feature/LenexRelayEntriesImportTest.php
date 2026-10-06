<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\RelayEntry;
use App\Models\RelayEntryMember;
use App\Models\SwimEvent;
use App\Services\LenexExportService;
use App\Services\LenexParserService;
use App\Services\LenexResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('relay-results-p2');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

/** Veranstaltung mit Staffelbewerb 8 (4x50 m Freistil) und vier Athleten des Vereins mit Lizenz REM1..REM4. */
function setup_rem(): array
{
    makeStrokeType_p5();
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $event = makeEvent_p5($meet, ['event_number' => 8, 'distance' => 50, 'relay_count' => 4, 'gender' => 'A', 'sport_classes' => null]);
    $athletes = [];
    foreach ([1, 2, 3, 4] as $n) {
        $athletes[$n] = makeAthlete_p5($club, 'M', ['S14']);
        $athletes[$n]->update(['last_name' => 'Melder'.$n, 'license' => 'REM'.$n]);
    }

    return [$meet, $event, $club, $athletes];
}

/**
 * Meldedatei mit einer Staffel des Vereins; $positions = LENEX athleteids je Position (99 = nicht in der Datei),
 * $classes = HANDICAP free je athleteid.
 *
 * @param  list<int>  $positions
 * @param  array<int, int>  $classes
 */
function entriesXml_rem(Club $club, string $relayAttributes, string $entryAttributes, array $positions, array $classes): string
{
    $athletes = '';
    foreach ($classes as $id => $class) {
        $athletes .= '<ATHLETE athleteid="'.$id.'" lastname="Melder'.$id.'" firstname="Max" gender="M" license="REM'.$id.'">'
            .'<HANDICAP free="'.$class.'" breast="'.$class.'" medley="'.$class.'" /></ATHLETE>';
    }
    $positionsXml = implode('', array_map(
        fn (int $id, int $i) => '<RELAYPOSITION athleteid="'.$id.'" number="'.($i + 1).'" />', $positions, array_keys($positions)
    ));

    return '<?xml version="1.0" encoding="UTF-8"?>
<LENEX version="3.0"><MEETS><MEET name="Testmeet" city="Wien" nation="AUT" course="LCM" startdate="2025-06-15">
<SESSIONS><SESSION number="1" date="2025-06-15"><EVENTS>
  <EVENT eventid="80" number="8" round="TIM"><SWIMSTYLE distance="50" relaycount="4" stroke="FREE" /></EVENT>
</EVENTS></SESSION></SESSIONS>
<CLUBS><CLUB name="'.$club->name.'" code="'.$club->code.'" nation="AUT">
  <ATHLETES>'.$athletes.'</ATHLETES>
  <RELAYS><RELAY gender="M" '.$relayAttributes.'><ENTRIES>
    <ENTRY eventid="80" '.$entryAttributes.'><RELAYPOSITIONS>'.$positionsXml.'</RELAYPOSITIONS></ENTRY>
  </ENTRIES></RELAY></RELAYS>
</CLUB></CLUBS></MEET></MEETS></LENEX>';
}

/** Import in eine bestehende Veranstaltung; liefert die Statistik (Exception → RuntimeException). */
function import_rem(string $xml, int $meetId): array
{
    $path = tempnam(sys_get_temp_dir(), 'lenex_rem').'.lef';
    file_put_contents($path, $xml);

    try {
        return (new LenexParserService)->import($path, new LenexResolverService, $meetId)['stats'];
    } catch (Exception $e) {
        throw new RuntimeException('LENEX-Import fehlgeschlagen: '.$e->getMessage(), previous: $e);
    } finally {
        unlink($path);
    }
}

// ── Import ────────────────────────────────────────────────────────────────────

it('importiert Staffelmeldungen samt Schwimmern, Klasse und Meldezeit', function () {
    [$meet, $event, $club, $athletes] = setup_rem();
    $xml = entriesXml_rem($club, 'number="1" name="WAT I" handicap="14"', 'entrytime="00:02:30.00" entrycourse="LCM"',
        [1, 2, 3, 99], [1 => 14, 2 => 14, 3 => 21]);

    $stats = import_rem($xml, $meet->id);

    $entry = RelayEntry::with('members')->sole();
    expect($stats['relay_entries'])->toBe(1)
        ->and($entry->swim_event_id)->toBe($event->id)
        ->and($entry->club_id)->toBe($club->id)
        ->and($entry->relay_number)->toBe(1)
        ->and($entry->name)->toBe('WAT I')
        ->and($entry->relay_class)->toBe('S14')
        ->and($entry->entry_time)->toBe(15000)
        ->and($entry->entry_course)->toBe('LCM')
        ->and($entry->status)->toBe('confirmed')
        ->and($entry->is_exhibition)->toBeFalse()
        // Position 4 verweist auf keinen Athleten der Datei und wird ausgelassen.
        ->and($entry->members->pluck('athlete_id')->all())->toBe([$athletes[1]->id, $athletes[2]->id, $athletes[3]->id])
        ->and($entry->members->pluck('position')->all())->toBe([1, 2, 3]);
});

it('leitet die Staffelklasse ohne RELAY handicap aus den Schwimmern ab und erkennt AK', function () {
    [$meet, , $club] = setup_rem();
    $xml = entriesXml_rem($club, 'number="2"', 'entrytime="NT" status="EXH"', [1, 2, 3, 4], [1 => 11, 2 => 12, 3 => 13, 4 => 11]);

    import_rem($xml, $meet->id);

    $entry = RelayEntry::sole();
    expect($entry->relay_class)->toBe('S49')
        ->and($entry->entry_time)->toBeNull()
        ->and($entry->is_exhibition)->toBeTrue();
});

it('legt beim erneuten Import keine Staffelmeldung doppelt an', function () {
    [$meet, , $club] = setup_rem();
    $first = entriesXml_rem($club, 'number="1" handicap="14"', 'entrytime="00:02:30.00"', [1, 2, 3, 4], [1 => 14, 2 => 14, 3 => 14, 4 => 14]);
    $second = entriesXml_rem($club, 'number="1" handicap="14"', 'entrytime="00:02:20.00"', [4, 3, 2, 1], [1 => 14, 2 => 14, 3 => 14, 4 => 14]);

    import_rem($first, $meet->id);
    import_rem($second, $meet->id);

    $entry = RelayEntry::with('members.athlete')->sole();
    expect($entry->entry_time)->toBe(14000)
        ->and(RelayEntryMember::count())->toBe(4)
        ->and($entry->members->first()->athlete->license)->toBe('REM4');
});

it('ordnet eine exportierte App-Meldung beim Zurückspielen der vorhandenen zu', function () {
    [$meet, $event, $club, $athletes] = setup_rem();
    $appEntry = RelayEntry::create([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'club_id' => $club->id,
        'relay_class' => 'S14', 'entry_time' => 15500, 'status' => 'confirmed',
    ]);
    foreach (array_values($athletes) as $i => $athlete) {
        RelayEntryMember::create(['relay_entry_id' => $appEntry->id, 'athlete_id' => $athlete->id, 'position' => $i + 1, 'sport_class' => 'S14']);
    }

    try {
        $xml = (new LenexExportService)->build($meet->fresh(), 'entries');
    } catch (DOMException $e) {
        throw new RuntimeException('LENEX-Export fehlgeschlagen: '.$e->getMessage(), previous: $e);
    }
    import_rem($xml, $meet->id);

    expect(RelayEntry::count())->toBe(1)
        ->and($appEntry->fresh()->relay_number)->toBe(1)
        ->and($appEntry->fresh()->entry_time)->toBe(15500)
        ->and(RelayEntryMember::count())->toBe(4);
});

it('übernimmt keine Staffelmeldungen für Rahmenbewerbe', function () {
    [$meet, $event, $club] = setup_rem();
    $event->update(['is_scored' => false]);
    $xml = entriesXml_rem($club, 'number="1" handicap="14"', 'entrytime="00:02:30.00"', [1, 2, 3, 4], [1 => 14, 2 => 14, 3 => 14, 4 => 14]);

    import_rem($xml, $meet->id);

    expect(RelayEntry::count())->toBe(0)
        ->and(Athlete::count())->toBe(4)
        ->and(SwimEvent::count())->toBe(1)
        ->and(Meet::count())->toBe(1);
});
