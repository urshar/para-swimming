<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Result;
use App\Models\SwimEvent;
use App\Models\SwimRecord;
use App\Models\User;
use App\Services\LenexParserService;
use App\Services\LenexResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('lenex-placeholder-class');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

/**
 * Veranstaltung mit Bewerb 1 (Freistil) und 2 (Brust) sowie einem Athleten mit Lizenz PH1 und den Sportklassen
 * $dbClasses in der Datenbank.
 *
 * @param  list<string>  $dbClasses
 * @return array{Meet, Club, Athlete}
 */
function setup_ph(array $dbClasses): array
{
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    foreach ([1 => 'FREE', 2 => 'BREAST'] as $number => $stroke) {
        makeEvent_p5($meet, [
            'event_number' => $number,
            'stroke_type_id' => makeStrokeType_p5($stroke)->id,
            'gender' => 'A',
            'sport_classes' => null,
        ]);
    }
    $athlete = makeAthlete_p5($club, 'F', $dbClasses);
    $athlete->update(['last_name' => 'Platzhalter', 'license' => 'PH1']);

    return [$meet, $club, $athlete];
}

/** Ergebnisdatei mit Ergebnissen des Athleten PH1 in Bewerb 1 und 2; $athleteInner = HANDICAP-Element (oder leer). */
function resultsXml_ph(Club $club, string $athleteInner, string $resultAttributes): string
{
    $results = '';
    foreach ([10 => 1, 20 => 2] as $eventId => $resultId) {
        $results .= '<RESULT resultid="'.$resultId.'" eventid="'.$eventId.'" swimtime="00:01:10.00" '
            .$resultAttributes.' />';
    }

    return '<?xml version="1.0" encoding="UTF-8"?>
<LENEX version="3.0"><MEETS><MEET name="Testmeet" city="Wien" nation="AUT" course="LCM" startdate="2025-06-15">
<SESSIONS><SESSION number="1" date="2025-06-15"><EVENTS>
  <EVENT eventid="10" number="1" round="TIM"><SWIMSTYLE distance="100" relaycount="1" stroke="FREE" /></EVENT>
  <EVENT eventid="20" number="2" round="TIM"><SWIMSTYLE distance="100" relaycount="1" stroke="BREAST" /></EVENT>
</EVENTS></SESSION></SESSIONS>
<CLUBS><CLUB name="'.$club->name.'" code="'.$club->code.'" nation="AUT">
  <ATHLETES><ATHLETE athleteid="1" lastname="Platzhalter" firstname="Paula" gender="F" license="PH1">'
        .$athleteInner.'<RESULTS>'.$results.'</RESULTS></ATHLETE></ATHLETES>
</CLUB></CLUBS></MEET></MEETS></LENEX>';
}

/** Import in eine bestehende Veranstaltung (Exception → RuntimeException). */
function import_ph(string $xml, int $meetId): void
{
    $path = tempnam(sys_get_temp_dir(), 'lenex_ph').'.lef';
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
 * Sportklasse des Ergebnisses je Bewerbsnummer.
 *
 * @return array<int, string|null>
 */
function resultClasses_ph(Meet $meet): array
{
    return Result::where('meet_id', $meet->id)->get()
        ->mapWithKeys(fn (Result $r) => [SwimEvent::find($r->swim_event_id)->event_number => $r->sport_class])
        ->sortKeys()
        ->all();
}

// ── Import ────────────────────────────────────────────────────────────────────

it('ersetzt handicap="_0" am Ergebnis durch die Sportklasse aus der Datenbank', function () {
    [$meet, $club] = setup_ph(['S14', 'SB14', 'SM14']);

    import_ph(resultsXml_ph($club, '', 'handicap="_0"'), $meet->id);

    expect(resultClasses_ph($meet))->toBe([1 => 'S14', 2 => 'SB14']);
});

it('ersetzt "_0" in ATHLETE.HANDICAP durch die Sportklasse aus der Datenbank', function () {
    [$meet, $club] = setup_ph(['S14', 'SB14', 'SM14']);

    import_ph(resultsXml_ph($club, '<HANDICAP free="_0" breast="_0" medley="_0" />', ''), $meet->id);

    expect(resultClasses_ph($meet))->toBe([1 => 'S14', 2 => 'SB14']);
});

it('korrigiert eine "_0"-Klasse beim erneuten Import', function () {
    [$meet, $club] = setup_ph(['S14', 'SB14', 'SM14']);
    import_ph(resultsXml_ph($club, '', 'handicap="_0"'), $meet->id);
    Result::where('meet_id', $meet->id)->update(['sport_class' => '_0']);

    import_ph(resultsXml_ph($club, '', 'handicap="_0"'), $meet->id);

    expect(resultClasses_ph($meet))->toBe([1 => 'S14', 2 => 'SB14']);
});

it('lässt die Klasse bei "_0" leer, wenn auch die Datenbank keine kennt', function () {
    [$meet, $club] = setup_ph([]);

    import_ph(resultsXml_ph($club, '', 'handicap="_0"'), $meet->id);

    expect(resultClasses_ph($meet))->toBe([1 => null, 2 => null]);
});

it('übernimmt gültige Klassen aus der Datei weiterhin vor der Datenbank', function () {
    [$meet, $club] = setup_ph(['S14', 'SB14', 'SM14']);

    import_ph(resultsXml_ph($club, '', 'handicap="9"'), $meet->id);

    expect(resultClasses_ph($meet))->toBe([1 => 'S9', 2 => 'SB9']);
});

it('nimmt ohne Klassenangabe in der Ergebnisdatei weiterhin nicht die Datenbank-Klasse', function () {
    [$meet, $club] = setup_ph(['S14', 'SB14', 'SM14']);

    import_ph(resultsXml_ph($club, '', ''), $meet->id);

    expect(resultClasses_ph($meet))->toBe([1 => null, 2 => null]);
});

// ── Rekord löschen ────────────────────────────────────────────────────────────

function makeAdmin_ph(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

/** Ergebnis mit Landesrekord-Flag und dem zugehörigen Rekord. */
function flaggedRecord_ph(): SwimRecord
{
    [$meet, , $athlete] = setup_ph(['S14']);
    $event = SwimEvent::where('meet_id', $meet->id)->where('event_number', 1)->first();
    $result = Result::forceCreate([
        'meet_id' => $meet->id,
        'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id,
        'club_id' => $athlete->club_id,
        'swim_time' => 6269,
        'sport_class' => 'S14',
        'is_regional_record' => true,
    ]);

    return SwimRecord::create([
        'stroke_type_id' => $event->stroke_type_id,
        'athlete_id' => $athlete->id,
        'club_id' => $athlete->club_id,
        'result_id' => $result->id,
        'record_type' => 'AUT.WBSV',
        'sport_class' => '_0',
        'gender' => 'F',
        'course' => 'LCM',
        'distance' => 100,
        'relay_count' => 1,
        'swim_time' => 6269,
        'record_status' => 'APPROVED',
        'is_current' => true,
        'set_date' => '2025-06-15',
    ]);
}

it('setzt beim Löschen eines Rekords das Rekord-Flag am Ergebnis zurück', function () {
    $record = flaggedRecord_ph();

    $this->actingAs(makeAdmin_ph())->delete(route('records.destroy', $record))->assertRedirect();

    expect(SwimRecord::find($record->id))->toBeNull()
        ->and(Result::find($record->result_id)->is_regional_record)->toBeFalse();
});

it('stellt beim Löschen einen ausstehenden Vorgänger als ausstehend wieder her', function () {
    $record = flaggedRecord_ph();
    $predecessor = SwimRecord::create(array_merge(
        $record->only(['stroke_type_id', 'athlete_id', 'club_id', 'record_type', 'gender', 'course', 'distance',
            'relay_count']),
        ['sport_class' => '_0', 'swim_time' => 6400, 'record_status' => 'PENDING.HISTORY', 'is_current' => false,
            'superseded_by_id' => $record->id, 'set_date' => '2025-01-01'],
    ));
    $record->update(['supersedes_id' => $predecessor->id]);

    $this->actingAs(makeAdmin_ph())->delete(route('records.destroy', $record))->assertRedirect();

    $predecessor->refresh();
    expect($predecessor->is_current)->toBeTrue()
        ->and($predecessor->record_status)->toBe('PENDING')
        ->and($predecessor->superseded_by_id)->toBeNull();
});
