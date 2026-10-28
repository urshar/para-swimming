<?php

use App\Enums\AccessScope;
use App\Enums\Module;
use App\Enums\RoleTemplate;
use App\Livewire\Admin\UserManager;
use App\Models\Club;
use App\Models\User;
use App\Models\UserModulePermission;
use App\Services\PermissionTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class)->group('club-permissions-p2');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function makeUser_cp2(?Club $club, bool $isAdmin = false): User
{
    static $i = 0;
    $i++;

    return User::create([
        'name' => 'Person '.$i,
        'email' => "person$i@example.test",
        'password' => 'password',
        'is_admin' => $isAdmin,
        'club_id' => $club?->id,
    ]);
}

function service_cp2(): PermissionTemplateService
{
    return app(PermissionTemplateService::class);
}

function manager_cp2(User $admin): Testable
{
    return Livewire::actingAs($admin)->test(UserManager::class);
}

// ── Absicherung ───────────────────────────────────────────────────────────────

it('verweigert Nicht-Admins die Komponente auch ohne Route', function () {
    $user = makeUser_cp2(makeClub_p5());
    service_cp2()->applyTemplate($user, RoleTemplate::Club);

    Livewire::actingAs($user)->test(UserManager::class)->assertForbidden();
});

// ── Anlegen und Bearbeiten ────────────────────────────────────────────────────

it('belegt beim Anlegen die Vorlage nach dem Verein vor und wendet sie an', function () {
    $club = makeClub_p5();

    manager_cp2(makeUser_cp2(null, true))
        ->call('openCreate')
        ->assertSet('role_template', RoleTemplate::Empty->value)
        ->set('name', 'Neu Verein')
        ->set('email', 'neu-verein@example.test')
        ->set('password', 'Secret123!')
        ->set('club_id', $club->id)
        ->assertSet('role_template', RoleTemplate::Club->value)
        ->call('save')
        ->assertHasNoErrors();

    $user = User::where('email', 'neu-verein@example.test')->first();

    expect($user->role_template)->toBe(RoleTemplate::Club)
        ->and($user->writeScope(Module::Athletes))->toBe(AccessScope::Own)
        ->and(service_cp2()->isCustomized($user))->toBeFalse();
});

it('behält eine von Hand gewählte Vorlage, auch wenn danach der Verein gewählt wird', function () {
    manager_cp2(makeUser_cp2(null, true))
        ->call('openCreate')
        ->set('role_template', RoleTemplate::Coach->value)
        ->set('club_id', makeClub_p5()->id)
        ->assertSet('role_template', RoleTemplate::Coach->value);
});

it('legt Admins ohne Vorlage und ohne Rechte-Zeilen an', function () {
    manager_cp2(makeUser_cp2(null, true))
        ->call('openCreate')
        ->set('name', 'Zweiter Admin')
        ->set('email', 'admin2@example.test')
        ->set('password', 'Secret123!')
        ->set('is_admin', true)
        ->call('save')
        ->assertHasNoErrors();

    $user = User::where('email', 'admin2@example.test')->first();

    expect($user->role_template)->toBeNull()
        ->and(UserModulePermission::where('user_id', $user->id)->count())->toBe(0);
});

it('ersetzt die Matrix nur bei geänderter Vorlage', function () {
    $user = makeUser_cp2(makeClub_p5());
    service_cp2()->applyTemplate($user, RoleTemplate::Club);
    service_cp2()->setPermissions($user, [
        Module::Statistics->value => ['read' => AccessScope::All, 'write' => AccessScope::None],
    ]);
    $admin = makeUser_cp2(null, true);

    // Gleiche Vorlage: Anpassung bleibt.
    manager_cp2($admin)
        ->call('openEdit', $user->id)
        ->assertSet('templateWillOverwrite', false)
        ->set('name', 'Umbenannt')
        ->call('save')
        ->assertHasNoErrors();

    expect($user->fresh()->canRead(Module::Statistics))->toBeTrue()
        ->and($user->fresh()->canRead(Module::Meets))->toBeFalse();

    // Neue Vorlage: Matrix wird ersetzt, vorher wird gewarnt.
    manager_cp2($admin)
        ->call('openEdit', $user->id)
        ->set('role_template', RoleTemplate::Coach->value)
        ->assertSet('templateWillOverwrite', true)
        ->call('save')
        ->assertHasNoErrors();

    $fresh = $user->fresh();

    expect($fresh->role_template)->toBe(RoleTemplate::Coach)
        ->and($fresh->canRead(Module::Statistics))->toBeFalse()
        ->and($fresh->writeScope(Module::Entries))->toBe(AccessScope::Own);
});

it('verhindert, dass sich ein Admin selbst die Admin-Rechte nimmt', function () {
    $admin = makeUser_cp2(null, true);

    manager_cp2($admin)
        ->call('openEdit', $admin->id)
        ->set('is_admin', false)
        ->set('role_template', RoleTemplate::Empty->value)
        ->call('save')
        ->assertHasErrors(['is_admin']);

    expect($admin->fresh()->is_admin)->toBeTrue();
});

// ── Rechte-Matrix ─────────────────────────────────────────────────────────────

it('lädt die Matrix, speichert sie und markiert den Benutzer als angepasst', function () {
    $user = makeUser_cp2(makeClub_p5());
    service_cp2()->applyTemplate($user, RoleTemplate::Coach);

    manager_cp2(makeUser_cp2(null, true))
        ->call('openPermissions', $user->id)
        ->assertSet('showPermissions', true)
        ->assertSet('matrix.entries', ['read' => 'own', 'write' => 'own'])
        ->assertSet('matrix.athletes', ['read' => 'none', 'write' => 'none'])
        ->set('matrix.athletes.read', 'own')
        ->call('savePermissions')
        ->assertHasNoErrors()
        ->assertSet('showPermissions', false)
        ->assertSee('angepasst');

    $fresh = $user->fresh();

    expect($fresh->readScope(Module::Athletes))->toBe(AccessScope::Own)
        ->and($fresh->role_template)->toBe(RoleTemplate::Coach)
        ->and(service_cp2()->isCustomized($fresh))->toBeTrue();
});

it('meldet ungültige Matrix-Einträge je Modul und speichert nichts', function () {
    $user = makeUser_cp2(makeClub_p5());
    service_cp2()->applyTemplate($user, RoleTemplate::Coach);

    manager_cp2(makeUser_cp2(null, true))
        ->call('openPermissions', $user->id)
        ->set('matrix.athletes.read', 'own')
        ->set('matrix.athletes.write', 'all')
        ->call('savePermissions')
        ->assertHasErrors(['permissions.athletes'])
        ->assertSet('showPermissions', true);

    expect($user->fresh()->canRead(Module::Athletes))->toBeFalse();
});

it('setzt die Matrix eines Benutzers per "Vorlage neu anwenden" zurück', function () {
    $user = makeUser_cp2(makeClub_p5());
    service_cp2()->applyTemplate($user, RoleTemplate::Coach);
    service_cp2()->setPermissions($user, []);

    manager_cp2(makeUser_cp2(null, true))
        ->call('openPermissions', $user->id)
        ->call('reapplyForPermissionsUser')
        ->assertSet('matrix.entries', ['read' => 'own', 'write' => 'own']);

    expect(service_cp2()->isCustomized($user->fresh()))->toBeFalse();
});

it('öffnet für Admins keine Matrix', function () {
    $other = makeUser_cp2(null, true);

    manager_cp2(makeUser_cp2(null, true))
        ->call('openPermissions', $other->id)
        ->assertSet('showPermissions', false);
});

// ── Vorlage für alle neu anwenden ─────────────────────────────────────────────

it('zeigt die Zahl der betroffenen Benutzer und wendet die Vorlage für alle neu an', function () {
    $club = makeClub_p5();
    $plain = makeUser_cp2($club);
    $custom = makeUser_cp2($club);
    service_cp2()->applyTemplate($plain, RoleTemplate::Coach);
    service_cp2()->applyTemplate($custom, RoleTemplate::Coach);
    service_cp2()->setPermissions($custom, []);

    manager_cp2(makeUser_cp2(null, true))
        ->call('openReapply')
        ->set('reapplyTemplate', RoleTemplate::Coach->value)
        ->assertSet('reapplyStats', ['total' => 2, 'customized' => 1])
        ->call('confirmReapply')
        ->assertSet('showReapply', false);

    expect($custom->fresh()->canWrite(Module::Entries))->toBeTrue();
});

// ── Zwei-Faktor ───────────────────────────────────────────────────────────────

it('setzt die Zwei-Faktor-Anmeldung eines anderen Benutzers zurück, nicht die eigene', function () {
    $admin = makeUser_cp2(null, true);
    $user = makeUser_cp2(makeClub_p5());
    $twoFactor = [
        'two_factor_secret' => 'geheim',
        'two_factor_recovery_codes' => 'codes',
        'two_factor_confirmed_at' => now(),
    ];
    $user->forceFill($twoFactor)->save();
    $admin->forceFill($twoFactor)->save();

    manager_cp2($admin)
        ->assertSee('aktiv')
        ->call('resetTwoFactor', $user->id)
        ->call('resetTwoFactor', $admin->id);

    $user->refresh();
    $admin->refresh();

    expect($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and($admin->two_factor_confirmed_at)->not->toBeNull();
});

// ── Liste ─────────────────────────────────────────────────────────────────────

it('filtert die Liste nach Vorlage und nach "ohne Vorlage"', function () {
    $club = makeClub_p5();
    $coach = makeUser_cp2($club);
    service_cp2()->applyTemplate($coach, RoleTemplate::Coach);
    $legacy = makeUser_cp2($club);

    manager_cp2(makeUser_cp2(null, true))
        ->set('templateFilter', RoleTemplate::Coach->value)
        ->assertSee($coach->email)
        ->assertDontSee($legacy->email)
        ->set('templateFilter', 'NONE')
        ->assertSee($legacy->email)
        ->assertDontSee($coach->email);
});

// ── Artisan-Befehl ────────────────────────────────────────────────────────────

it('weist im Probelauf nichts zu', function () {
    $user = makeUser_cp2(makeClub_p5());

    $this->artisan('permissions:assign-template', ['template' => 'club', '--dry-run' => true])
        ->expectsOutputToContain('Probelauf')
        ->assertSuccessful();

    expect($user->fresh()->role_template)->toBeNull();
});

it('weist die Vorlage nur Nicht-Admins ohne Vorlage zu, mit --only-with-club nur mit Verein', function () {
    $club = makeClub_p5();
    $legacy = makeUser_cp2($club);
    $noClub = makeUser_cp2(null);
    $admin = makeUser_cp2(null, true);
    $coach = makeUser_cp2($club);
    service_cp2()->applyTemplate($coach, RoleTemplate::Coach);

    $this->artisan('permissions:assign-template', ['template' => 'club', '--only-with-club' => true])
        ->expectsConfirmation('Vorlage "Verein" auf 1 Benutzer anwenden?', 'yes')
        ->assertSuccessful();

    expect($legacy->fresh()->role_template)->toBe(RoleTemplate::Club)
        ->and($legacy->fresh()->canWrite(Module::Athletes))->toBeTrue()
        ->and($noClub->fresh()->role_template)->toBeNull()
        ->and($admin->fresh()->role_template)->toBeNull()
        ->and($coach->fresh()->role_template)->toBe(RoleTemplate::Coach);
});

it('lehnt eine unbekannte Vorlage ab', function () {
    $this->artisan('permissions:assign-template', ['template' => 'gibt-es-nicht'])
        ->assertFailed();
});
