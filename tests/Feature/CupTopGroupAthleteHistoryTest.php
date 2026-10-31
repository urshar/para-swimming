<?php

/** @noinspection PhpUnhandledExceptionInspection Pest-Test-Closures fangen Exceptions selbst ab. */

use App\Enums\AccessScope;
use App\Enums\RoleTemplate;
use App\Enums\TopGroupReason;
use App\Models\Athlete;
use App\Models\BaseTimeVersion;
use App\Models\Club;
use App\Models\Cup;
use App\Models\CupTopGroupClassification;
use App\Models\Nation;
use App\Models\User;
use App\Services\PermissionTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('cup-top-group-p3');

// ── Helpers ──────────────────────────────────────────────────────────────────

function makeNation_ctg3(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'AUT', 'name_en' => 'AUT', 'is_active' => true]);
}

function makeAthlete_ctg3(): Athlete
{
    return Athlete::create([
        'first_name' => 'Max', 'last_name' => 'Huber', 'gender' => 'M', 'nation_id' => makeNation_ctg3()->id,
        'is_active' => true,
    ]);
}

function makeCup_ctg3(int $year): Cup
{
    $version = BaseTimeVersion::create(['label' => "V$year", 'valid_from' => '2012-01-01']);

    return Cup::create([
        'year' => $year, 'name' => "ÖBSV Cup $year", 'base_time_version_id' => $version->id,
        'rounds_count' => 1, 'best_of_count' => 3, 'top_group_points_threshold' => 450,
    ]);
}

/**
 * @param  array<string, array{read: AccessScope, write: AccessScope}>  $matrix
 *
 * @throws Throwable
 */
function makeUser_ctg3(array $matrix): User
{
    $user = User::factory()->create([
        'club_id' => Club::create(['name' => 'Leseverein', 'nation_id' => makeNation_ctg3()->id])->id,
        'role_template' => RoleTemplate::Empty,
    ]);
    app(PermissionTemplateService::class)->setPermissions($user, $matrix);

    return $user->fresh();
}

it('zeigt die Top-Gruppen-Einstufung je Cup-Jahr, neuestes zuerst', function () {
    $athlete = makeAthlete_ctg3();
    $admin = User::factory()->create(['is_admin' => true, 'club_id' => null]);
    CupTopGroupClassification::create([
        'cup_id' => makeCup_ctg3(2025)->id, 'athlete_id' => $athlete->id, 'is_top_group' => true,
        'calculated_is_top_group' => true, 'reason' => TopGroupReason::TwoYearsAbove,
        'last_start_year' => 2024, 'last_start_points' => 501, 'prior_start_year' => 2023, 'prior_start_points' => 488,
        'calculated_at' => now(),
    ]);
    CupTopGroupClassification::create([
        'cup_id' => makeCup_ctg3(2026)->id, 'athlete_id' => $athlete->id, 'is_top_group' => false,
        'calculated_is_top_group' => true, 'reason' => TopGroupReason::CarriedOver,
        'manual_is_top_group' => false, 'manual_note' => 'Verletzungspause', 'manual_set_by' => $admin->id,
        'manual_set_at' => now(), 'calculated_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('athletes.show', $athlete))
        ->assertOk()
        ->assertSeeInOrder(['ÖBSV Cup — Top-Gruppe', '2026', 'nicht Top', 'von Hand', 'Verletzungspause',
            '2025', 'Top-Gruppe', 'zwei Jahre über der Grenze', '2024 501 Pkt.', '2023 488 Pkt.']);
});

it('zeigt einen Hinweis, wenn der Athlet nie eingestuft wurde', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->get(route('athletes.show', makeAthlete_ctg3()))
        ->assertOk()
        ->assertSee('Noch in keinem Cup-Jahr eingestuft.');
});

it('blendet den Abschnitt ohne Leserecht für die Cup-Wertung aus', function () {
    $user = makeUser_ctg3(['athletes' => ['read' => AccessScope::All, 'write' => AccessScope::None]]);

    $this->actingAs($user)
        ->get(route('athletes.show', makeAthlete_ctg3()))
        ->assertOk()
        ->assertDontSee('ÖBSV Cup — Top-Gruppe');
});

it('zeigt den Abschnitt mit Leserecht für die Cup-Wertung', function () {
    $user = makeUser_ctg3([
        'athletes' => ['read' => AccessScope::All, 'write' => AccessScope::None],
        'cup' => ['read' => AccessScope::All, 'write' => AccessScope::None],
    ]);

    $this->actingAs($user)
        ->get(route('athletes.show', makeAthlete_ctg3()))
        ->assertOk()
        ->assertSee('ÖBSV Cup — Top-Gruppe');
});
