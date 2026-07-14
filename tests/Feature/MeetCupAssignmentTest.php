<?php

use App\Models\BaseTimeVersion;
use App\Models\Cup;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────────────────────

function makeAdmin_cup9(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function makeClubUser_cup9(): User
{
    return User::factory()->create(['is_admin' => false]);
}

function makeNation_cup9(string $code = 'AUT'): Nation
{
    return Nation::firstOrCreate(
        ['code' => $code],
        ['name_de' => $code, 'name_en' => $code, 'is_active' => true]
    );
}

function makeCup_cup9(): Cup
{
    $version = BaseTimeVersion::create(['label' => 'V1', 'valid_from' => '2021-01-01']);

    return Cup::create([
        'year' => 2026, 'name' => 'ÖBSV Cup 2026', 'base_time_version_id' => $version->id,
        'rounds_count' => 1, 'best_of_count' => 3, 'top_group_points_threshold' => 450,
    ]);
}

function meetPayload_cup9(array $overrides = []): array
{
    return array_merge([
        'name' => 'Testmeet', 'city' => 'Wien', 'nation_id' => makeNation_cup9()->id,
        'course' => 'LCM', 'start_date' => '2026-06-01',
    ], $overrides);
}

// ── store ──────────────────────────────────────────────────────────────────────

describe('store', function () {
    it('Admin kann ein Meet direkt einem Cup zuordnen', function () {
        $cup = makeCup_cup9();

        $this->actingAs(makeAdmin_cup9())
            ->post(route('meets.store'), meetPayload_cup9(['cup_id' => $cup->id]))
            ->assertRedirect();

        expect(Meet::where('name', 'Testmeet')->firstOrFail()->cup_id)->toBe($cup->id);
    })->group('cup-wertung-p9');

    it('cup_id eines Club-Users wird ignoriert', function () {
        $cup = makeCup_cup9();

        $this->actingAs(makeClubUser_cup9())
            ->post(route('meets.store'), meetPayload_cup9(['cup_id' => $cup->id]))
            ->assertRedirect();

        expect(Meet::where('name', 'Testmeet')->firstOrFail()->cup_id)->toBeNull();
    })->group('cup-wertung-p9');
});

// ── update ─────────────────────────────────────────────────────────────────────

describe('update', function () {
    it('Admin kann die Cup-Zuordnung eines bestehenden Meets ändern', function () {
        $meet = Meet::create(meetPayload_cup9());
        $cup = makeCup_cup9();

        $this->actingAs(makeAdmin_cup9())
            ->put(route('meets.update', $meet), meetPayload_cup9(['cup_id' => $cup->id]))
            ->assertRedirect();

        expect($meet->fresh()->cup_id)->toBe($cup->id);
    })->group('cup-wertung-p9');

    it('Admin kann die Cup-Zuordnung wieder entfernen', function () {
        $cup = makeCup_cup9();
        $meet = Meet::create(meetPayload_cup9(['cup_id' => $cup->id]));

        $this->actingAs(makeAdmin_cup9())
            ->put(route('meets.update', $meet), meetPayload_cup9(['cup_id' => '']))
            ->assertRedirect();

        expect($meet->fresh()->cup_id)->toBeNull();
    })->group('cup-wertung-p9');

    it('cup_id-Änderung eines Club-Users wird ignoriert', function () {
        $meet = Meet::create(meetPayload_cup9());
        $cup = makeCup_cup9();

        $this->actingAs(makeClubUser_cup9())
            ->put(route('meets.update', $meet), meetPayload_cup9(['cup_id' => $cup->id]))
            ->assertRedirect();

        expect($meet->fresh()->cup_id)->toBeNull();
    })->group('cup-wertung-p9');
});
