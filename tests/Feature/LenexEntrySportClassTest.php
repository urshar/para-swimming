<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Entry;
use App\Models\Meet;
use App\Models\SwimEvent;
use App\Services\LenexParserService;
use App\Services\LenexResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('lenex-entry-sport-class');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

/**
 * Veranstaltung mit Bewerb 1 (Freistil), 2 (Brust) und 3 (Lagen) sowie einem Athleten mit Lizenz ESC1 und den
 * Sportklassen $dbClasses in der Datenbank.
 *
 * @param  list<string>  $dbClasses
 * @return array{Meet, Club, Athlete}
 */
function setup_esc(array $dbClasses): array
{
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    foreach ([1 => 'FREE', 2 => 'BREAST', 3 => 'MEDLEY'] as $number => $stroke) {
        makeEvent_p5($meet, [
            'event_number' => $number,
            'stroke_type_id' => makeStrokeType_p5($stroke)->id,
            'gender' => 'A',
            'sport_classes' => null,
        ]);
    }
    $athlete = makeAthlete_p5($club, 'M', $dbClasses);
    $athlete->update(['last_name' => 'Melder', 'license' => 'ESC1']);

    return [$meet, $club, $athlete];
}

/** Meldedatei mit Meldungen des Athleten ESC1 auf die Bewerbe 1–3; $athleteInner = HANDICAP-Element (oder leer). */
function entriesXml_esc(Club $club, string $athleteInner, string $entryAttributes = ''): string
{
    $entries = '';
    foreach ([10, 20, 30] as $eventId) {
        $entries .= '<ENTRY eventid="'.$eventId.'" entrytime="00:01:10.00" '.$entryAttributes.' />';
    }

    return '<?xml version="1.0" encoding="UTF-8"?>
<LENEX version="3.0"><MEETS><MEET name="Testmeet" city="Wien" nation="AUT" course="LCM" startdate="2025-06-15">
<SESSIONS><SESSION number="1" date="2025-06-15"><EVENTS>
  <EVENT eventid="10" number="1" round="TIM"><SWIMSTYLE distance="100" relaycount="1" stroke="FREE" /></EVENT>
  <EVENT eventid="20" number="2" round="TIM"><SWIMSTYLE distance="100" relaycount="1" stroke="BREAST" /></EVENT>
  <EVENT eventid="30" number="3" round="TIM"><SWIMSTYLE distance="100" relaycount="1" stroke="MEDLEY" /></EVENT>
</EVENTS></SESSION></SESSIONS>
<CLUBS><CLUB name="'.$club->name.'" code="'.$club->code.'" nation="AUT">
  <ATHLETES><ATHLETE athleteid="1" lastname="Melder" firstname="Max" gender="M" license="ESC1">'
        .$athleteInner.'<ENTRIES>'.$entries.'</ENTRIES></ATHLETE></ATHLETES>
</CLUB></CLUBS></MEET></MEETS></LENEX>';
}

/** Import in eine bestehende Veranstaltung (Exception → RuntimeException). */
function import_esc(string $xml, int $meetId): void
{
    $path = tempnam(sys_get_temp_dir(), 'lenex_esc').'.lef';
    file_put_contents($path, $xml);

    try {
        (new LenexParserService)->import($path, new LenexResolverService, $meetId);
    } catch (Exception $e) {
        throw new RuntimeException('LENEX-Import fehlgeschlagen: '.$e->getMessage(), previous: $e);
    } finally {
        unlink($path);
    }
}

/**
 * Sportklasse der Meldung je Bewerbsnummer.
 *
 * @return array<int, string|null>
 */
function entryClasses_esc(Meet $meet): array
{
    return Entry::where('meet_id', $meet->id)->get()
        ->mapWithKeys(fn (Entry $e) => [SwimEvent::find($e->swim_event_id)->event_number => $e->sport_class])
        ->sortKeys()
        ->all();
}

// ── Tests ─────────────────────────────────────────────────────────────────────

it('übernimmt die Sportklasse aus ATHLETE.HANDICAP zur Lage des Bewerbs', function () {
    [$meet, $club] = setup_esc([]);

    import_esc(entriesXml_esc($club, '<HANDICAP free="7" breast="6" medley="7" />'), $meet->id);

    expect(entryClasses_esc($meet))->toBe([1 => 'S7', 2 => 'SB6', 3 => 'SM7']);
});

it('nimmt fehlende Klassen aus der Datenbank, wenn das HANDICAP nur free enthält', function () {
    [$meet, $club] = setup_esc(['S9', 'SB8', 'SM9']);

    import_esc(entriesXml_esc($club, '<HANDICAP free="3" exception="A, 1, 4" />'), $meet->id);

    expect(entryClasses_esc($meet))->toBe([1 => 'S3', 2 => 'SB8', 3 => 'SM9']);
});

it('nimmt die Klassen aus der Datenbank, wenn die Datei kein HANDICAP enthält', function () {
    [$meet, $club] = setup_esc(['S14', 'SB14', 'SM14']);

    import_esc(entriesXml_esc($club, ''), $meet->id);

    expect(entryClasses_esc($meet))->toBe([1 => 'S14', 2 => 'SB14', 3 => 'SM14']);
});

it('ergänzt beim handicap-Attribut der Meldung das Präfix zur Lage', function () {
    [$meet, $club] = setup_esc(['S9', 'SB8', 'SM9']);

    import_esc(entriesXml_esc($club, '', 'handicap="11"'), $meet->id);

    expect(entryClasses_esc($meet))->toBe([1 => 'S11', 2 => 'SB11', 3 => 'SM11']);
});

it('lässt eine vorhandene Klasse beim erneuten Import ohne Klassenangabe stehen', function () {
    [$meet, $club, $athlete] = setup_esc([]);
    import_esc(entriesXml_esc($club, '<HANDICAP free="5" breast="4" medley="5" />'), $meet->id);

    import_esc(entriesXml_esc($club, ''), $meet->id);

    expect(entryClasses_esc($meet))->toBe([1 => 'S5', 2 => 'SB4', 3 => 'SM5'])
        ->and(Entry::where('meet_id', $meet->id)->where('athlete_id', $athlete->id)->count())->toBe(3);
});

it('lässt die Klasse leer, wenn weder Datei noch Datenbank eine kennen', function () {
    [$meet, $club] = setup_esc([]);

    import_esc(entriesXml_esc($club, ''), $meet->id);

    expect(entryClasses_esc($meet))->toBe([1 => null, 2 => null, 3 => null]);
});
