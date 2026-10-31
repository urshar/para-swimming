<?php

use App\Enums\AccessScope;
use App\Enums\Module;
use App\Enums\RoleTemplate;
use App\Models\User;
use App\Services\PermissionTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('club-permissions-p3');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

/**
 * Nicht-Admin mit einzeln gesetzter Matrix (ohne Vorlage-Rechte).
 *
 * @param  array<string, array{read: AccessScope, write: AccessScope}>  $matrix
 *
 * @throws Throwable
 */
function userWithMatrix_cp3v(array $matrix): User
{
    $user = User::factory()->create(['club_id' => makeClub_p5()->id, 'role_template' => RoleTemplate::Empty]);
    app(PermissionTemplateService::class)->setPermissions($user, $matrix);

    return $user->fresh();
}

// ── Schaltflächen nach Modulrecht ─────────────────────────────────────────────

it('zeigt Pflege-Schaltflächen eines Moduls jedem mit Schreibrecht, nicht nur Admins', /** @throws Throwable */ function () {
    $meet = makeMeet_p5();
    $all = AccessScope::All;
    $user = userWithMatrix_cp3v([
        Module::Meets->value => ['read' => $all, 'write' => $all],
        Module::Lenex->value => ['read' => $all, 'write' => AccessScope::None],
    ]);

    test()->actingAs($user)->get(route('meets.show', $meet))
        ->assertOk()
        ->assertSee(route('meets.edit', $meet))
        ->assertSee(route('meets.events.create', $meet))
        ->assertSee(route('records.check', $meet))
        ->assertDontSee(route('meets.entries-overview', $meet))
        ->assertDontSee(route('meets.results-overview', $meet))
        ->assertDontSee(route('admin.meets.documents.index', $meet));

    test()->actingAs($user)->get(route('meets.index'))
        ->assertOk()
        ->assertSee(route('meets.create'));
});

it('blendet Pflege-Schaltflächen ohne Schreibrecht aus, auch bei Lesen "alle"', /** @throws Throwable */ function () {
    $club = makeClub_p5();
    $all = AccessScope::All;
    $user = userWithMatrix_cp3v([
        Module::Clubs->value => ['read' => $all, 'write' => AccessScope::None],
        Module::Records->value => ['read' => $all, 'write' => AccessScope::None],
    ]);

    test()->actingAs($user)->get(route('clubs.show', $club))
        ->assertOk()
        ->assertDontSee(route('clubs.edit', $club));

    test()->actingAs($user)->get(route('records.index'))
        ->assertOk()
        ->assertDontSee(route('records.create'));
});

it('zeigt "Bearbeiten" bei "eigener Verein" nur auf der eigenen Vereinsseite', /** @throws Throwable */ function () {
    $club = makeClub_p5();
    $foreignClub = makeClub_p5();
    $user = User::factory()->create(['club_id' => $club->id]);
    $own = AccessScope::Own;
    app(PermissionTemplateService::class)->setPermissions($user, [
        Module::Clubs->value => ['read' => AccessScope::All, 'write' => $own],
        Module::Athletes->value => ['read' => $own, 'write' => $own],
    ]);

    test()->actingAs($user->fresh())->get(route('clubs.show', $club))
        ->assertOk()
        ->assertSee(route('clubs.edit', $club));
    test()->actingAs($user->fresh())->get(route('clubs.show', $foreignClub))
        ->assertOk()
        ->assertDontSee(route('clubs.edit', $foreignClub));
});

it('verlinkt die Vereinsmeldungen für jeden, der melden darf, sonst nicht', /** @throws Throwable */ function () {
    $meet = makeMeet_p5();
    $meet->forceFill(['is_open' => true])->save();
    $coach = User::factory()->create(['club_id' => makeClub_p5()->id, 'role_template' => RoleTemplate::Coach]);
    $reader = userWithMatrix_cp3v([
        Module::Meets->value => ['read' => AccessScope::All, 'write' => AccessScope::None],
    ]);

    test()->actingAs($coach)->get(route('meets.show', $meet))
        ->assertOk()
        ->assertSee(route('club-entries.index', $meet));

    test()->actingAs($reader)->get(route('meets.show', $meet))
        ->assertOk()
        ->assertDontSee(route('club-entries.index', $meet));
});
