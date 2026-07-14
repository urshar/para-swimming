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
use App\Models\SportClassGroupMember;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Services\DailyRankingService;
use App\Services\GroupResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────────────────────

function service_cup3(): DailyRankingService
{
    return new DailyRankingService(new GroupResolverService);
}

function makeNation_cup3(string $code = 'AUT'): Nation
{
    return Nation::firstOrCreate(
        ['code' => $code],
        ['name_de' => $code, 'name_en' => $code, 'is_active' => true]
    );
}

function makeClub_cup3(string $nationCode = 'AUT'): Club
{
    return Club::create(['name' => 'Testclub', 'nation_id' => makeNation_cup3($nationCode)->id]);
}

function makeAthlete_cup3(array $attrs = []): Athlete
{
    return Athlete::create(array_merge([
        'first_name' => 'Max',
        'last_name' => 'Mustermann',
        'gender' => 'M',
        'nation_id' => makeNation_cup3()->id,
        'is_active' => true,
    ], $attrs));
}

function makeCup_cup3(array $attrs = []): Cup
{
    $version = BaseTimeVersion::create(['label' => 'V1', 'valid_from' => '2021-01-01']);

    return Cup::create(array_merge([
        'year' => 2026,
        'name' => 'ÖBSV Cup 2026',
        'base_time_version_id' => $version->id,
        'rounds_count' => 1,
        'best_of_count' => 3,
        'top_group_points_threshold' => 450,
    ], $attrs));
}

function makeMeet_cup3(?Cup $cup, array $attrs = []): Meet
{
    return Meet::create(array_merge([
        'name' => 'Testmeet',
        'nation_id' => makeNation_cup3()->id,
        'course' => 'LCM',
        'start_date' => '2026-06-01',
        'cup_id' => $cup?->id,
    ], $attrs));
}

function makeStrokeType_cup3(): StrokeType
{
    return StrokeType::firstOrCreate(
        ['code' => 'FREE'],
        ['lenex_code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle', 'category' => 'standard', 'is_active' => true]
    );
}

function makeSwimEvent_cup3(Meet $meet, array $attrs = []): SwimEvent
{
    return SwimEvent::create(array_merge([
        'meet_id' => $meet->id,
        'stroke_type_id' => makeStrokeType_cup3()->id,
        'distance' => 100,
        'gender' => 'A',
    ], $attrs));
}

function makeSportClassGroup_cup3(string $code, array $sportClasses = []): SportClassGroup
{
    $group = SportClassGroup::create(['code' => $code, 'name_de' => $code, 'is_active' => true]);

    foreach ($sportClasses as $sportClass) {
        SportClassGroupMember::create(['sport_class_group_id' => $group->id, 'sport_class' => $sportClass]);
    }

    return $group;
}

function makeResult_cup3(Athlete $athlete, Club $club, Meet $meet, array $attrs = []): Result
{
    return Result::create(array_merge([
        'meet_id' => $meet->id,
        'swim_event_id' => makeSwimEvent_cup3($meet)->id,
        'athlete_id' => $athlete->id,
        'club_id' => $club->id,
        'sport_class' => 'S9',
        'swim_time' => 60000,
        'points' => 400,
    ], $attrs));
}

// ── calculateForMeet: Grundlagen ─────────────────────────────────────────────

describe('calculateForMeet — Grundlagen', function () {
    it('wirft eine Exception, wenn das Meet keinem Cup zugeordnet ist', function () {
        $meet = makeMeet_cup3(null);

        expect(fn () => service_cup3()->calculateForMeet($meet))
            ->toThrow(InvalidArgumentException::class);
    })->group('cup-wertung-p3');

    it('wertet nur das punktbeste gültige Ergebnis eines Athleten', function () {
        makeSportClassGroup_cup3('PI', ['S9']);
        $cup = makeCup_cup3();
        $meet = makeMeet_cup3($cup);
        $athlete = makeAthlete_cup3();
        $club = makeClub_cup3();

        makeResult_cup3($athlete, $club, $meet, ['points' => 425]);
        makeResult_cup3($athlete, $club, $meet, ['points' => 471]); // bestes Ergebnis
        makeResult_cup3($athlete, $club, $meet, ['points' => 455]);

        $rows = service_cup3()->calculateForMeet($meet);

        expect($rows)->toHaveCount(1)
            ->and($rows->first()->points)->toBe(471);
    })->group('cup-wertung-p3');

    it('schließt ungültige Ergebnisse (DSQ) aus', function () {
        makeSportClassGroup_cup3('PI', ['S9']);
        $cup = makeCup_cup3();
        $meet = makeMeet_cup3($cup);
        $athlete = makeAthlete_cup3();
        $club = makeClub_cup3();

        makeResult_cup3($athlete, $club, $meet, ['points' => 999, 'status' => 'DSQ']);
        makeResult_cup3($athlete, $club, $meet, ['points' => 400]);

        $rows = service_cup3()->calculateForMeet($meet);

        expect($rows)->toHaveCount(1)->and($rows->first()->points)->toBe(400);
    })->group('cup-wertung-p3');

    it('schließt Ergebnisse ohne zugeordnete Sportklassengruppe aus (z.B. künftige Staffel-Klassen)', function () {
        // keine SportClassGroup für "R20" angelegt
        $cup = makeCup_cup3();
        $meet = makeMeet_cup3($cup);
        $athlete = makeAthlete_cup3();
        $club = makeClub_cup3();

        makeResult_cup3($athlete, $club, $meet, ['sport_class' => 'R20', 'points' => 400]);

        $rows = service_cup3()->calculateForMeet($meet);

        expect($rows)->toHaveCount(0);
    })->group('cup-wertung-p3');

    it('ersetzt bei erneuter Berechnung den bisherigen Snapshot vollständig', function () {
        makeSportClassGroup_cup3('PI', ['S9']);
        $cup = makeCup_cup3();
        $meet = makeMeet_cup3($cup);
        $athlete = makeAthlete_cup3();
        $club = makeClub_cup3();
        makeResult_cup3($athlete, $club, $meet, ['points' => 400]);

        service_cup3()->calculateForMeet($meet);
        expect(CupDailyResult::where('meet_id', $meet->id)->count())->toBe(1);

        // zweiter Athlet erst NACH der ersten Berechnung hinzugefügt
        $athlete2 = makeAthlete_cup3(['first_name' => 'Anna', 'gender' => 'F']);
        makeResult_cup3($athlete2, $club, $meet, ['points' => 350]);

        service_cup3()->calculateForMeet($meet);

        expect(CupDailyResult::where('meet_id', $meet->id)->count())->toBe(2);
    })->group('cup-wertung-p3');
});

// ── Bucketing: Geschlecht + Sportklassengruppe ───────────────────────────────

describe('calculateForMeet — Bucketing', function () {
    it('ordnet Männer und Frauen getrennten Wertungskategorien zu', function () {
        makeSportClassGroup_cup3('PI', ['S9']);
        $cup = makeCup_cup3();
        $meet = makeMeet_cup3($cup);
        $club = makeClub_cup3();

        $man = makeAthlete_cup3(['gender' => 'M']);
        $woman = makeAthlete_cup3(['gender' => 'F', 'first_name' => 'Anna']);
        makeResult_cup3($man, $club, $meet, ['points' => 400]);
        makeResult_cup3($woman, $club, $meet, ['points' => 420]);

        $rows = service_cup3()->calculateForMeet($meet);

        expect($rows->firstWhere('athlete_id', $man->id)->gender)->toBe('M')
            ->and($rows->firstWhere('athlete_id', $woman->id)->gender)->toBe('F');
    })->group('cup-wertung-p3');

    it('ordnet einen ausländischen Verein automatisch der Top-Gruppe zu', function () {
        makeSportClassGroup_cup3('PI', ['S9']);
        $topGroup = SportClassGroup::create(['code' => 'TOP', 'name_de' => 'Top-Gruppe', 'is_virtual' => true, 'is_active' => true]);
        $cup = makeCup_cup3();
        $meet = makeMeet_cup3($cup);
        $athlete = makeAthlete_cup3();
        $foreignClub = makeClub_cup3('GER');
        makeResult_cup3($athlete, $foreignClub, $meet, ['points' => 300]);

        $rows = service_cup3()->calculateForMeet($meet);

        expect($rows->first()->sport_class_group_id)->toBe($topGroup->id);
    })->group('cup-wertung-p3');
});

// ── rankedBracket / assignRanks ──────────────────────────────────────────────

describe('rankedBracket', function () {
    it('vergibt bei Punktgleichstand denselben Rang und überspringt den nächsten', function () {
        $group = makeSportClassGroup_cup3('PI', ['S9']);
        $cup = makeCup_cup3();
        $meet = makeMeet_cup3($cup);
        $club = makeClub_cup3();

        $a = makeAthlete_cup3(['first_name' => 'A']);
        $b = makeAthlete_cup3(['first_name' => 'B']);
        $c = makeAthlete_cup3(['first_name' => 'C']);
        makeResult_cup3($a, $club, $meet, ['points' => 500]);
        makeResult_cup3($b, $club, $meet, ['points' => 500]);
        makeResult_cup3($c, $club, $meet, ['points' => 480]);

        service_cup3()->calculateForMeet($meet);

        $ranked = service_cup3()->rankedBracket($meet->id, 'M', $group->id);

        expect($ranked->pluck('rank')->all())->toBe([1, 1, 3]);
    })->group('cup-wertung-p3');
});
