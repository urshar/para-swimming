<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\RelayResult;
use App\Models\RelayResultMember;
use App\Models\RelayResultSplit;
use App\Models\Result;
use App\Models\ScoringGroup;
use App\Models\SwimEvent;
use App\Services\LenexExportService;
use App\Services\LenexParserService;
use App\Services\LenexResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('relay-results-p2');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

/** Athlet mit Lizenz (für den Rundlauf-Import auflösbar). */
function athlete_rex(Club $club, int $n): Athlete
{
    $athlete = makeAthlete_p5($club, 'M', ['S14']);
    $athlete->update(['last_name' => 'Staffel'.$n, 'license' => 'REX'.$n, 'birth_date' => '2000-01-01']);

    return $athlete;
}

/**
 * Herrenstaffel 4x50 m Freistil (Bewerb 8, Lauf 2, Bahn 4) des Vereins $club mit drei eigenen und einem
 * vereinsfremden Schwimmer, Zwischenzeit nach 50 m.
 *
 * @return array{0: Meet, 1: SwimEvent, 2: RelayResult, 3: Club, 4: Club}
 */
function setup_rex(): array
{
    makeStrokeType_p5();
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $otherClub = makeClub_p5();
    $event = makeEvent_p5($meet, ['event_number' => 8, 'distance' => 50, 'relay_count' => 4, 'gender' => 'A', 'sport_classes' => null]);
    $swimmers = [athlete_rex($club, 1), athlete_rex($club, 2), athlete_rex($club, 3), athlete_rex($otherClub, 4)];

    $relay = RelayResult::create([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'club_id' => $club->id, 'relay_number' => 1,
        'gender' => 'M', 'relay_class' => 'S14', 'swim_time' => 14949, 'place' => 1, 'points' => 323,
        'heat' => 2, 'lane' => 4,
    ]);
    foreach ($swimmers as $i => $athlete) {
        RelayResultMember::create([
            'relay_result_id' => $relay->id, 'position' => $i + 1, 'athlete_id' => $athlete->id,
            'first_name' => $athlete->first_name, 'last_name' => $athlete->last_name, 'gender' => 'M', 'sport_class' => 'S14',
        ]);
    }
    RelayResultSplit::create(['relay_result_id' => $relay->id, 'distance' => 50, 'split_time' => 3372]);

    return [$meet, $event, $relay, $club, $otherClub];
}

/** Ergebnisexport (DOMException → RuntimeException). */
function export_rex(Meet $meet): string
{
    try {
        return (new LenexExportService)->build($meet->fresh(), 'results');
    } catch (DOMException $e) {
        throw new RuntimeException('LENEX-Export fehlgeschlagen: '.$e->getMessage(), previous: $e);
    }
}

/** Import in eine bestehende Veranstaltung (Exception → RuntimeException). */
function import_rex(string $xml, int $meetId): void
{
    $path = tempnam(sys_get_temp_dir(), 'lenex_rex').'.lef';
    file_put_contents($path, $xml);

    try {
        (new LenexParserService)->import($path, new LenexResolverService, $meetId);
    } catch (Exception $e) {
        throw new RuntimeException('LENEX-Import fehlgeschlagen: '.$e->getMessage(), previous: $e);
    } finally {
        unlink($path);
    }
}

// ── Export ────────────────────────────────────────────────────────────────────

it('exportiert Staffelergebnisse mit Schwimmern, Zwischenzeiten und Laufverweis', function () {
    [$meet, $event, $relay, $club, $otherClub] = setup_rex();

    $xml = simplexml_load_string(export_rex($meet));
    $relayXml = $xml->xpath('//CLUB[@code="'.$club->code.'"]/RELAYS/RELAY')[0];
    $resultXml = $relayXml->RESULTS->RESULT;
    $heatXml = $xml->xpath('//EVENT[@number="8"]/HEATS/HEAT')[0];

    expect((string) $relayXml['gender'])->toBe('M')
        ->and((string) $relayXml['number'])->toBe('1')
        ->and((string) $relayXml['handicap'])->toBe('14')
        ->and((string) $resultXml['swimtime'])->toBe('00:02:29.49')
        ->and((string) $resultXml['points'])->toBe('323')
        ->and((string) $resultXml['heatid'])->toBe((string) $heatXml['heatid'])
        ->and((string) $heatXml['number'])->toBe('2')
        ->and((string) $resultXml->SPLITS->SPLIT['swimtime'])->toBe('00:00:33.72')
        ->and(count($resultXml->RELAYPOSITIONS->RELAYPOSITION))->toBe(4)
        // Vereinsfremder Schwimmer steht unter seinem eigenen Verein in ATHLETES.
        ->and(count($xml->xpath('//CLUB[@code="'.$otherClub->code.'"]/ATHLETES/ATHLETE[@license="REX4"]')))->toBe(1)
        ->and(count($xml->xpath('//CLUB[@code="'.$club->code.'"]/ATHLETES/ATHLETE')))->toBe(3);
});

it('schreibt Staffel-Ranglisten je Wertungsgruppe und ohne Gruppen je Wertung und Klasse', function () {
    [$meet, $event, $relay] = setup_rex();

    $fallback = simplexml_load_string(export_rex($meet))->xpath('//EVENT[@number="8"]/AGEGROUPS/AGEGROUP')[0];
    expect((string) $fallback['gender'])->toBe('M')
        ->and((string) $fallback['handicap'])->toBe('14')
        ->and((string) $fallback->RANKINGS->RANKING['resultid'])->toBe((string) $relay->id)
        ->and((string) $fallback->RANKINGS->RANKING['place'])->toBe('1');

    ScoringGroup::create(['swim_event_id' => $event->id, 'name' => 'ÖSTM: MI', 'gender' => 'M', 'sport_classes' => '14', 'sort_order' => 1]);
    $grouped = simplexml_load_string(export_rex($meet))->xpath('//EVENT[@number="8"]/AGEGROUPS/AGEGROUP')[0];
    expect((string) $grouped['name'])->toBe('ÖSTM: MI')
        ->and((string) $grouped->RANKINGS->RANKING['resultid'])->toBe((string) $relay->id);
});

it('liest exportierte Staffelergebnisse beim Import unverändert wieder ein', function () {
    [$meet, $event, $relay] = setup_rex();
    $memberIds = $relay->members->pluck('athlete_id')->all();
    $xml = export_rex($meet);
    RelayResult::query()->delete();

    import_rex($xml, $meet->id);

    $imported = RelayResult::with(['members', 'splits'])->sole();
    expect($imported->swim_event_id)->toBe($event->id)
        ->and($imported->gender)->toBe('M')
        ->and($imported->relay_class)->toBe('S14')
        ->and($imported->swim_time)->toBe(14949)
        ->and($imported->place)->toBe(1)
        ->and($imported->points)->toBe(323)
        ->and($imported->heat)->toBe(2)
        ->and($imported->lane)->toBe(4)
        ->and($imported->members->pluck('athlete_id')->all())->toBe($memberIds)
        ->and($imported->splits->pluck('split_time')->all())->toBe([3372])
        ->and(Result::count())->toBe(0);
});

it('verweist auch bei Einzelergebnissen und Meldungen per heatid auf HEAT', function () {
    [$meet, , , $club] = setup_rex();
    $event = makeEvent_p5($meet, ['event_number' => 1, 'distance' => 50]);
    $athlete = Athlete::where('license', 'REX1')->sole();
    Result::create([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id, 'club_id' => $club->id,
        'swim_time' => 3297, 'heat' => 3, 'lane' => 5,
    ]);
    $xml = export_rex($meet);
    Result::query()->delete();

    import_rex($xml, $meet->id);

    expect(Result::sole()->heat)->toBe(3);
});

it('erkennt eine Ergebnisdatei auch, wenn der erste Athlet keine Ergebnisse hat', function () {
    makeStrokeType_p5();
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    athlete_rex($club, 1);
    athlete_rex($club, 2);
    $xml = '<?xml version="1.0" encoding="UTF-8"?>
<LENEX version="3.0"><MEETS><MEET name="Testmeet" city="Wien" nation="AUT" course="LCM" startdate="2025-06-15">
<SESSIONS><SESSION number="1" date="2025-06-15"><EVENTS>
  <EVENT eventid="1" number="1" round="TIM"><SWIMSTYLE distance="50" relaycount="1" stroke="FREE" /></EVENT>
</EVENTS></SESSION></SESSIONS>
<CLUBS><CLUB name="'.$club->name.'" code="'.$club->code.'" nation="AUT"><ATHLETES>
  <ATHLETE athleteid="1" lastname="Staffel1" firstname="Max" gender="M" license="REX1" />
  <ATHLETE athleteid="2" lastname="Staffel2" firstname="Max" gender="M" license="REX2">
    <RESULTS><RESULT eventid="1" resultid="9" swimtime="00:00:30.00" /></RESULTS>
  </ATHLETE>
</ATHLETES></CLUB></CLUBS></MEET></MEETS></LENEX>';

    import_rex($xml, $meet->id);

    expect(Result::sole()->swim_time)->toBe(3000);
});
