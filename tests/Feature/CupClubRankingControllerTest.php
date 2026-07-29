<?php

use App\Models\Athlete;
use App\Models\BaseTimeVersion;
use App\Models\Club;
use App\Models\Cup;
use App\Models\CupDailyResult;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\SportClassGroup;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────────────────────

function user_ccr3(): User
{
    return User::factory()->create(['is_admin' => false]);
}

function nextSeq_ccr3(): int
{
    static $n = 0;

    return ++$n;
}

function makeNation_ccr3(string $code = 'AUT'): Nation
{
    return Nation::firstOrCreate(
        ['code' => $code],
        ['name_de' => $code, 'name_en' => $code, 'is_active' => true]
    );
}

function makeClub_ccr3(string $name, string $nationCode = 'AUT'): Club
{
    return Club::create(['name' => $name, 'nation_id' => makeNation_ccr3($nationCode)->id]);
}

function makeAthlete_ccr3(): Athlete
{
    $seq = nextSeq_ccr3();

    return Athlete::create([
        'first_name' => "Vorname$seq",
        'last_name' => "Nachname$seq",
        'gender' => 'M',
        'nation_id' => makeNation_ccr3()->id,
        'is_active' => true,
    ]);
}

function makeCup_ccr3(int $year = 2026): Cup
{
    $version = BaseTimeVersion::create(['label' => "V$year", 'valid_from' => "$year-01-01"]);

    return Cup::create([
        'year' => $year,
        'name' => "ÖBSV Cup $year",
        'base_time_version_id' => $version->id,
        'rounds_count' => 1,
        'best_of_count' => 3,
    ]);
}

function makeMeet_ccr3(Cup $cup): Meet
{
    return Meet::create([
        'name' => 'Meet '.nextSeq_ccr3(),
        'nation_id' => makeNation_ccr3()->id,
        'start_date' => "$cup->year-05-01",
        'cup_id' => $cup->id,
    ]);
}

function makeResult_ccr3(Meet $meet, Athlete $athlete, Club $club): Result
{
    $stroke = StrokeType::firstOrCreate(
        ['code' => 'FREE'],
        ['lenex_code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle']
    );
    $event = SwimEvent::create([
        'meet_id' => $meet->id,
        'stroke_type_id' => $stroke->id,
        'distance' => 100,
        'round' => 'TIM',
        'relay_count' => 1,
    ]);

    return Result::create([
        'meet_id' => $meet->id,
        'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id,
        'club_id' => $club->id,
        'swim_time' => 10000,
        'sport_class' => 'S9',
    ]);
}

/** Erzeugt reguläres Result + Tageswertungszeile (füttert Start- UND Leistungswertung). */
function makeDaily_ccr3(
    Cup $cup,
    Meet $meet,
    Athlete $athlete,
    Club $club,
    int $points,
    ?CarbonInterface $calculatedAt = null
): CupDailyResult {
    $group = SportClassGroup::firstOrCreate(['code' => 'PI'], ['name_de' => 'PI', 'sort_order' => 1]);

    return CupDailyResult::create([
        'cup_id' => $cup->id,
        'meet_id' => $meet->id,
        'athlete_id' => $athlete->id,
        'club_id' => $club->id,
        'result_id' => makeResult_ccr3($meet, $athlete, $club)->id,
        'sport_class_group_id' => $group->id,
        'gender' => 'M',
        'points' => $points,
        'calculated_at' => $calculatedAt ?? now()->addMinutes(5),
    ]);
}

// ── Tests ────────────────────────────────────────────────────────────────────

it('erfordert eine Anmeldung', function () {
    $cup = makeCup_ccr3();

    $this->get(route('cups.club-ranking.show', $cup))->assertRedirect();
})->group('cup-club-ranking-p3');

it('listet die Cup-Jahre in der Übersicht', function () {
    makeCup_ccr3();

    $this->actingAs(user_ccr3())
        ->get(route('cups.club-ranking.index'))
        ->assertOk()
        ->assertSee('ÖBSV Cup 2026');
})->group('cup-club-ranking-p3');

it('zeigt standardmäßig die Leistungswertung', function () {
    $cup = makeCup_ccr3();
    $meet = makeMeet_ccr3($cup);
    makeDaily_ccr3($cup, $meet, makeAthlete_ccr3(), makeClub_ccr3('Delfin Wien'), 500);

    $this->actingAs(user_ccr3())
        ->get(route('cups.club-ranking.show', $cup))
        ->assertOk()
        ->assertSee('Leistungswertung')
        ->assertSee('Delfin Wien');
})->group('cup-club-ranking-p3');

it('zeigt die Startwertung bei system=start', function () {
    $cup = makeCup_ccr3();
    $meet = makeMeet_ccr3($cup);
    makeDaily_ccr3($cup, $meet, makeAthlete_ccr3(), makeClub_ccr3('Delfin Wien'), 500);

    $this->actingAs(user_ccr3())
        ->get(route('cups.club-ranking.show', ['cup' => $cup, 'system' => 'start']))
        ->assertOk()
        ->assertSee('Startwertung')
        ->assertSee('Delfin Wien');
})->group('cup-club-ranking-p3');

it('blendet die Athleten-Details der Leistungswertung in die Seite ein', function () {
    $cup = makeCup_ccr3();
    $meet = makeMeet_ccr3($cup);
    $athlete = makeAthlete_ccr3();
    makeDaily_ccr3($cup, $meet, $athlete, makeClub_ccr3('Delfin Wien'), 500);

    $this->actingAs(user_ccr3())
        ->get(route('cups.club-ranking.show', ['cup' => $cup, 'system' => 'performance']))
        ->assertOk()
        ->assertSee($athlete->display_name);
})->group('cup-club-ranking-p3');

it('zeigt einen Staleness-Hinweis, wenn für ein Cup-Meet keine Tageswertung vorliegt', function () {
    $cup = makeCup_ccr3();
    $meet = makeMeet_ccr3($cup);
    // Ergebnis vorhanden, aber keine Tageswertung berechnet.
    makeResult_ccr3($meet, makeAthlete_ccr3(), makeClub_ccr3('Delfin Wien'));

    $this->actingAs(user_ccr3())
        ->get(route('cups.club-ranking.show', ['cup' => $cup, 'system' => 'performance']))
        ->assertOk()
        ->assertSee('Veraltet');
})->group('cup-club-ranking-p3');

it('zeigt keinen Staleness-Hinweis bei frischer Tageswertung', function () {
    $cup = makeCup_ccr3();
    $meet = makeMeet_ccr3($cup);
    makeDaily_ccr3($cup, $meet, makeAthlete_ccr3(), makeClub_ccr3('Delfin Wien'), 500);

    $this->actingAs(user_ccr3())
        ->get(route('cups.club-ranking.show', ['cup' => $cup, 'system' => 'performance']))
        ->assertOk()
        ->assertDontSee('Veraltet');
})->group('cup-club-ranking-p3');

it('schließt ausländische Vereine standardmäßig aus und bezieht sie mit foreign=1 ein', function () {
    $cup = makeCup_ccr3();
    $meet = makeMeet_ccr3($cup);
    makeDaily_ccr3($cup, $meet, makeAthlete_ccr3(), makeClub_ccr3('Austria SC'), 500);
    makeDaily_ccr3($cup, $meet, makeAthlete_ccr3(), makeClub_ccr3('Germania SC', 'GER'), 400);

    $user = user_ccr3();

    $this->actingAs($user)
        ->get(route('cups.club-ranking.show', ['cup' => $cup, 'system' => 'performance']))
        ->assertOk()
        ->assertSee('Austria SC')
        ->assertDontSee('Germania SC');

    $this->actingAs($user)
        ->get(route('cups.club-ranking.show', ['cup' => $cup, 'system' => 'performance', 'foreign' => 1]))
        ->assertOk()
        ->assertSee('Germania SC');
})->group('cup-club-ranking-p3');
