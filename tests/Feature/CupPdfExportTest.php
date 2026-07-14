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
use App\Services\OverallRankingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────────────────────

function makeClubUser_cup7(): User
{
    return User::factory()->create(['is_admin' => false]);
}

function makeNation_cup7(string $code = 'AUT'): Nation
{
    return Nation::firstOrCreate(
        ['code' => $code],
        ['name_de' => $code, 'name_en' => $code, 'is_active' => true]
    );
}

function makeClub_cup7(): Club
{
    return Club::create(['name' => 'Testclub', 'nation_id' => makeNation_cup7()->id]);
}

function makeAthlete_cup7(): Athlete
{
    return Athlete::create([
        'first_name' => 'Max', 'last_name' => 'Mustermann', 'gender' => 'M',
        'nation_id' => makeNation_cup7()->id, 'is_active' => true,
    ]);
}

function makeCup_cup7(): Cup
{
    $version = BaseTimeVersion::create(['label' => 'V1', 'valid_from' => '2021-01-01']);

    return Cup::create([
        'year' => 2026, 'name' => 'ÖBSV Cup 2026', 'base_time_version_id' => $version->id,
        'rounds_count' => 1, 'best_of_count' => 3, 'top_group_points_threshold' => 450,
    ]);
}

function makeMeet_cup7(Cup $cup): Meet
{
    return Meet::create([
        'name' => 'Testmeet', 'nation_id' => makeNation_cup7()->id,
        'course' => 'LCM', 'start_date' => '2026-06-01', 'cup_id' => $cup->id,
    ]);
}

function makeStrokeType_cup7(): StrokeType
{
    return StrokeType::firstOrCreate(
        ['code' => 'FREE'],
        ['lenex_code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle', 'category' => 'standard', 'is_active' => true]
    );
}

function makeDailyResult_cup7(Cup $cup, Meet $meet, Athlete $athlete, Club $club, SportClassGroup $group, int $points): CupDailyResult
{
    $event = SwimEvent::create([
        'meet_id' => $meet->id, 'stroke_type_id' => makeStrokeType_cup7()->id, 'distance' => 100, 'gender' => 'A',
    ]);
    $result = Result::create([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id, 'club_id' => $club->id,
        'sport_class' => 'S9', 'swim_time' => 60000, 'points' => 1,
    ]);

    return CupDailyResult::create([
        'cup_id' => $cup->id, 'meet_id' => $meet->id, 'athlete_id' => $athlete->id, 'club_id' => $club->id,
        'result_id' => $result->id, 'sport_class_group_id' => $group->id, 'gender' => $athlete->gender,
        'points' => $points, 'calculated_at' => now(),
    ]);
}

// ── Tageswertung PDF ──────────────────────────────────────────────────────────

describe('Tageswertung PDF', function () {
    it('liefert ein PDF für ein Meet ohne Wertung (Leerzustand)', function () {
        $cup = makeCup_cup7();
        $meet = makeMeet_cup7($cup);

        $response = $this->actingAs(makeClubUser_cup7())
            ->get(route('meets.cup-daily-ranking.pdf', $meet));

        $response->assertOk();
        expect($response->headers->get('Content-Type'))->toContain('application/pdf');
    })->group('cup-wertung-p7');

    it('liefert ein PDF mit berechneter Tageswertung', function () {
        $group = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);
        $cup = makeCup_cup7();
        $meet = makeMeet_cup7($cup);
        $club = makeClub_cup7();
        $athlete = makeAthlete_cup7();
        makeDailyResult_cup7($cup, $meet, $athlete, $club, $group, 420);

        $response = $this->actingAs(makeClubUser_cup7())
            ->get(route('meets.cup-daily-ranking.pdf', $meet));

        $response->assertOk();
        expect($response->headers->get('Content-Type'))->toContain('application/pdf');
    })->group('cup-wertung-p7');
});

// ── Gesamtwertung PDF ─────────────────────────────────────────────────────────

describe('Gesamtwertung PDF', function () {
    it('liefert ein PDF mit berechneter Gesamtwertung', function () {
        $group = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);
        $cup = makeCup_cup7();
        $meet = makeMeet_cup7($cup);
        $club = makeClub_cup7();
        $athlete = makeAthlete_cup7();
        makeDailyResult_cup7($cup, $meet, $athlete, $club, $group, 420);
        app(OverallRankingService::class)->calculateForCup($cup);

        $response = $this->actingAs(makeClubUser_cup7())
            ->get(route('cups.overall-ranking.pdf', $cup));

        $response->assertOk();
        expect($response->headers->get('Content-Type'))->toContain('application/pdf');
    })->group('cup-wertung-p7');
});
