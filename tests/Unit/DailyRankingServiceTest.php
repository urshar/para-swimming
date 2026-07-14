<?php

/** @noinspection PhpUnhandledExceptionInspection Pest-Test-Closures fangen Exceptions selbst ab. */

use App\Models\Athlete;
use App\Models\BaseTime;
use App\Models\BaseTimeCategory;
use App\Models\BaseTimeDiscipline;
use App\Models\BaseTimeSportClass;
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
use App\Services\TopGroupClassificationService;
use App\Services\WorldAquaticsPointsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────────────────────

// 100,00s Basiszeit für S9/LCM/100m Freistil — als Konstante, damit sich Testdaten leicht nachvollziehen lassen.
function cup3BaseTimeCentiseconds(): int
{
    return 10000;
}

function service_cup3(): DailyRankingService
{
    return new DailyRankingService(
        new GroupResolverService,
        new TopGroupClassificationService,
        new WorldAquaticsPointsService
    );
}

/** Berechnet die erwarteten Punkte mit derselben Formel wie WorldAquaticsPointsService, zur Verifikation in Tests. */
function expectedPoints_cup3(int $swimTimeCentiseconds, ?int $baseTimeCentiseconds = null): int
{
    $baseTimeCentiseconds ??= cup3BaseTimeCentiseconds();

    return (int) round(1000 * ($baseTimeCentiseconds / $swimTimeCentiseconds) ** 3);
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
    makeBaseTimeEntry_cup3($version, cup3BaseTimeCentiseconds());
    makeBaseTimeEntry_cup3($version, cup3BaseTimeCentiseconds(), 'S9', 'F');

    return Cup::create(array_merge([
        'year' => 2026,
        'name' => 'ÖBSV Cup 2026',
        'base_time_version_id' => $version->id,
        'rounds_count' => 1,
        'best_of_count' => 3,
        'top_group_points_threshold' => 450,
    ], $attrs));
}

/** Legt die komplette Basiswert-Infrastruktur für S9/LCM/100m Freistil in einer Version an. */
function makeBaseTimeEntry_cup3(
    BaseTimeVersion $version,
    int $centiseconds,
    string $sportClassCode = 'S9',
    string $gender = 'M'
): BaseTime {
    $category = BaseTimeCategory::firstOrCreate(
        ['course' => 'LCM', 'gender' => $gender],
        ['code' => "LCM_$gender", 'label' => "LCM $gender"]
    );
    $discipline = BaseTimeDiscipline::firstOrCreate(
        ['stroke_type_id' => makeStrokeType_cup3()->id, 'distance' => 100, 'relay_count' => 1],
        ['code' => 'FREE_100']
    );
    $sportClass = BaseTimeSportClass::firstOrCreate(['code' => $sportClassCode], ['sort_order' => 1]);

    return BaseTime::create([
        'base_time_version_id' => $version->id,
        'base_time_category_id' => $category->id,
        'base_time_discipline_id' => $discipline->id,
        'base_time_sport_class_id' => $sportClass->id,
        'value_centiseconds' => $centiseconds,
    ]);
}

function makeMeet_cup3(array $attrs = []): Meet
{
    return Meet::create(array_merge([
        'name' => 'Testmeet',
        'nation_id' => makeNation_cup3()->id,
        'course' => 'LCM',
        'start_date' => '2026-06-01',
    ], $attrs));
}

function makeStrokeType_cup3(): StrokeType
{
    return StrokeType::firstOrCreate(
        ['code' => 'FREE'],
        [
            'lenex_code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle', 'category' => 'standard',
            'is_active' => true,
        ]
    );
}

function makeSwimEvent_cup3(Meet $meet, array $attrs = []): SwimEvent
{
    return SwimEvent::create(array_merge([
        'meet_id' => $meet->id,
        'stroke_type_id' => makeStrokeType_cup3()->id,
        'distance' => 100,
        'gender' => 'A',
        'relay_count' => 1,
    ], $attrs));
}

/**
 * $swimTimeCentiseconds bestimmt über die Basiswert-Formel die tatsächlich
 * gewerteten Punkte. 'points' wird bewusst auf einen abweichenden Fantasiewert
 * gesetzt, um zu verifizieren, dass die Tageswertung ihn NICHT verwendet.
 */
function makeResult_cup3(Athlete $athlete, Club $club, Meet $meet, int $swimTimeCentiseconds, array $attrs = []): Result
{
    return Result::create(array_merge([
        'meet_id' => $meet->id,
        'swim_event_id' => makeSwimEvent_cup3($meet)->id,
        'athlete_id' => $athlete->id,
        'club_id' => $club->id,
        'sport_class' => 'S9',
        'swim_time' => $swimTimeCentiseconds,
        'points' => 999999, // absichtlich falscher/veralteter Wert — darf NICHT übernommen werden
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

// ── calculateForMeet — Grundlagen ────────────────────────────────────────────

describe('calculateForMeet — Grundlagen', function () {
    it('wirft eine Exception, wenn das Meet keinem Cup zugeordnet ist', function () {
        $meet = makeMeet_cup3();

        expect(fn () => service_cup3()->calculateForMeet($meet))
            ->toThrow(InvalidArgumentException::class);
    })->group('cup-wertung-p3');

    it('berechnet die Punkte gegen die Cup-Basiswert-Version neu, statt results.points zu übernehmen', function () {
        makeSportClassGroup_cup3('PI', ['S9']);
        $cup = makeCup_cup3();
        $meet = makeMeet_cup3(['cup_id' => $cup->id]);
        $athlete = makeAthlete_cup3();
        $club = makeClub_cup3();

        makeResult_cup3($athlete, $club, $meet, cup3BaseTimeCentiseconds()); // exakt Basiszeit → 1000 Punkte

        $rows = service_cup3()->calculateForMeet($meet);

        expect($rows->first()->points)->toBe(1000)
            ->and($rows->first()->points)->not->toBe(999999); // der Fantasiewert aus results.points
    })->group('cup-wertung-p3');

    it('wertet nur das punktbeste gültige Ergebnis eines Athleten (schnellste Zeit = meiste Punkte)', function () {
        makeSportClassGroup_cup3('PI', ['S9']);
        $cup = makeCup_cup3();
        $meet = makeMeet_cup3(['cup_id' => $cup->id]);
        $athlete = makeAthlete_cup3();
        $club = makeClub_cup3();

        makeResult_cup3($athlete, $club, $meet, 11000); // langsamer
        makeResult_cup3($athlete, $club, $meet, 9500);  // am schnellsten → beste Punkte
        makeResult_cup3($athlete, $club, $meet, 10200);

        $rows = service_cup3()->calculateForMeet($meet);

        expect($rows)->toHaveCount(1)
            ->and($rows->first()->points)->toBe(expectedPoints_cup3(9500));
    })->group('cup-wertung-p3');

    it('schließt ungültige Ergebnisse (DSQ) aus', function () {
        makeSportClassGroup_cup3('PI', ['S9']);
        $cup = makeCup_cup3();
        $meet = makeMeet_cup3(['cup_id' => $cup->id]);
        $athlete = makeAthlete_cup3();
        $club = makeClub_cup3();

        makeResult_cup3($athlete, $club, $meet, 8000, ['status' => 'DSQ']); // wäre die beste Zeit
        makeResult_cup3($athlete, $club, $meet, 10500);

        $rows = service_cup3()->calculateForMeet($meet);

        expect($rows)->toHaveCount(1)
            ->and($rows->first()->points)->toBe(expectedPoints_cup3(10500));
    })->group('cup-wertung-p3');

    it('schließt Ergebnisse ohne zugeordnete Sportklassengruppe aus (z.B. künftige Staffel-Klassen)', function () {
        // keine SportClassGroup für "R20" angelegt
        $cup = makeCup_cup3();
        $meet = makeMeet_cup3(['cup_id' => $cup->id]);
        $athlete = makeAthlete_cup3();
        $club = makeClub_cup3();

        makeResult_cup3($athlete, $club, $meet, 10000, ['sport_class' => 'R20']);

        $rows = service_cup3()->calculateForMeet($meet);

        expect($rows)->toHaveCount(0);
    })->group('cup-wertung-p3');

    it('schließt Ergebnisse aus, für die sich in der Cup-Basiswert-Version keine Punkte berechnen lassen', function () {
        makeSportClassGroup_cup3('PI', ['S9']);
        $cup = makeCup_cup3();
        $meet = makeMeet_cup3(['cup_id' => $cup->id]);
        $athlete = makeAthlete_cup3();
        $club = makeClub_cup3();

        // Sportklasse S13 hat in dieser Basiswert-Version keinen Eintrag.
        makeResult_cup3($athlete, $club, $meet, 10000, ['sport_class' => 'S13']);

        $rows = service_cup3()->calculateForMeet($meet);

        expect($rows)->toHaveCount(0);
    })->group('cup-wertung-p3');

    it('ersetzt bei erneuter Berechnung den bisherigen Snapshot vollständig', function () {
        makeSportClassGroup_cup3('PI', ['S9']);
        $cup = makeCup_cup3();
        $meet = makeMeet_cup3(['cup_id' => $cup->id]);
        $athlete = makeAthlete_cup3();
        $club = makeClub_cup3();
        makeResult_cup3($athlete, $club, $meet, 10000);

        service_cup3()->calculateForMeet($meet);
        expect(CupDailyResult::where('meet_id', $meet->id)->count())->toBe(1);

        // zweiter Athlet erst NACH der ersten Berechnung hinzugefügt
        $athlete2 = makeAthlete_cup3(['first_name' => 'Anna', 'gender' => 'F']);
        makeResult_cup3($athlete2, $club, $meet, 10500);

        service_cup3()->calculateForMeet($meet);

        expect(CupDailyResult::where('meet_id', $meet->id)->count())->toBe(2);
    })->group('cup-wertung-p3');
});

// ── Bucketing: Geschlecht + Sportklassengruppe ───────────────────────────────

describe('calculateForMeet — Bucketing', function () {
    it('ordnet Männer und Frauen getrennten Wertungskategorien zu', function () {
        makeSportClassGroup_cup3('PI', ['S9']);
        $cup = makeCup_cup3();
        $meet = makeMeet_cup3(['cup_id' => $cup->id]);
        $club = makeClub_cup3();

        $man = makeAthlete_cup3(['gender' => 'M']);
        $woman = makeAthlete_cup3(['gender' => 'F', 'first_name' => 'Anna']);
        makeResult_cup3($man, $club, $meet, 10000);
        makeResult_cup3($woman, $club, $meet, 10000);

        $rows = service_cup3()->calculateForMeet($meet);

        expect($rows->firstWhere('athlete_id', $man->id)->gender)->toBe('M')
            ->and($rows->firstWhere('athlete_id', $woman->id)->gender)->toBe('F');
    })->group('cup-wertung-p3');

    it('ordnet einen ausländischen Verein automatisch der Top-Gruppe zu', function () {
        makeSportClassGroup_cup3('PI', ['S9']);
        $topGroup = SportClassGroup::create([
            'code' => 'TOP', 'name_de' => 'Top-Gruppe', 'is_virtual' => true, 'is_active' => true,
        ]);
        $cup = makeCup_cup3();
        $meet = makeMeet_cup3(['cup_id' => $cup->id]);
        $athlete = makeAthlete_cup3();
        $foreignClub = makeClub_cup3('GER');
        makeResult_cup3($athlete, $foreignClub, $meet, 10500);

        $rows = service_cup3()->calculateForMeet($meet);

        expect($rows->first()->sport_class_group_id)->toBe($topGroup->id);
    })->group('cup-wertung-p3');
});

// ── rankedBracket / assignRanks ──────────────────────────────────────────────

describe('rankedBracket', function () {
    it('vergibt bei Punktgleichstand denselben Rang und überspringt den nächsten', function () {
        $group = makeSportClassGroup_cup3('PI', ['S9']);
        $cup = makeCup_cup3();
        $meet = makeMeet_cup3(['cup_id' => $cup->id]);
        $club = makeClub_cup3();

        $a = makeAthlete_cup3(['first_name' => 'A']);
        $b = makeAthlete_cup3(['first_name' => 'B']);
        $c = makeAthlete_cup3(['first_name' => 'C']);
        makeResult_cup3($a, $club, $meet, 10000); // identische Zeit → identische Punkte
        makeResult_cup3($b, $club, $meet, 10000);
        makeResult_cup3($c, $club, $meet, 11000); // langsamer → weniger Punkte

        service_cup3()->calculateForMeet($meet);

        $ranked = service_cup3()->rankedBracket($meet->id, 'M', $group->id);

        expect($ranked->pluck('rank')->all())->toBe([1, 1, 3]);
    })->group('cup-wertung-p3');
});
