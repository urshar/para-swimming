<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\ForeignStart;
use App\Models\Nation;
use App\Models\Result;
use App\Models\SwimEvent;
use App\Services\LenexParserService;
use App\Services\LenexResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('lenex-result-handicap-class');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function athlete_lrhc(Club $club): Athlete
{
    foreach (['FREE', 'BREAST', 'MEDLEY'] as $stroke) {
        makeStrokeType_p5($stroke);
    }
    $athlete = makeAthlete_p5($club, 'M', ['S4', 'SB3', 'SM4']);
    $athlete->update(['last_name' => 'Ernhofer', 'license' => 'LIC9']);

    return $athlete;
}

/** LENEX-Import in eine neue Veranstaltung (Exception → RuntimeException). */
function importLenex_lrhc(string $xml): void
{
    $path = tempnam(sys_get_temp_dir(), 'lenex_lrhc').'.lef';
    file_put_contents($path, $xml);

    try {
        (new LenexParserService)->import($path, new LenexResolverService);
    } catch (Exception $e) {
        throw new RuntimeException('LENEX-Import fehlgeschlagen: '.$e->getMessage(), previous: $e);
    } finally {
        unlink($path);
    }
}

/**
 * Ergebnisdatei ohne HANDICAP-Element am Athleten (wie automatisch konvertierte Ergebnisdateien): Die Klasse steht
 * nur als Nummer in RESULT handicap; eventid 4 nennt sie bereits mit Präfix. Dazu ein Gast (ESP) mit demselben Muster.
 */
function resultXml_lrhc(Club $club): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>
<LENEX version="3.0"><MEETS><MEET name="Klassentest" city="Graz" nation="AUT" course="LCM">
<SESSIONS><SESSION number="1" date="2022-02-26"><EVENTS>
  <EVENT eventid="1" number="1" gender="M" round="TIM"><SWIMSTYLE distance="200" relaycount="1" stroke="FREE" />
    <AGEGROUPS><AGEGROUP agegroupid="1" agemin="-1" agemax="-1" gender="M" /></AGEGROUPS></EVENT>
  <EVENT eventid="2" number="2" gender="M" round="TIM"><SWIMSTYLE distance="100" relaycount="1" stroke="BREAST" />
    <AGEGROUPS><AGEGROUP agegroupid="2" agemin="-1" agemax="-1" gender="M" /></AGEGROUPS></EVENT>
  <EVENT eventid="3" number="3" gender="M" round="TIM"><SWIMSTYLE distance="150" relaycount="1" stroke="MEDLEY" />
    <AGEGROUPS><AGEGROUP agegroupid="3" agemin="-1" agemax="-1" gender="M" /></AGEGROUPS></EVENT>
  <EVENT eventid="4" number="4" gender="M" round="TIM"><SWIMSTYLE distance="50" relaycount="1" stroke="BREAST" />
    <AGEGROUPS><AGEGROUP agegroupid="4" agemin="-1" agemax="-1" gender="M" /></AGEGROUPS></EVENT>
</EVENTS></SESSION></SESSIONS>
<CLUBS>
  <CLUB name="'.$club->name.'" code="'.$club->code.'" nation="AUT"><ATHLETES>
    <ATHLETE athleteid="2" lastname="Ernhofer" firstname="Andreas" gender="M" birthdate="1997-01-01" license="LIC9">
      <RESULTS>
        <RESULT resultid="1" eventid="1" handicap="4" swimtime="00:03:42.32" />
        <RESULT resultid="2" eventid="2" handicap="3" swimtime="00:02:06.29" />
        <RESULT resultid="3" eventid="3" handicap="4" swimtime="00:02:56.01" />
        <RESULT resultid="4" eventid="4" handicap="SB3" swimtime="00:00:55.47" />
      </RESULTS>
    </ATHLETE>
  </ATHLETES></CLUB>
  <CLUB name="Spanien" code="ESP" nation="ESP"><ATHLETES>
    <ATHLETE athleteid="9" lastname="Gast" firstname="Pablo" gender="M" birthdate="1995-01-01">
      <RESULTS><RESULT resultid="9" eventid="2" handicap="3" swimtime="00:02:01.00" /></RESULTS>
    </ATHLETE>
  </ATHLETES></CLUB>
</CLUBS></MEET></MEETS></LENEX>';
}

/** Sport-Klasse des importierten Ergebnisses im Bewerb mit dieser Nummer. */
function resultClass_lrhc(int $eventNumber): ?string
{
    return Result::where('swim_event_id', SwimEvent::where('event_number', $eventNumber)->value('id'))->value('sport_class');
}

// ── Klasse aus RESULT handicap ────────────────────────────────────────────────

it('ergänzt bei einer reinen Klassennummer in RESULT handicap das Präfix zur Lage', function () {
    $club = makeClub_p5();
    athlete_lrhc($club);

    importLenex_lrhc(resultXml_lrhc($club));

    expect(resultClass_lrhc(1))->toBe('S4')
        ->and(resultClass_lrhc(2))->toBe('SB3')
        ->and(resultClass_lrhc(3))->toBe('SM4');
});

it('übernimmt eine Klasse mit Präfix aus RESULT handicap unverändert', function () {
    $club = makeClub_p5();
    athlete_lrhc($club);

    importLenex_lrhc(resultXml_lrhc($club));

    expect(resultClass_lrhc(4))->toBe('SB3');
});

it('ergänzt das Präfix auch bei Gast-Starts', function () {
    $club = makeClub_p5();
    athlete_lrhc($club);
    Nation::firstOrCreate(['code' => 'ESP'], ['name_de' => 'Spanien', 'name_en' => 'Spain']);

    importLenex_lrhc(resultXml_lrhc($club));

    expect(ForeignStart::where('last_name', 'Gast')->value('sport_class'))->toBe('SB3');
});
