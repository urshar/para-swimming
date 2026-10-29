<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Services\LenexParserService;
use App\Services\LenexResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('lenex-shared-event-number');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function athlete_lsen(Club $club): Athlete
{
    makeStrokeType_p5('BREAST');
    makeStrokeType_p5('FLY');
    $athlete = makeAthlete_p5($club, 'M', ['SB3', 'S4']);
    $athlete->update(['last_name' => 'Ernhofer', 'license' => 'LIC8']);

    return $athlete;
}

/** LENEX-Import, optional in eine bestehende Veranstaltung; liefert die Statistik (Exception → RuntimeException). */
function importLenex_lsen(string $xml, ?int $meetId): array
{
    $path = tempnam(sys_get_temp_dir(), 'lenex_lsen').'.lef';
    file_put_contents($path, $xml);

    try {
        return (new LenexParserService)->import($path, new LenexResolverService, $meetId)['stats'];
    } catch (Exception $e) {
        throw new RuntimeException('LENEX-Import fehlgeschlagen: '.$e->getMessage(), previous: $e);
    } finally {
        unlink($path);
    }
}

/**
 * Ergebnisdatei: Vorlauf und Finale 50 m Brust als eigene EVENTs mit gleicher Nummer 8 (wie in den Paralympics-
 * Ergebnissen), dazu 100 m Schmetterling mit der Schreibweise stroke="BUTTERFLY".
 */
function resultXml_lsen(Club $club): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>
<LENEX version="3.0"><MEETS><MEET name="Rundentest" city="Graz" nation="AUT" course="LCM">
<SESSIONS><SESSION number="1" date="2021-08-25"><EVENTS>
  <EVENT eventid="2" number="8" order="1" gender="M" round="PRE"><SWIMSTYLE distance="50" relaycount="1" stroke="BREAST" />
    <AGEGROUPS><AGEGROUP agegroupid="2" agemin="19" agemax="-1" gender="M" name="Open" handicap="3" /></AGEGROUPS>
  </EVENT>
  <EVENT eventid="14" number="8" order="2" gender="M" round="FIN" preveventid="2"><SWIMSTYLE distance="50" relaycount="1" stroke="BREAST" />
    <AGEGROUPS><AGEGROUP agegroupid="2" agemin="19" agemax="-1" gender="M" name="Open" handicap="3" /></AGEGROUPS>
  </EVENT>
  <EVENT eventid="11" number="135" order="3" gender="M" round="PRE"><SWIMSTYLE distance="100" relaycount="1" stroke="BUTTERFLY" />
    <AGEGROUPS><AGEGROUP agegroupid="2" agemin="19" agemax="-1" gender="M" name="Open" handicap="4" /></AGEGROUPS>
  </EVENT>
</EVENTS></SESSION></SESSIONS>
<CLUBS><CLUB name="'.$club->name.'" code="'.$club->code.'" nation="AUT"><ATHLETES>
  <ATHLETE athleteid="2" lastname="Ernhofer" firstname="Andreas" gender="M" birthdate="1997-03-09" license="LIC8">
    <HANDICAP free="4" breast="3" medley="4" />
    <RESULTS>
      <RESULT eventid="2" agegroupid="2" place="8" swimtime="00:00:55.00" />
      <RESULT eventid="14" agegroupid="2" place="7" swimtime="00:00:54.18" />
      <RESULT eventid="11" agegroupid="2" place="15" swimtime="00:01:09.52" />
    </RESULTS>
  </ATHLETE>
</ATHLETES></CLUB></CLUBS></MEET></MEETS></LENEX>';
}

// ── Vorlauf und Finale mit gleicher Bewerbsnummer ─────────────────────────────

it('legt Vorlauf und Finale mit gleicher Bewerbsnummer als eigene Bewerbe an', function () {
    $club = makeClub_p5();
    athlete_lsen($club);

    $stats = importLenex_lsen(resultXml_lsen($club), null);

    $prelim = SwimEvent::where('event_number', 8)->where('round', 'PRE')->first();
    $final = SwimEvent::where('event_number', 8)->where('round', 'FIN')->first();
    expect(SwimEvent::where('event_number', 8)->count())->toBe(2)
        ->and($stats['events'])->toBe(3)
        ->and($prelim?->lenex_event_id)->toBe('2')
        ->and($final?->lenex_event_id)->toBe('14');
});

it('ordnet Vorlauf- und Finalzeit dem jeweiligen Bewerb zu, statt eine zu überschreiben', function () {
    $club = makeClub_p5();
    athlete_lsen($club);

    $stats = importLenex_lsen(resultXml_lsen($club), null);

    $prelim = SwimEvent::where('event_number', 8)->where('round', 'PRE')->firstOrFail();
    $final = SwimEvent::where('event_number', 8)->where('round', 'FIN')->firstOrFail();
    expect(Result::count())->toBe(3)
        ->and($stats['results'])->toBe(3)
        ->and(Result::where('swim_event_id', $prelim->id)->value('swim_time'))->toBe(5500)
        ->and(Result::where('swim_event_id', $final->id)->value('swim_time'))->toBe(5418)
        ->and(Result::where('swim_event_id', $final->id)->value('sport_class'))->toBe('SB3');
});

it('verdoppelt beim erneuten Import weder Bewerbe noch Ergebnisse', function () {
    $club = makeClub_p5();
    athlete_lsen($club);
    importLenex_lsen(resultXml_lsen($club), null);
    $meetId = SwimEvent::firstOrFail()->meet_id;

    $stats = importLenex_lsen(resultXml_lsen($club), $meetId);

    expect(SwimEvent::count())->toBe(3)
        ->and(Result::count())->toBe(3)
        ->and($stats['results_new'])->toBe(0);
});

// ── Schwimmlage "BUTTERFLY" ───────────────────────────────────────────────────

it('liest stroke="BUTTERFLY" als Schmetterling (LENEX-Code FLY)', function () {
    $club = makeClub_p5();
    athlete_lsen($club);

    importLenex_lsen(resultXml_lsen($club), null);

    $fly = StrokeType::where('lenex_code', 'FLY')->firstOrFail();
    expect(SwimEvent::where('event_number', 135)->value('stroke_type_id'))->toBe($fly->id)
        ->and(StrokeType::findByLenexCode('BUTTERFLY')?->id)->toBe($fly->id);
});
