<?php

/** @noinspection PhpUnhandledExceptionInspection Pest-Test-Closures fangen Exceptions selbst ab. */

use App\Models\AgeGroup;
use App\Models\Athlete;
use App\Models\BaseTimeVersion;
use App\Models\Cup;
use App\Models\Nation;
use App\Models\SportClassGroup;
use App\Services\GroupResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────────────────────

function makeCup_cup12(): Cup
{
    $version = BaseTimeVersion::create(['label' => 'V1', 'valid_from' => '2021-01-01']);

    return Cup::create([
        'year' => 2026, 'name' => 'ÖBSV Cup 2026', 'base_time_version_id' => $version->id,
        'rounds_count' => 1, 'best_of_count' => 3, 'top_group_points_threshold' => 450,
    ]);
}

function makeAthlete_cup12(array $attrs = []): Athlete
{
    $nation = Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'AUT', 'name_en' => 'AUT', 'is_active' => true]);

    return Athlete::create(array_merge([
        'first_name' => 'Max', 'last_name' => 'Mustermann', 'gender' => 'M',
        'nation_id' => $nation->id, 'is_active' => true,
    ], $attrs));
}

// ── Cup::isGenderCombined ─────────────────────────────────────────────────────

describe('Cup::isGenderCombined', function () {
    it('ist standardmäßig false (getrennte Wertung), wenn kein Eintrag existiert', function () {
        $cup = makeCup_cup12();
        $group = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);

        expect($cup->isGenderCombined($group))->toBeFalse();
    })->group('cup-wertung-p12');

    it('ist true, wenn explizit auf gemeinsam gesetzt', function () {
        $cup = makeCup_cup12();
        $group = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);
        $cup->groupSettings()->create(['sport_class_group_id' => $group->id, 'gender_combined' => true]);

        expect($cup->isGenderCombined($group))->toBeTrue();
    })->group('cup-wertung-p12');
});

// ── Cup::isAgeGroupActive (pro Sportklassengruppe, Erik 2026-07-19) ──────────────

describe('Cup::isAgeGroupActive', function () {
    it('gilt als aktiv, wenn kein expliziter Eintrag existiert (Default)', function () {
        $cup = makeCup_cup12();
        $group = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);
        $ageGroup = AgeGroup::create(['code' => 'JUGEND', 'name_de' => 'Jugend', 'max_age' => 18, 'is_active' => true]);

        expect($cup->isAgeGroupActive($ageGroup, $group))->toBeTrue();
    })->group('cup-wertung-p12');

    it('kann pro Sportklassengruppe unabhängig deaktiviert werden', function () {
        $cup = makeCup_cup12();
        $groupPI = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);
        $groupVI = SportClassGroup::create(['code' => 'VI', 'name_de' => 'VI', 'is_active' => true]);
        $ageGroup = AgeGroup::create(['code' => 'JUGEND', 'name_de' => 'Jugend', 'max_age' => 18, 'is_active' => true]);

        $cup->ageGroupSettings()->create([
            'sport_class_group_id' => $groupPI->id, 'age_group_id' => $ageGroup->id, 'is_active' => false,
        ]);

        expect($cup->isAgeGroupActive($ageGroup, $groupPI))->toBeFalse()
            ->and($cup->isAgeGroupActive($ageGroup, $groupVI))->toBeTrue();
    })->group('cup-wertung-p12');
});

// ── GroupResolverService::resolveAgeGroup — dynamische Altersgrenzen (Erik, 2026-07-20) ──

describe('resolveAgeGroup — dynamische Altersgrenzen pro Sportklassengruppe', function () {
    it('Jugend deaktiviert -> Offen greift ab 0 (übernimmt die komplette Spanne)', function () {
        $jugend = AgeGroup::create(['code' => 'JUGEND', 'name_de' => 'Jugend', 'min_age' => 0, 'max_age' => 18, 'sort_order' => 10, 'is_active' => true]);
        AgeGroup::create(['code' => 'OFFEN', 'name_de' => 'Offen', 'min_age' => 19, 'sort_order' => 20, 'is_active' => true]);
        $cup = makeCup_cup12();
        $group = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);
        $cup->ageGroupSettings()->create([
            'sport_class_group_id' => $group->id, 'age_group_id' => $jugend->id, 'is_active' => false,
        ]);

        $athlete = makeAthlete_cup12(['birth_date' => '2010-01-01']); // wäre normalerweise Jugend (16)

        $resolvedGroup = (new GroupResolverService)->resolveAgeGroup($athlete, '2026-06-01', $cup, $group);

        expect($resolvedGroup?->code)->toBe('OFFEN');
    })->group('cup-wertung-p12');

    it('Jugend + Offen aktiv -> Jugend 0-18, Offen ab 19', function () {
        AgeGroup::create(['code' => 'JUGEND', 'name_de' => 'Jugend', 'min_age' => 0, 'max_age' => 18, 'sort_order' => 10, 'is_active' => true]);
        AgeGroup::create(['code' => 'OFFEN', 'name_de' => 'Offen', 'min_age' => 19, 'sort_order' => 20, 'is_active' => true]);
        $cup = makeCup_cup12();
        $group = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);

        $jugendlich = makeAthlete_cup12(['birth_date' => '2010-01-01']); // 16
        $erwachsen = makeAthlete_cup12(['birth_date' => '2000-01-01']); // 26

        $resolver = new GroupResolverService;

        expect($resolver->resolveAgeGroup($jugendlich, '2026-06-01', $cup, $group)?->code)->toBe('JUGEND')
            ->and($resolver->resolveAgeGroup($erwachsen, '2026-06-01', $cup, $group)?->code)->toBe('OFFEN');
    })->group('cup-wertung-p12');

    it('Jugend + Offen + Senioren aktiv -> Offen wird auf 19-49 eingegrenzt', function () {
        AgeGroup::create(['code' => 'JUGEND', 'name_de' => 'Jugend', 'min_age' => 0, 'max_age' => 18, 'sort_order' => 10, 'is_active' => true]);
        AgeGroup::create(['code' => 'OFFEN', 'name_de' => 'Offen', 'min_age' => 19, 'sort_order' => 20, 'is_active' => true]);
        AgeGroup::create(['code' => 'SENIOREN', 'name_de' => 'Senioren', 'min_age' => 50, 'sort_order' => 30, 'is_active' => true]);
        $cup = makeCup_cup12();
        $group = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);

        $mitte = makeAthlete_cup12(['birth_date' => '1990-01-01']); // 36 -> Offen
        $senior = makeAthlete_cup12(['birth_date' => '1970-01-01']); // 56 -> Senioren

        $resolver = new GroupResolverService;

        expect($resolver->resolveAgeGroup($mitte, '2026-06-01', $cup, $group)?->code)->toBe('OFFEN')
            ->and($resolver->resolveAgeGroup($senior, '2026-06-01', $cup, $group)?->code)->toBe('SENIOREN');
    })->group('cup-wertung-p12');

    it('Senioren existiert, ist aber deaktiviert -> Offen wird nach oben unbegrenzt', function () {
        AgeGroup::create(['code' => 'JUGEND', 'name_de' => 'Jugend', 'min_age' => 0, 'max_age' => 18, 'sort_order' => 10, 'is_active' => true]);
        AgeGroup::create(['code' => 'OFFEN', 'name_de' => 'Offen', 'min_age' => 19, 'sort_order' => 20, 'is_active' => true]);
        $senioren = AgeGroup::create(['code' => 'SENIOREN', 'name_de' => 'Senioren', 'min_age' => 50, 'sort_order' => 30, 'is_active' => true]);
        $cup = makeCup_cup12();
        $group = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);
        $cup->ageGroupSettings()->create([
            'sport_class_group_id' => $group->id, 'age_group_id' => $senioren->id, 'is_active' => false,
        ]);

        $senior = makeAthlete_cup12(['birth_date' => '1970-01-01']); // 56 -> müsste ohne Senioren zu Offen fallen

        $resolvedGroup = (new GroupResolverService)->resolveAgeGroup($senior, '2026-06-01', $cup, $group);

        expect($resolvedGroup?->code)->toBe('OFFEN');
    })->group('cup-wertung-p12');

    it('nur Offen aktiv -> deckt 0 bis unbegrenzt ab', function () {
        $jugend = AgeGroup::create(['code' => 'JUGEND', 'name_de' => 'Jugend', 'min_age' => 0, 'max_age' => 18, 'sort_order' => 10, 'is_active' => true]);
        AgeGroup::create(['code' => 'OFFEN', 'name_de' => 'Offen', 'min_age' => 19, 'sort_order' => 20, 'is_active' => true]);
        $senioren = AgeGroup::create(['code' => 'SENIOREN', 'name_de' => 'Senioren', 'min_age' => 50, 'sort_order' => 30, 'is_active' => true]);
        $cup = makeCup_cup12();
        $group = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);
        $cup->ageGroupSettings()->create(['sport_class_group_id' => $group->id, 'age_group_id' => $jugend->id, 'is_active' => false]);
        $cup->ageGroupSettings()->create(['sport_class_group_id' => $group->id, 'age_group_id' => $senioren->id, 'is_active' => false]);

        $kind = makeAthlete_cup12(['birth_date' => '2018-01-01']); // 8
        $senior = makeAthlete_cup12(['birth_date' => '1960-01-01']); // 66

        $resolver = new GroupResolverService;

        expect($resolver->resolveAgeGroup($kind, '2026-06-01', $cup, $group)?->code)->toBe('OFFEN')
            ->and($resolver->resolveAgeGroup($senior, '2026-06-01', $cup, $group)?->code)->toBe('OFFEN');
    })->group('cup-wertung-p12');

    it('keine Altersgruppe aktiv -> gemeinsame Wertung ohne Alterskategorie (null)', function () {
        $jugend = AgeGroup::create(['code' => 'JUGEND', 'name_de' => 'Jugend', 'min_age' => 0, 'max_age' => 18, 'sort_order' => 10, 'is_active' => true]);
        $offen = AgeGroup::create(['code' => 'OFFEN', 'name_de' => 'Offen', 'min_age' => 19, 'sort_order' => 20, 'is_active' => true]);
        $cup = makeCup_cup12();
        $group = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);
        $cup->ageGroupSettings()->create(['sport_class_group_id' => $group->id, 'age_group_id' => $jugend->id, 'is_active' => false]);
        $cup->ageGroupSettings()->create(['sport_class_group_id' => $group->id, 'age_group_id' => $offen->id, 'is_active' => false]);

        $athlete = makeAthlete_cup12(['birth_date' => '2000-01-01']);

        $resolvedGroup = (new GroupResolverService)->resolveAgeGroup($athlete, '2026-06-01', $cup, $group);

        expect($resolvedGroup)->toBeNull();
    })->group('cup-wertung-p12');

    it('gilt unabhängig pro Sportklassengruppe (PI deaktiviert Jugend, VI nicht)', function () {
        $jugend = AgeGroup::create(['code' => 'JUGEND', 'name_de' => 'Jugend', 'min_age' => 0, 'max_age' => 18, 'sort_order' => 10, 'is_active' => true]);
        AgeGroup::create(['code' => 'OFFEN', 'name_de' => 'Offen', 'min_age' => 19, 'sort_order' => 20, 'is_active' => true]);
        $cup = makeCup_cup12();
        $groupPI = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);
        $groupVI = SportClassGroup::create(['code' => 'VI', 'name_de' => 'VI', 'is_active' => true]);
        $cup->ageGroupSettings()->create([
            'sport_class_group_id' => $groupPI->id, 'age_group_id' => $jugend->id, 'is_active' => false,
        ]);

        $athlete = makeAthlete_cup12(['birth_date' => '2010-01-01']); // 16

        $resolver = new GroupResolverService;

        expect($resolver->resolveAgeGroup($athlete, '2026-06-01', $cup, $groupPI)?->code)->toBe('OFFEN')
            ->and($resolver->resolveAgeGroup($athlete, '2026-06-01', $cup, $groupVI)?->code)->toBe('JUGEND');
    })->group('cup-wertung-p12');

    it('funktioniert unverändert ohne Cup-/Sportklassengruppen-Parameter (Rückwärtskompatibilität, statische Grenzen)', function () {
        AgeGroup::create(['code' => 'JUGEND', 'name_de' => 'Jugend', 'max_age' => 18, 'is_active' => true]);

        $athlete = makeAthlete_cup12(['birth_date' => '2010-01-01']);

        $resolvedGroup = (new GroupResolverService)->resolveAgeGroup($athlete, '2026-06-01');

        expect($resolvedGroup?->code)->toBe('JUGEND');
    })->group('cup-wertung-p12');
});
