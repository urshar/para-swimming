<?php

use App\Models\AgeGroup;
use App\Models\Athlete;
use App\Models\AthleteKaderMembership;
use App\Models\BaseTimeVersion;
use App\Models\Club;
use App\Models\Cup;
use App\Models\KaderType;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\SportClassGroup;
use App\Models\SportClassGroupMember;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Services\GroupResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────────────────────

function makeNation_cup2(string $code = 'AUT'): Nation
{
    return Nation::firstOrCreate(
        ['code' => $code],
        ['name_de' => $code, 'name_en' => $code, 'is_active' => true]
    );
}

function makeClub_cup2(string $nationCode = 'AUT'): Club
{
    return Club::create(['name' => 'Testclub', 'nation_id' => makeNation_cup2($nationCode)->id]);
}

function makeAthlete_cup2(array $attrs = []): Athlete
{
    return Athlete::create(array_merge([
        'first_name' => 'Max',
        'last_name' => 'Mustermann',
        'gender' => 'M',
        'nation_id' => makeNation_cup2()->id,
        'is_active' => true,
    ], $attrs));
}

function makeMeet_cup2(array $attrs = []): Meet
{
    return Meet::create(array_merge([
        'name' => 'Testmeet',
        'nation_id' => makeNation_cup2()->id,
        'course' => 'LCM',
        'start_date' => '2026-06-01',
    ], $attrs));
}

function makeCup_cup2(array $attrs = []): Cup
{
    $version = BaseTimeVersion::create(['label' => 'V1', 'valid_from' => '2021-01-01']);

    return Cup::create(array_merge([
        'year' => 2026,
        'name' => 'ÖBSV Cup 2026',
        'base_time_version_id' => $version->id,
        'rounds_count' => 1,
        'best_of_count' => 1,
        'top_group_points_threshold' => 450,
    ], $attrs));
}

function makeSportClassGroup_cup2(string $code, array $sportClasses = []): SportClassGroup
{
    $group = SportClassGroup::create(['code' => $code, 'name_de' => $code, 'is_active' => true]);

    foreach ($sportClasses as $sportClass) {
        SportClassGroupMember::create(['sport_class_group_id' => $group->id, 'sport_class' => $sportClass]);
    }

    return $group;
}

function makeTopGroup_cup2(): SportClassGroup
{
    return SportClassGroup::create(['code' => 'TOP', 'name_de' => 'Top-Gruppe', 'is_virtual' => true, 'is_active' => true]);
}

function makeStrokeType_cup2(): StrokeType
{
    return StrokeType::firstOrCreate(
        ['code' => 'FREE'],
        ['lenex_code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle', 'category' => 'standard', 'is_active' => true]
    );
}

function makeSwimEvent_cup2(Meet $meet, array $attrs = []): SwimEvent
{
    return SwimEvent::create(array_merge([
        'meet_id' => $meet->id,
        'stroke_type_id' => makeStrokeType_cup2()->id,
        'distance' => 100,
        'gender' => 'A',
    ], $attrs));
}

function makeResult_cup2(Athlete $athlete, Club $club, Meet $meet, array $attrs = []): Result
{
    return Result::create(array_merge([
        'meet_id' => $meet->id,
        'swim_event_id' => makeSwimEvent_cup2($meet)->id,
        'athlete_id' => $athlete->id,
        'club_id' => $club->id,
        'sport_class' => 'S9',
        'swim_time' => 60000,
        'points' => 400,
    ], $attrs));
}

// ── resolveBaseSportClassGroup ───────────────────────────────────────────────

describe('resolveBaseSportClassGroup', function () {
    it('findet die Gruppe für eine zugeordnete Sportklasse', function () {
        $group = makeSportClassGroup_cup2('PI', ['S9']);

        $resolved = (new GroupResolverService)->resolveBaseSportClassGroup('S9');

        expect($resolved?->id)->toBe($group->id);
    })->group('cup-wertung-p2');

    it('gibt null zurück für eine nicht zugeordnete Sportklasse (z.B. Staffel)', function () {
        makeSportClassGroup_cup2('PI', ['S9']);

        $resolved = (new GroupResolverService)->resolveBaseSportClassGroup('R20');

        expect($resolved)->toBeNull();
    })->group('cup-wertung-p2');

    it('nutzt die vorab geladene sportClassMap, wenn übergeben', function () {
        $group = makeSportClassGroup_cup2('PI', ['S9']);
        $service = new GroupResolverService;
        $map = $service->loadSportClassMap();

        expect($service->resolveBaseSportClassGroup('S9', $map)?->id)->toBe($group->id);
    })->group('cup-wertung-p2');
});

// ── isTopGroup: Nationalkader ─────────────────────────────────────────────────

describe('isTopGroup — Nationalkader', function () {
    it('Athlet mit aktiver Kaderzugehörigkeit ist in der Top-Gruppe', function () {
        $athlete = makeAthlete_cup2();
        $club = makeClub_cup2();
        $meet = makeMeet_cup2(['start_date' => '2026-06-01']);
        $cup = makeCup_cup2();
        $kaderType = KaderType::create(['code' => 'WELTKLASSE', 'name_de' => 'Weltklasse']);
        AthleteKaderMembership::create(['athlete_id' => $athlete->id, 'kader_type_id' => $kaderType->id]);

        $result = makeResult_cup2($athlete, $club, $meet, ['points' => 300]); // unter Punktgrenze

        expect((new GroupResolverService)->isTopGroup($result, $cup))->toBeTrue();
    })->group('cup-wertung-p2');

    it('Athlet ohne Kaderzugehörigkeit und unter der Punktgrenze ist NICHT in der Top-Gruppe', function () {
        $athlete = makeAthlete_cup2();
        $club = makeClub_cup2();
        $meet = makeMeet_cup2();
        $cup = makeCup_cup2();

        $result = makeResult_cup2($athlete, $club, $meet, ['points' => 300]);

        expect((new GroupResolverService)->isTopGroup($result, $cup))->toBeFalse();
    })->group('cup-wertung-p2');
});

// ── isTopGroup: Punktgrenze ───────────────────────────────────────────────────

describe('isTopGroup — Punktgrenze', function () {
    it('mehr als die Punktgrenze → Top-Gruppe', function () {
        $athlete = makeAthlete_cup2();
        $club = makeClub_cup2();
        $meet = makeMeet_cup2();
        $cup = makeCup_cup2(['top_group_points_threshold' => 450]);

        $result = makeResult_cup2($athlete, $club, $meet, ['points' => 451]);

        expect((new GroupResolverService)->isTopGroup($result, $cup))->toBeTrue();
    })->group('cup-wertung-p2');

    it('genau die Punktgrenze → NICHT Top-Gruppe (strikt "mehr als")', function () {
        $athlete = makeAthlete_cup2();
        $club = makeClub_cup2();
        $meet = makeMeet_cup2();
        $cup = makeCup_cup2(['top_group_points_threshold' => 450]);

        $result = makeResult_cup2($athlete, $club, $meet, ['points' => 450]);

        expect((new GroupResolverService)->isTopGroup($result, $cup))->toBeFalse();
    })->group('cup-wertung-p2');
});

// ── isTopGroup: Ausländischer Verein ─────────────────────────────────────────

describe('isTopGroup — Ausländischer Verein', function () {
    it('Verein mit Nation != AUT → Top-Gruppe', function () {
        $athlete = makeAthlete_cup2();
        $club = makeClub_cup2('GER');
        $meet = makeMeet_cup2();
        $cup = makeCup_cup2();

        $result = makeResult_cup2($athlete, $club, $meet, ['points' => 300]);

        expect((new GroupResolverService)->isTopGroup($result, $cup))->toBeTrue();
    })->group('cup-wertung-p2');

    it('österreichischer Verein → kein automatischer Top-Gruppen-Grund', function () {
        $athlete = makeAthlete_cup2();
        $club = makeClub_cup2('AUT');
        $meet = makeMeet_cup2();
        $cup = makeCup_cup2();

        $result = makeResult_cup2($athlete, $club, $meet, ['points' => 300]);

        expect((new GroupResolverService)->isTopGroup($result, $cup))->toBeFalse();
    })->group('cup-wertung-p2');
});

// ── resolveSportClassGroup (Gesamtlogik) ─────────────────────────────────────

describe('resolveSportClassGroup', function () {
    it('gibt die Top-Gruppe zurück, wenn ein Top-Kriterium zutrifft und die Gruppe aktiv ist', function () {
        makeSportClassGroup_cup2('PI', ['S9']);
        $topGroup = makeTopGroup_cup2();
        $athlete = makeAthlete_cup2();
        $club = makeClub_cup2('GER'); // ausländischer Verein
        $meet = makeMeet_cup2();
        $cup = makeCup_cup2();

        $result = makeResult_cup2($athlete, $club, $meet);

        $resolved = (new GroupResolverService)->resolveSportClassGroup($result, $cup);

        expect($resolved?->id)->toBe($topGroup->id);
    })->group('cup-wertung-p2');

    it('fällt auf die Basisgruppe zurück, wenn die Top-Gruppe für den Cup deaktiviert ist', function () {
        $baseGroup = makeSportClassGroup_cup2('PI', ['S9']);
        $topGroup = makeTopGroup_cup2();
        $athlete = makeAthlete_cup2();
        $club = makeClub_cup2('GER');
        $meet = makeMeet_cup2();
        $cup = makeCup_cup2();
        $cup->groupSettings()->create(['sport_class_group_id' => $topGroup->id, 'is_active' => false]);

        $result = makeResult_cup2($athlete, $club, $meet);

        $resolved = (new GroupResolverService)->resolveSportClassGroup($result, $cup);

        expect($resolved?->id)->toBe($baseGroup->id);
    })->group('cup-wertung-p2');

    it('gibt null zurück, wenn die zuständige Basisgruppe für den Cup deaktiviert ist', function () {
        $baseGroup = makeSportClassGroup_cup2('PI', ['S9']);
        $athlete = makeAthlete_cup2();
        $club = makeClub_cup2('AUT');
        $meet = makeMeet_cup2();
        $cup = makeCup_cup2();
        $cup->groupSettings()->create(['sport_class_group_id' => $baseGroup->id, 'is_active' => false]);

        $result = makeResult_cup2($athlete, $club, $meet, ['points' => 100]);

        $resolved = (new GroupResolverService)->resolveSportClassGroup($result, $cup);

        expect($resolved)->toBeNull();
    })->group('cup-wertung-p2');

    it('gibt null zurück für eine nicht zugeordnete Sportklasse ohne Top-Kriterium', function () {
        $athlete = makeAthlete_cup2();
        $club = makeClub_cup2('AUT');
        $meet = makeMeet_cup2();
        $cup = makeCup_cup2();

        $result = makeResult_cup2($athlete, $club, $meet, ['sport_class' => 'R20', 'points' => 100]);

        $resolved = (new GroupResolverService)->resolveSportClassGroup($result, $cup);

        expect($resolved)->toBeNull();
    })->group('cup-wertung-p2');
});

// ── resolveAgeGroup ───────────────────────────────────────────────────────────

describe('resolveAgeGroup', function () {
    it('zählt bereits im Wettkampfjahr als 18, wenn der Athlet erst am 31.12. Geburtstag hat', function () {
        AgeGroup::create(['code' => 'JUGEND', 'name_de' => 'Jugend', 'max_age' => 18, 'is_active' => true]);
        AgeGroup::create(['code' => 'OFFEN', 'name_de' => 'Offen', 'min_age' => 19, 'is_active' => true]);

        // Wird am 31.12.2026 erst 18 → zählt für das GANZE Jahr 2026 als 18 (Jugend),
        // obwohl er am Wettkampftag (01.06.2026) noch 17 ist.
        $athlete = makeAthlete_cup2(['birth_date' => '2008-12-31']);

        $group = (new GroupResolverService)->resolveAgeGroup($athlete, '2026-06-01');

        expect($group?->code)->toBe('JUGEND');
    })->group('cup-wertung-p2');

    it('zählt bereits im Vorjahr Geborene mit Geburtstag am 31.12. als ein Jahr älter', function () {
        AgeGroup::create(['code' => 'JUGEND', 'name_de' => 'Jugend', 'max_age' => 18, 'is_active' => true]);
        AgeGroup::create(['code' => 'OFFEN', 'name_de' => 'Offen', 'min_age' => 19, 'is_active' => true]);

        // Wird am 31.12.2026 bereits 19 → Offen, obwohl der Wettkampf schon im Jänner ist.
        $athlete = makeAthlete_cup2(['birth_date' => '2007-12-31']);

        $group = (new GroupResolverService)->resolveAgeGroup($athlete, '2026-01-15');

        expect($group?->code)->toBe('OFFEN');
    })->group('cup-wertung-p2');

    it('gibt null zurück, wenn kein Geburtsdatum hinterlegt ist', function () {
        AgeGroup::create(['code' => 'JUGEND', 'name_de' => 'Jugend', 'max_age' => 18, 'is_active' => true]);

        $athlete = makeAthlete_cup2(['birth_date' => null]);

        expect((new GroupResolverService)->resolveAgeGroup($athlete, '2026-06-01'))->toBeNull();
    })->group('cup-wertung-p2');
});
