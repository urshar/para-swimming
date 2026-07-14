<?php

use App\Models\Athlete;
use App\Models\BaseTimeVersion;
use App\Models\Club;
use App\Models\Cup;
use App\Models\CupDailyResult;
use App\Models\CupOverallResult;
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

function makeAdmin_cup6(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function makeClubUser_cup6(): User
{
    return User::factory()->create(['is_admin' => false]);
}

function makeNation_cup6(string $code = 'AUT'): Nation
{
    return Nation::firstOrCreate(
        ['code' => $code],
        ['name_de' => $code, 'name_en' => $code, 'is_active' => true]
    );
}

function makeClub_cup6(): Club
{
    return Club::create(['name' => 'Testclub', 'nation_id' => makeNation_cup6()->id]);
}

function makeAthlete_cup6(array $attrs = []): Athlete
{
    return Athlete::create(array_merge([
        'first_name' => 'Max', 'last_name' => 'Mustermann', 'gender' => 'M',
        'nation_id' => makeNation_cup6()->id, 'is_active' => true,
    ], $attrs));
}

function makeCup_cup6(): Cup
{
    $version = BaseTimeVersion::create(['label' => 'V1', 'valid_from' => '2021-01-01']);

    return Cup::create([
        'year' => 2026, 'name' => 'ÖBSV Cup 2026', 'base_time_version_id' => $version->id,
        'rounds_count' => 1, 'best_of_count' => 3, 'top_group_points_threshold' => 450,
    ]);
}

function makeMeet_cup6(Cup $cup): Meet
{
    return Meet::create([
        'name' => 'Testmeet', 'nation_id' => makeNation_cup6()->id,
        'course' => 'LCM', 'start_date' => '2026-06-01', 'cup_id' => $cup->id,
    ]);
}

function makeStrokeType_cup6(): StrokeType
{
    return StrokeType::firstOrCreate(
        ['code' => 'FREE'],
        ['lenex_code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle', 'category' => 'standard', 'is_active' => true]
    );
}

function makeDailyResult_cup6(Cup $cup, Meet $meet, Athlete $athlete, Club $club, SportClassGroup $group, int $points): CupDailyResult
{
    $event = SwimEvent::create([
        'meet_id' => $meet->id, 'stroke_type_id' => makeStrokeType_cup6()->id, 'distance' => 100, 'gender' => 'A',
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

// ── show ──────────────────────────────────────────────────────────────────────

describe('show', function () {
    it('zeigt eine leere Ansicht, wenn noch keine Gesamtwertung berechnet wurde', function () {
        $cup = makeCup_cup6();

        $this->actingAs(makeClubUser_cup6())
            ->get(route('cups.overall-ranking.show', $cup))
            ->assertOk()
            ->assertSee('noch keine Gesamtwertung berechnet');
    })->group('cup-wertung-p6');

    it('zeigt die berechnete Gesamtwertung inkl. Rang', function () {
        $group = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);
        $cup = makeCup_cup6();
        $club = makeClub_cup6();
        $athlete = makeAthlete_cup6();
        makeDailyResult_cup6($cup, makeMeet_cup6($cup), $athlete, $club, $group, 420);

        app(OverallRankingService::class)->calculateForCup($cup);

        $this->actingAs(makeClubUser_cup6())
            ->get(route('cups.overall-ranking.show', $cup))
            ->assertOk()
            ->assertSee('Mustermann, Max')
            ->assertSee('420');
    })->group('cup-wertung-p6');
});

// ── calculate ─────────────────────────────────────────────────────────────────

describe('calculate', function () {
    it('Club-User bekommt 403', function () {
        $cup = makeCup_cup6();

        $this->actingAs(makeClubUser_cup6())
            ->post(route('cups.overall-ranking.calculate', $cup))
            ->assertForbidden();
    })->group('cup-wertung-p6');

    it('Admin kann die Gesamtwertung berechnen und wird zur Anzeige weitergeleitet', function () {
        $group = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);
        $cup = makeCup_cup6();
        $club = makeClub_cup6();
        $athlete = makeAthlete_cup6();
        makeDailyResult_cup6($cup, makeMeet_cup6($cup), $athlete, $club, $group, 420);

        $this->actingAs(makeAdmin_cup6())
            ->post(route('cups.overall-ranking.calculate', $cup))
            ->assertRedirect(route('cups.overall-ranking.show', $cup));

        expect(CupOverallResult::where('cup_id', $cup->id)->count())->toBe(1);
    })->group('cup-wertung-p6');
});
