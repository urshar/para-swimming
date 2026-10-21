<?php

use App\Models\Club;
use App\Models\Meet;
use App\Models\RelayResult;
use App\Models\RelayResultMember;
use App\Models\SwimEvent;
use App\Models\SwimRecord;
use App\Services\LenexParserService;
use App\Services\LenexResolverService;
use App\Services\RecordCheckerService;
use App\Services\RelayClassValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('relay-class-resolution');

// Staffelklasse: Eine Klassenangabe der Datei gilt nur, wenn sie eine gültige Staffelklasse ist (Splash-Fehleingabe
// handicap="1" ergab "S1"); sonst kommt sie aus den Klassen der Mitglieder (S14 darf S14 und S21 enthalten).
// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

/**
 * Herren-Staffel 4×50 m Freistil mit vier Schwimmern der Klassen $classes; die Wertungsgruppe trägt $groupHandicap.
 *
 * @param  list<int>  $classes
 */
function xml_rcr(Club $club, string $groupHandicap, array $classes): string
{
    $athletes = '';
    $positions = '';
    foreach ($classes as $i => $class) {
        $id = $i + 1;
        $athletes .= '<ATHLETE athleteid="'.$id.'" firstname="Max" lastname="Staffel'.$id.'" gender="M" birthdate="2000-01-0'.$id.'">'
            .'<HANDICAP free="'.$class.'" breast="'.$class.'" medley="'.$class.'" /></ATHLETE>';
        $positions .= '<RELAYPOSITION athleteid="'.$id.'" number="'.$id.'" />';
    }

    return '<?xml version="1.0" encoding="UTF-8"?>
<LENEX version="3.0"><MEETS><MEET name="Staffelklassen" city="Dornbirn" nation="AUT" course="SCM" startdate="2012-05-05">
<SESSIONS><SESSION number="1" date="2012-05-05"><EVENTS>
  <EVENT eventid="1077" number="11" round="TIM"><SWIMSTYLE distance="50" relaycount="4" stroke="FREE" />
    <AGEGROUPS><AGEGROUP agegroupid="1078" gender="M"'.($groupHandicap !== '' ? ' handicap="'.$groupHandicap.'"' : '').'>
      <RANKINGS><RANKING order="1" place="1" resultid="501" /></RANKINGS></AGEGROUP></AGEGROUPS></EVENT>
</EVENTS></SESSION></SESSIONS>
<CLUBS><CLUB name="'.$club->name.'" code="'.$club->code.'" nation="AUT"><ATHLETES>'.$athletes.'</ATHLETES>
  <RELAYS><RELAY number="1" gender="M"><RESULTS><RESULT eventid="1077" resultid="501" swimtime="00:06:54.75">
    <RELAYPOSITIONS>'.$positions.'</RELAYPOSITIONS></RESULT></RESULTS></RELAY></RELAYS>
</CLUB></CLUBS></MEET></MEETS></LENEX>';
}

it('übernimmt eine gültige Staffelklasse und leitet sonst aus den Mitgliedern ab', /** @throws Exception */ function (string $groupHandicap, array $classes, ?string $expected) {
    makeNation_p5();
    makeStrokeType_p5();
    $club = makeClub_p5();
    $path = tempnam(sys_get_temp_dir(), 'lenex_rcr').'.lef';
    file_put_contents($path, xml_rcr($club, $groupHandicap, $classes));

    (new LenexParserService)->import($path, new LenexResolverService);

    expect(RelayResult::sole()->relay_class)->toBe($expected);
})->with([
    'Fehleingabe handicap="1", S14 mit S21' => ['1', [14, 14, 14, 21], 'S14'],
    'ohne Klassenangabe' => ['', [14, 21, 14, 14], 'S14'],
    'gültige Klasse aus der Datei' => ['14', [14, 14, 14, 14], 'S14'],
    'ungültige Mischung' => ['1', [6, 9, 14, 14], null],
]);

/** Staffelergebnis mit Mitgliedern der Klassen $classes (Athleten beim Verein $memberClub). */
function relay_rcr(Club $relayClub, Club $memberClub, array $classes, ?string $relayClass): RelayResult
{
    $meet = Meet::query()->first() ?? makeMeet_p5();
    $event = SwimEvent::query()->first() ?? makeEvent_p5($meet, ['relay_count' => 4, 'distance' => 50, 'gender' => 'M']);

    $relay = RelayResult::query()->create(['meet_id' => $meet->id, 'swim_event_id' => $event->id, 'club_id' => $relayClub->id,
        'gender' => 'M', 'swim_time' => 41475, 'relay_class' => $relayClass]);
    foreach ($classes as $i => $class) {
        $athlete = makeAthlete_p5($memberClub, 'M', [$class]);
        RelayResultMember::query()->create(['relay_result_id' => $relay->id, 'position' => $i + 1, 'athlete_id' => $athlete->id,
            'gender' => 'M', 'sport_class' => $class]);
    }

    return $relay;
}

it('setzt im Rekordcheck die Staffelklasse auch bei einer Landesauswahl, die keinen Rekord aufstellt', /** @throws Throwable */ function () {
    $selection = makeClub_p5();
    $selection->update(['type' => Club::TYPE_VERBAND]);
    $relay = relay_rcr($selection, makeClub_p5(), ['S14', 'S14', 'S14', 'S21'], 'S1');

    $check = app(RecordCheckerService::class)->checkMeet(Meet::sole());

    expect($relay->fresh()->relay_class)->toBe('S14')
        ->and($check['new_records'])->toBe([])
        ->and(SwimRecord::query()->count())->toBe(0);
});

it('korrigiert fehlende und ungültige Staffelklassen über das Kommando erst nach dem Probelauf', function () {
    $club = makeClub_p5();
    $wrong = relay_rcr($club, $club, ['S14', 'S14', 'S14', 'S21'], 'S1');
    $missing = relay_rcr($club, $club, ['S6', 'S7', 'S3', 'S4'], null);
    $mixed = relay_rcr($club, $club, ['S6', 'S9', 'S14', 'S14'], null);
    $valid = relay_rcr($club, $club, ['S14', 'S14', 'S14', 'S14'], 'S14');

    $this->artisan('relays:resolve-classes', ['--dry-run' => true])
        ->expectsOutputToContain('Probelauf — 2 Staffelklasse(n)')
        ->assertSuccessful();

    expect($wrong->fresh()->relay_class)->toBe('S1');

    $this->artisan('relays:resolve-classes')
        ->expectsConfirmation('2 Staffelklasse(n) ändern?', 'yes')
        ->assertSuccessful();

    expect($wrong->fresh()->relay_class)->toBe('S14')
        ->and($missing->fresh()->relay_class)->toBe('S20')
        ->and($mixed->fresh()->relay_class)->toBeNull()
        ->and($valid->fresh()->relay_class)->toBe('S14');
});

it('erkennt nur gültige Staffelklassen aus der Datei', function () {
    expect(RelayClassValidator::fromHandicap('14'))->toBe('S14')
        ->and(RelayClassValidator::fromHandicap('49'))->toBe('S49')
        ->and(RelayClassValidator::fromHandicap('1'))->toBeNull()
        ->and(RelayClassValidator::fromHandicap('14,34'))->toBeNull()
        ->and(RelayClassValidator::fromHandicap(''))->toBeNull();
});

it('braucht für die Ableitung aus den Mitgliedern jede Position', function () {
    $event = new SwimEvent(['relay_count' => 4]);
    $members = collect([['S14'], ['S14'], ['S21']])->map(fn (array $c) => new RelayResultMember(['sport_class' => $c[0]]));

    expect((new RelayClassValidator)->resolveFromMembers($members, $event))->toBeNull();
});
