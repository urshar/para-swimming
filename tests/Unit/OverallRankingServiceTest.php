<?php

/** @noinspection PhpUnhandledExceptionInspection Pest-Test-Closures fangen Exceptions selbst ab. */

use App\Models\AgeGroup;
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
use App\Services\GroupResolverService;
use App\Services\OverallRankingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────────────────────

function service_cup5(): OverallRankingService
{
    return new OverallRankingService(new GroupResolverService);
}

function makeNation_cup5(string $code = 'AUT'): Nation
{
    return Nation::firstOrCreate(
        ['code' => $code],
        ['name_de' => $code, 'name_en' => $code, 'is_active' => true]
    );
}

function makeClub_cup5(): Club
{
    return Club::create(['name' => 'Testclub', 'nation_id' => makeNation_cup5()->id]);
}

function makeAthlete_cup5(array $attrs = []): Athlete
{
    return Athlete::create(array_merge([
        'first_name' => 'Max',
        'last_name' => 'Mustermann',
        'gender' => 'M',
        'nation_id' => makeNation_cup5()->id,
        'is_active' => true,
    ], $attrs));
}

function makeCup_cup5(array $attrs = []): Cup
{
    $version = BaseTimeVersion::create(['label' => 'V1', 'valid_from' => '2021-01-01']);

    return Cup::create(array_merge([
        'year' => 2026,
        'name' => 'ÖBSV Cup 2026',
        'base_time_version_id' => $version->id,
        'rounds_count' => 4,
        'best_of_count' => 2,
        'top_group_points_threshold' => 450,
    ], $attrs));
}

function makeMeet_cup5(Cup $cup, array $attrs = []): Meet
{
    return Meet::create(array_merge([
        'name' => 'Testmeet',
        'nation_id' => makeNation_cup5()->id,
        'course' => 'LCM',
        'start_date' => '2026-06-01',
        'cup_id' => $cup->id,
    ], $attrs));
}

function makeStrokeType_cup5(): StrokeType
{
    return StrokeType::firstOrCreate(
        ['code' => 'FREE'],
        ['lenex_code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle', 'category' => 'standard', 'is_active' => true]
    );
}

function makeResult_cup5(Athlete $athlete, Club $club, Meet $meet): Result
{
    $event = SwimEvent::create([
        'meet_id' => $meet->id, 'stroke_type_id' => makeStrokeType_cup5()->id, 'distance' => 100, 'gender' => 'A',
    ]);

    return Result::create([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id, 'club_id' => $club->id,
        'sport_class' => 'S9', 'swim_time' => 60000, 'points' => 1,
    ]);
}

/** Legt direkt eine Tageswertungs-Zeile an (umgeht DailyRankingService für schlanke Fixtures). */
function makeDailyResult_cup5(
    Cup $cup, Meet $meet, Athlete $athlete, Club $club, SportClassGroup $group, int $points, string $gender = 'M'
): CupDailyResult {
    return CupDailyResult::create([
        'cup_id' => $cup->id,
        'meet_id' => $meet->id,
        'athlete_id' => $athlete->id,
        'club_id' => $club->id,
        'result_id' => makeResult_cup5($athlete, $club, $meet)->id,
        'sport_class_group_id' => $group->id,
        'gender' => $gender,
        'points' => $points,
        'calculated_at' => now(),
    ]);
}

// ── calculateForCup: Grundlagen ──────────────────────────────────────────────

describe('calculateForCup — Grundlagen', function () {
    it('summiert die besten best_of_count Tageswertungen', function () {
        $group = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);
        $cup = makeCup_cup5(['best_of_count' => 2]);
        $club = makeClub_cup5();
        $athlete = makeAthlete_cup5();

        foreach ([300, 500, 450, 100] as $points) {
            makeDailyResult_cup5($cup, makeMeet_cup5($cup), $athlete, $club, $group, $points);
        }

        service_cup5()->calculateForCup($cup);

        $overall = CupOverallResult::where('cup_id', $cup->id)->firstOrFail();

        expect($overall->total_points)->toBe(500 + 450)
            ->and($overall->rounds_counted)->toBe(2);
    })->group('cup-wertung-p5');

    it('zählt weniger Runden, wenn der Athlet an weniger Meets teilgenommen hat als best_of_count', function () {
        $group = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);
        $cup = makeCup_cup5(['best_of_count' => 3]);
        $club = makeClub_cup5();
        $athlete = makeAthlete_cup5();

        makeDailyResult_cup5($cup, makeMeet_cup5($cup), $athlete, $club, $group, 400);

        service_cup5()->calculateForCup($cup);

        $overall = CupOverallResult::where('cup_id', $cup->id)->firstOrFail();

        expect($overall->total_points)->toBe(400)->and($overall->rounds_counted)->toBe(1);
    })->group('cup-wertung-p5');

    it('ersetzt bei erneuter Berechnung den bisherigen Snapshot vollständig', function () {
        $group = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);
        $cup = makeCup_cup5();
        $club = makeClub_cup5();
        $athlete = makeAthlete_cup5();
        makeDailyResult_cup5($cup, makeMeet_cup5($cup), $athlete, $club, $group, 400);

        service_cup5()->calculateForCup($cup);
        expect(CupOverallResult::where('cup_id', $cup->id)->count())->toBe(1);

        service_cup5()->calculateForCup($cup);
        expect(CupOverallResult::where('cup_id', $cup->id)->count())->toBe(1);
    })->group('cup-wertung-p5');

    it('bildet für unterschiedliche Athleten getrennte Zeilen', function () {
        $group = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);
        $cup = makeCup_cup5();
        $club = makeClub_cup5();
        $meet = makeMeet_cup5($cup);
        $a = makeAthlete_cup5(['first_name' => 'A']);
        $b = makeAthlete_cup5(['first_name' => 'B']);
        makeDailyResult_cup5($cup, $meet, $a, $club, $group, 400);
        makeDailyResult_cup5($cup, $meet, $b, $club, $group, 350);

        service_cup5()->calculateForCup($cup);

        expect(CupOverallResult::where('cup_id', $cup->id)->count())->toBe(2);
    })->group('cup-wertung-p5');
});

// ── Altersgruppe (nur Gesamtwertung) ─────────────────────────────────────────

describe('calculateForCup — Altersgruppe', function () {
    it('ordnet die Altersgruppe nach der 31.12.-Stichtagsregel des Cup-Jahres zu', function () {
        AgeGroup::create(['code' => 'JUGEND', 'name_de' => 'Jugend', 'max_age' => 18, 'is_active' => true]);
        AgeGroup::create(['code' => 'OFFEN', 'name_de' => 'Offen', 'min_age' => 19, 'is_active' => true]);
        $group = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);
        $cup = makeCup_cup5(['year' => 2026]);
        $club = makeClub_cup5();
        // wird am 31.12.2026 bereits 19 → Offen
        $athlete = makeAthlete_cup5(['birth_date' => '2007-06-15']);
        makeDailyResult_cup5($cup, makeMeet_cup5($cup), $athlete, $club, $group, 400);

        service_cup5()->calculateForCup($cup);

        $overall = CupOverallResult::where('cup_id', $cup->id)->firstOrFail();

        expect($overall->ageGroup->code)->toBe('OFFEN');
    })->group('cup-wertung-p5');

    it('lässt age_group_id leer, wenn der Athlet kein Geburtsdatum hat', function () {
        AgeGroup::create(['code' => 'JUGEND', 'name_de' => 'Jugend', 'max_age' => 18, 'is_active' => true]);
        $group = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);
        $cup = makeCup_cup5();
        $club = makeClub_cup5();
        $athlete = makeAthlete_cup5(['birth_date' => null]);
        makeDailyResult_cup5($cup, makeMeet_cup5($cup), $athlete, $club, $group, 400);

        service_cup5()->calculateForCup($cup);

        $overall = CupOverallResult::where('cup_id', $cup->id)->firstOrFail();

        expect($overall->age_group_id)->toBeNull();
    })->group('cup-wertung-p5');
});

// ── rankedBracket ─────────────────────────────────────────────────────────────

describe('rankedBracket', function () {
    it('vergibt bei Punktgleichstand denselben Rang und überspringt den nächsten', function () {
        $group = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);
        AgeGroup::create(['code' => 'OFFEN', 'name_de' => 'Offen', 'min_age' => 19, 'is_active' => true]);
        $cup = makeCup_cup5(['best_of_count' => 1]);
        $club = makeClub_cup5();
        $meet = makeMeet_cup5($cup);

        $a = makeAthlete_cup5(['first_name' => 'A', 'birth_date' => '2000-01-01']);
        $b = makeAthlete_cup5(['first_name' => 'B', 'birth_date' => '2000-01-01']);
        $c = makeAthlete_cup5(['first_name' => 'C', 'birth_date' => '2000-01-01']);
        makeDailyResult_cup5($cup, $meet, $a, $club, $group, 500);
        makeDailyResult_cup5($cup, $meet, $b, $club, $group, 500);
        makeDailyResult_cup5($cup, $meet, $c, $club, $group, 480);

        service_cup5()->calculateForCup($cup);

        $ageGroupId = AgeGroup::where('code', 'OFFEN')->value('id');
        $ranked = service_cup5()->rankedBracket($cup->id, 'M', $group->id, $ageGroupId);

        expect($ranked->pluck('rank')->all())->toBe([1, 1, 3]);
    })->group('cup-wertung-p5');
});
