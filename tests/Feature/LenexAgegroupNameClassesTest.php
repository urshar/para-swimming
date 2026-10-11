<?php

use App\Models\Result;
use App\Models\ScoringGroup;
use App\Models\SwimEvent;
use App\Services\LenexParserService;
use App\Services\LenexResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('lenex-agegroup-names');

// Ältere Splash-Dateien (ÖSTM 2012): Klassen nur im AGEGROUP-Namen ("S9", "SB8", "S1-S10"), handicap-Attribut leer.
// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

function xml_lan(string $clubName, string $clubCode): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>
<LENEX version="3.0"><MEETS><MEET name="61. Staatsmeisterschaften" city="Dornbirn" nation="AUT" course="SCM">
<SESSIONS><SESSION number="1" date="2012-05-05"><EVENTS>
  <EVENT eventid="1058" number="1"><SWIMSTYLE distance="100" relaycount="1" stroke="FREE" />
    <AGEGROUPS>
      <AGEGROUP agegroupid="1" agemax="-1" agemin="-1" gender="F" name="S9"><RANKINGS><RANKING order="1" place="1" resultid="501" /></RANKINGS></AGEGROUP>
      <AGEGROUP agegroupid="2" agemax="-1" agemin="-1" gender="F" name="S1 - S4" />
      <AGEGROUP agegroupid="3" agemax="-1" agemin="-1" gender="M" name="SB8" />
    </AGEGROUPS></EVENT>
  <EVENT eventid="1059" number="2"><SWIMSTYLE distance="50" relaycount="1" stroke="FREE" />
    <AGEGROUPS><AGEGROUP agegroupid="4" agemax="-1" agemin="-1" name="Schnupperbewerb" /></AGEGROUPS></EVENT>
</EVENTS></SESSION></SESSIONS>
<CLUBS><CLUB name="'.$clubName.'" code="'.$clubCode.'" nation="AUT"><ATHLETES>
  <ATHLETE athleteid="1" lastname="Hummel" firstname="Anna" gender="F" birthdate="2000-01-01" license="LIC1">
    <HANDICAP free="9" /><RESULTS><RESULT eventid="1058" resultid="501" swimtime="00:01:10.00" /></RESULTS></ATHLETE>
</ATHLETES></CLUB></CLUBS></MEET></MEETS></LENEX>';
}

/** Schreibt die Datei in ein Temp-Verzeichnis und liefert den Pfad. */
function file_lan(string $xml): string
{
    $path = tempnam(sys_get_temp_dir(), 'lenex_lan').'.lef';
    file_put_contents($path, $xml);

    return $path;
}

it('liest die Klassen aus dem Gruppennamen, wenn das handicap-Attribut fehlt', /** @throws Exception */ function () {
    makeStrokeType_p5();
    $club = makeClub_p5();
    $athlete = makeAthlete_p5($club, 'F');
    $athlete->update(['last_name' => 'Hummel', 'first_name' => 'Anna', 'license' => 'LIC1']);
    $path = file_lan(xml_lan($club->name, $club->code));

    $unclassified = (new LenexParserService)->unclassifiedEvents($path);
    (new LenexParserService)->import($path, new LenexResolverService);

    $event = SwimEvent::where('event_number', 1)->sole();
    $groups = ScoringGroup::where('swim_event_id', $event->id)->orderBy('sort_order')->get();

    expect(array_column($unclassified, 'number'))->toBe([2])
        ->and($event->sport_classes)->toBe('1 2 3 4 8 9')
        ->and($groups->pluck('sport_classes')->all())->toBe(['9', '1,2,3,4', '8'])
        ->and(Result::sole()->place)->toBe(1);
});

it('ändert die Sportklasse eines bestehenden Athleten nicht, ergänzt aber eine fehlende Kategorie', /** @throws Exception */ function () {
    makeStrokeType_p5();
    $club = makeClub_p5();
    $vorhanden = makeAthlete_p5($club, 'F', ['S14']);
    $vorhanden->update(['last_name' => 'Hummel', 'first_name' => 'Anna', 'license' => 'LIC1']);
    $vorhanden->sportClasses()->update(['classification_status' => 'CONFIRMED']);

    (new LenexParserService)->import(file_lan(xml_lan($club->name, $club->code)), new LenexResolverService);

    $sport = $vorhanden->getSportClass('S');

    expect($sport->sport_class)->toBe('S14')
        ->and($sport->classification_status)->toBe('CONFIRMED');

    $vorhanden->sportClasses()->delete();
    $vorhanden->sportClasses()->create(['category' => 'SB', 'class_number' => '8', 'sport_class' => 'SB8']);

    (new LenexParserService)->import(file_lan(xml_lan($club->name, $club->code)), new LenexResolverService);

    expect($vorhanden->sportClasses()->orderBy('category')->pluck('sport_class')->all())->toBe(['S9', 'SB8']);
});
