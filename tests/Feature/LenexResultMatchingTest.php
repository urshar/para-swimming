<?php

use App\Http\Controllers\LenexImportController;
use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Result;
use App\Services\LenexParserService;
use App\Services\LenexResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('lenex-result-matching');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function athlete_lrm(Club $club): Athlete
{
    makeStrokeType_p5();
    $athlete = makeAthlete_p5($club, 'F');
    $athlete->update(['last_name' => 'Hummel', 'license' => 'LIC1']);

    return $athlete;
}

/** LENEX-Import, optional in eine bestehende Veranstaltung; liefert die Statistik (Exception → RuntimeException). */
function importLenex_lrm(string $xml, ?int $meetId): array
{
    $path = tempnam(sys_get_temp_dir(), 'lenex_lrm').'.lef';
    file_put_contents($path, $xml);

    try {
        return (new LenexParserService)->import($path, new LenexResolverService, $meetId)['stats'];
    } catch (Exception $e) {
        throw new RuntimeException('LENEX-Import fehlgeschlagen: '.$e->getMessage(), previous: $e);
    } finally {
        unlink($path);
    }
}

/** Import-Rückmeldung zu den Ergebnissen (private Controller-Methode; ReflectionException → RuntimeException). */
function matchSummary_lrm(array $stats): string
{
    try {
        return (new ReflectionMethod(LenexImportController::class, 'resultMatchSummary'))->invoke(null, $stats);
    } catch (ReflectionException $e) {
        throw new RuntimeException('Rückmeldung nicht ermittelbar: '.$e->getMessage(), previous: $e);
    }
}

/** Ergebnisdatei mit einem Einzelergebnis (50 m Freistil, Lauf 2 über heatid 2185, Bahn 4, Platz 1 aus dem RANKING). */
function resultXml_lrm(Club $club, string $resultAttributes): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>
<LENEX version="3.0"><MEETS><MEET name="Abgleichtest" city="Kapfenberg" nation="AUT" course="SCM">
<SESSIONS><SESSION number="1" date="2025-05-10"><EVENTS>
  <EVENT eventid="1059" number="1" round="TIM"><SWIMSTYLE distance="50" relaycount="1" stroke="FREE" />
    <AGEGROUPS><AGEGROUP agegroupid="1060" agemax="-1" agemin="-1" gender="F" name="ÖSTM: S01 - S10" handicap="1,2,3,4,5,6,7,8,9,10">
      <RANKINGS><RANKING order="1" place="1" resultid="501" /></RANKINGS>
    </AGEGROUP></AGEGROUPS>
    <HEATS><HEAT heatid="2185" number="2" order="2" /></HEATS>
  </EVENT>
</EVENTS></SESSION></SESSIONS>
<CLUBS><CLUB name="'.$club->name.'" code="'.$club->code.'" nation="AUT"><ATHLETES>
  <ATHLETE athleteid="1" lastname="Hummel" firstname="Anna" gender="F" birthdate="2000-01-01" license="LIC1">
    <HANDICAP free="9" breast="8" medley="9" />
    <RESULTS><RESULT eventid="1059" resultid="501" swimtime="00:00:32.97" heatid="2185" lane="4" '.$resultAttributes.' /></RESULTS>
  </ATHLETE>
</ATHLETES></CLUB></CLUBS></MEET></MEETS></LENEX>';
}

/** Vorhandenes Ergebnis wie aus einer anderen Quelle: ohne Lauf, Bahn und LENEX-resultid, mit abweichender Zeit. */
function legacyResult_lrm(Athlete $athlete, ?int $points): Result
{
    $meet = Meet::firstOrFail();
    $result = Result::firstOrFail();
    $result->update([
        'heat' => null, 'lane' => null, 'lenex_result_id' => null,
        'swim_time' => 3372, 'place' => null, 'points' => $points, 'sport_class' => 'S9',
    ]);

    expect($result->meet_id)->toBe($meet->id)
        ->and($result->athlete_id)->toBe($athlete->id);

    return $result;
}

// ── Abgleich ──────────────────────────────────────────────────────────────────

it('ergänzt ein vorhandenes Ergebnis ohne Lauf und Bahn, statt es zu verdoppeln', function () {
    $club = makeClub_p5();
    $athlete = athlete_lrm($club);
    $xml = resultXml_lrm($club, 'points="612"');
    importLenex_lrm($xml, null);
    $legacy = legacyResult_lrm($athlete, null);

    $stats = importLenex_lrm($xml, $legacy->meet_id);

    $result = $legacy->fresh();
    expect(Result::count())->toBe(1)
        ->and($stats['results'])->toBe(1)
        ->and($stats['results_new'])->toBe(0)
        ->and($result->heat)->toBe(2)
        ->and($result->lane)->toBe(4)
        ->and($result->lenex_result_id)->toBe('501')
        ->and($result->swim_time)->toBe(3297)
        ->and($result->place)->toBe(1)
        ->and($result->points)->toBe(612);
});

it('ändert beim erneuten Import derselben Datei nichts mehr', function () {
    $club = makeClub_p5();
    athlete_lrm($club);
    $xml = resultXml_lrm($club, 'points="612"');

    $first = importLenex_lrm($xml, null);
    $second = importLenex_lrm($xml, Meet::value('id'));

    expect(Result::count())->toBe(1)
        ->and($first['results_new'])->toBe(1)
        ->and($second['results_new'])->toBe(0);
});

it('lässt vorhandene Punkte stehen, wenn die Datei keine enthält, und übernimmt die Sportklasse aus der Datei', function () {
    $club = makeClub_p5();
    $athlete = athlete_lrm($club);
    $xml = resultXml_lrm($club, '');
    importLenex_lrm($xml, null);
    $legacy = legacyResult_lrm($athlete, 598);
    $legacy->update(['sport_class' => 'S10']);

    importLenex_lrm($xml, $legacy->meet_id);

    $result = $legacy->fresh();
    expect($result->points)->toBe(598)
        ->and($result->swim_time)->toBe(3297)
        ->and($result->sport_class)->toBe('S9');
});

it('ordnet bei mehreren vorhandenen Ergebnissen ohne Lauf und Bahn nicht zu', function () {
    $club = makeClub_p5();
    $athlete = athlete_lrm($club);
    $xml = resultXml_lrm($club, '');
    importLenex_lrm($xml, null);
    $legacy = legacyResult_lrm($athlete, null);
    Result::create($legacy->only(['meet_id', 'swim_event_id', 'athlete_id', 'club_id', 'sport_class']) + ['swim_time' => 3400]);

    $stats = importLenex_lrm($xml, $legacy->meet_id);

    expect(Result::count())->toBe(3)
        ->and($stats['results_new'])->toBe(1)
        ->and($stats['results_ambiguous'])->toBe(1)
        ->and($legacy->fresh()->swim_time)->toBe(3372);
});

it('hält ein Ergebnis mit anderem Lauf und anderer Bahn getrennt', function () {
    $club = makeClub_p5();
    athlete_lrm($club);
    $xml = resultXml_lrm($club, '');
    importLenex_lrm($xml, null);
    $other = Result::firstOrFail();
    $other->update(['heat' => 1, 'lane' => 5, 'lenex_result_id' => null, 'swim_time' => 3500]);

    $stats = importLenex_lrm($xml, $other->meet_id);

    expect(Result::count())->toBe(2)
        ->and($stats['results_new'])->toBe(1)
        ->and($other->fresh()->swim_time)->toBe(3500);
});

it('meldet nach dem Import, wie viele Ergebnisse neu und wie viele abgeglichen sind', function () {
    $club = makeClub_p5();
    $athlete = athlete_lrm($club);
    $xml = resultXml_lrm($club, '');
    importLenex_lrm($xml, null);
    $legacy = legacyResult_lrm($athlete, null);

    $stats = importLenex_lrm($xml, $legacy->meet_id);
    expect(matchSummary_lrm($stats))->toBe(' (davon 0 neu, 1 mit vorhandenen abgeglichen)');
});

it('korrigiert einen früher als heatid gespeicherten Lauf über die LENEX-resultid', function () {
    $club = makeClub_p5();
    athlete_lrm($club);
    $xml = resultXml_lrm($club, '');
    importLenex_lrm($xml, null);
    $result = Result::firstOrFail();
    $result->update(['heat' => 2185]);

    $stats = importLenex_lrm($xml, $result->meet_id);

    expect(Result::count())->toBe(1)
        ->and($stats['results_new'])->toBe(0)
        ->and($result->fresh()->heat)->toBe(2);
});
