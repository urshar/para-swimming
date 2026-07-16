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

// ── Cup::isAgeGroupActive ─────────────────────────────────────────────────────

describe('Cup::isAgeGroupActive', function () {
    it('gilt als aktiv, wenn kein expliziter Eintrag existiert (Default)', function () {
        $cup = makeCup_cup12();
        $ageGroup = AgeGroup::create(['code' => 'JUGEND', 'name_de' => 'Jugend', 'max_age' => 18, 'is_active' => true]);

        expect($cup->isAgeGroupActive($ageGroup))->toBeTrue();
    })->group('cup-wertung-p12');

    it('kann pro Cup deaktiviert werden', function () {
        $cup = makeCup_cup12();
        $ageGroup = AgeGroup::create(['code' => 'JUGEND', 'name_de' => 'Jugend', 'max_age' => 18, 'is_active' => true]);
        $cup->ageGroupSettings()->create(['age_group_id' => $ageGroup->id, 'is_active' => false]);

        expect($cup->isAgeGroupActive($ageGroup))->toBeFalse();
    })->group('cup-wertung-p12');
});

// ── GroupResolverService::resolveAgeGroup mit Cup ────────────────────────────

describe('resolveAgeGroup — Cup-Deaktivierung', function () {
    it('gibt null zurück, wenn die passende Altersgruppe für den Cup deaktiviert ist', function () {
        $jugend = AgeGroup::create(['code' => 'JUGEND', 'name_de' => 'Jugend', 'max_age' => 18, 'is_active' => true]);
        AgeGroup::create(['code' => 'OFFEN', 'name_de' => 'Offen', 'min_age' => 19, 'is_active' => true]);
        $cup = makeCup_cup12();
        $cup->ageGroupSettings()->create(['age_group_id' => $jugend->id, 'is_active' => false]);

        $athlete = makeAthlete_cup12(['birth_date' => '2010-01-01']); // ist 2026 Jugend

        $group = (new GroupResolverService)->resolveAgeGroup($athlete, '2026-06-01', $cup);

        expect($group)->toBeNull();
    })->group('cup-wertung-p12');

    it('ordnet weiterhin normal zu, wenn keine Cup-Deaktivierung vorliegt', function () {
        AgeGroup::create(['code' => 'JUGEND', 'name_de' => 'Jugend', 'max_age' => 18, 'is_active' => true]);
        $cup = makeCup_cup12();

        $athlete = makeAthlete_cup12(['birth_date' => '2010-01-01']);

        $group = (new GroupResolverService)->resolveAgeGroup($athlete, '2026-06-01', $cup);

        expect($group?->code)->toBe('JUGEND');
    })->group('cup-wertung-p12');

    it('funktioniert unverändert ohne Cup-Parameter (Rückwärtskompatibilität)', function () {
        AgeGroup::create(['code' => 'JUGEND', 'name_de' => 'Jugend', 'max_age' => 18, 'is_active' => true]);

        $athlete = makeAthlete_cup12(['birth_date' => '2010-01-01']);

        $group = (new GroupResolverService)->resolveAgeGroup($athlete, '2026-06-01');

        expect($group?->code)->toBe('JUGEND');
    })->group('cup-wertung-p12');
});
