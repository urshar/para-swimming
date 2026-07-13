<?php

use App\Models\AgeGroup;
use App\Models\Athlete;
use App\Models\AthleteKaderMembership;
use App\Models\BaseTimeVersion;
use App\Models\Cup;
use App\Models\KaderType;
use App\Models\Nation;
use App\Models\SportClassGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// ── Cup::isGroupActive ───────────────────────────────────────────────────────

describe('Cup::isGroupActive', function () {
    it('gilt als aktiv, wenn kein expliziter Eintrag existiert (Default)', function () {
        $version = BaseTimeVersion::create(['label' => 'V1', 'valid_from' => '2021-01-01']);
        $cup = Cup::create([
            'year' => 2026, 'name' => 'ÖBSV Cup 2026', 'base_time_version_id' => $version->id,
            'rounds_count' => 1, 'best_of_count' => 1, 'top_group_points_threshold' => 450,
        ]);
        $group = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);

        expect($cup->isGroupActive($group))->toBeTrue();
    })->group('cup-wertung-p1');
});

// ── AgeGroup::matchesAge ─────────────────────────────────────────────────────

describe('AgeGroup::matchesAge', function () {
    it('Jugend (max 18) matched Alter bis 18, nicht 19', function () {
        $jugend = AgeGroup::create(['code' => 'JUGEND', 'name_de' => 'Jugend', 'max_age' => 18]);

        expect($jugend->matchesAge(18))->toBeTrue()
            ->and($jugend->matchesAge(19))->toBeFalse();
    })->group('cup-wertung-p1');

    it('Offen (min 19) matched Alter ab 19, nicht 18', function () {
        $offen = AgeGroup::create(['code' => 'OFFEN', 'name_de' => 'Offen', 'min_age' => 19]);

        expect($offen->matchesAge(19))->toBeTrue()
            ->and($offen->matchesAge(18))->toBeFalse();
    })->group('cup-wertung-p1');
});

// ── AthleteKaderMembership::scopeActiveOn ────────────────────────────────────

describe('AthleteKaderMembership::scopeActiveOn', function () {
    it('findet eine unbegrenzt gültige Zugehörigkeit an jedem Stichtag', function () {
        $athlete = Athlete::create([
            'first_name' => 'Max', 'last_name' => 'Mustermann', 'gender' => 'M',
            'nation_id' => Nation::create(['code' => 'AUT', 'name_de' => 'AUT', 'name_en' => 'AUT'])->id,
        ]);
        $kaderType = KaderType::create(['code' => 'WELTKLASSE', 'name_de' => 'Weltklasse']);
        AthleteKaderMembership::create(['athlete_id' => $athlete->id, 'kader_type_id' => $kaderType->id]);

        expect($athlete->kaderMemberships()->activeOn('2026-06-01')->exists())->toBeTrue();
    })->group('cup-wertung-p1');

    it('findet eine zeitlich begrenzte Zugehörigkeit nur innerhalb des Zeitraums', function () {
        $athlete = Athlete::create([
            'first_name' => 'Max', 'last_name' => 'Mustermann', 'gender' => 'M',
            'nation_id' => Nation::create(['code' => 'AUT', 'name_de' => 'AUT', 'name_en' => 'AUT'])->id,
        ]);
        $kaderType = KaderType::create(['code' => 'WELTKLASSE', 'name_de' => 'Weltklasse']);
        AthleteKaderMembership::create([
            'athlete_id' => $athlete->id, 'kader_type_id' => $kaderType->id,
            'valid_from' => '2026-01-01', 'valid_until' => '2026-12-31',
        ]);

        expect($athlete->kaderMemberships()->activeOn('2026-06-01')->exists())->toBeTrue()
            ->and($athlete->kaderMemberships()->activeOn('2027-01-01')->exists())->toBeFalse();
    })->group('cup-wertung-p1');
});
