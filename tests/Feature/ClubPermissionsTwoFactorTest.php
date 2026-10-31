<?php

use App\Enums\AccessScope;
use App\Enums\Module;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Livewire\Admin\UserManager;
use App\Models\User;
use App\Services\PermissionTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;

uses(RefreshDatabase::class)->group('club-permissions-p10');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php). Die Testsuite schaltet die Pflicht aus (P10-2);
// hier wird sie je Test eingeschaltet.

beforeEach(function () {
    config(['auth.two_factor_required' => true]);
});

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

/** Vereinsnutzer mit der Vorlage "Verein" (hat Schreibrechte, also 2FA-Pflicht). */
function writer_cp10(): User
{
    return User::factory()->create(['club_id' => makeClub_p5()->id]);
}

function withTwoFactor_cp10(User $user): User
{
    $user->forceFill([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $user;
}

function reader_cp10(): User
{
    $user = User::factory()->create(['club_id' => makeClub_p5()->id]);
    app(PermissionTemplateService::class)->setPermissions($user, [
        Module::Meets->value => ['read' => AccessScope::All, 'write' => AccessScope::None],
    ]);

    return $user->fresh();
}

// ── Pflicht ───────────────────────────────────────────────────────────────────

it('verlangt 2FA von Admins und Benutzern mit Schreibrecht, nicht von reinen Lesern', function () {
    expect(writer_cp10()->requiresTwoFactor())->toBeTrue()
        ->and(User::factory()->create(['is_admin' => true])->requiresTwoFactor())->toBeTrue()
        ->and(reader_cp10()->requiresTwoFactor())->toBeFalse();
});

it('leitet ohne 2FA jede Seite hinter dem Login auf die Sicherheitsseite', function () {
    $user = writer_cp10();

    $this->actingAs($user)->get(route('meets.index'))->assertRedirect(route('security.edit'));
    $this->actingAs($user)->get(route('athletes.index'))->assertRedirect(route('security.edit'));
    $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.users.index'))
        ->assertRedirect(route('security.edit'));
    $this->actingAs($user)->getJson(route('meets.index'))->assertForbidden();
});

it('lässt Sicherheitsseite, Passwortbestätigung, öffentlichen Bereich und Abmelden zu', function () {
    $user = writer_cp10();

    $this->actingAs($user)->get(route('security.edit'))->assertRedirect(route('password.confirm'));
    $this->actingAs($user)->get(route('password.confirm'))->assertOk();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSee('Zwei-Faktor-Anmeldung ist Pflicht');
    $this->actingAs($user)->get(route('public.robots'))->assertOk();
    $this->actingAs($user)->post(route('logout'))->assertRedirect('/');
});

it('lässt mit bestätigter 2FA, als reiner Leser und bei abgeschalteter Pflicht durch', function () {
    $this->actingAs(withTwoFactor_cp10(writer_cp10()))->get(route('meets.index'))->assertOk();
    $this->actingAs(reader_cp10())->get(route('meets.index'))->assertOk();

    config(['auth.two_factor_required' => false]);
    $this->actingAs(writer_cp10())->get(route('meets.index'))->assertOk();
});

it('wirkt ein neu vergebenes Schreibrecht ab der nächsten Seite', function () {
    $user = reader_cp10();
    $this->actingAs($user)->get(route('meets.index'))->assertOk();

    app(PermissionTemplateService::class)->setPermissions($user, [
        Module::Meets->value => ['read' => AccessScope::All, 'write' => AccessScope::All],
    ]);

    $this->actingAs($user->fresh())->get(route('meets.index'))->assertRedirect(route('security.edit'));
});

it('gilt auch für Livewire-Aktionen (persistente Livewire-Middleware)', function () {
    expect(app(PersistentMiddleware::class)->getPersistentMiddleware())->toContain(EnsureTwoFactorEnabled::class);
});

// ── Deaktivieren gesperrt ─────────────────────────────────────────────────────

it('lässt die Pflicht-2FA weder über die Seite noch über die Fortify-Route deaktivieren', function () {
    $user = withTwoFactor_cp10(writer_cp10());

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertDontSee('Disable 2FA');

    Livewire::actingAs($user)->test('pages::settings.security')->call('disable')->assertForbidden();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('two-factor.disable'))
        ->assertForbidden();

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
});

// ── Benutzerverwaltung und Notfallbefehl ──────────────────────────────────────

it('zeigt in der Benutzerverwaltung fehlende Pflicht-2FA an', function () {
    writer_cp10();

    Livewire::actingAs(withTwoFactor_cp10(User::factory()->create(['is_admin' => true])))
        ->test(UserManager::class)
        ->assertSee('aus (Pflicht)');
});

it('setzt die 2FA per Artisan zurück', function () {
    $user = withTwoFactor_cp10(writer_cp10());

    $this->artisan('users:reset-two-factor', ['email' => $user->email])->assertSuccessful();
    $this->artisan('users:reset-two-factor', ['email' => 'unbekannt@example.org'])->assertFailed();

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();
    $this->actingAs($user->fresh())->get(route('meets.index'))->assertRedirect(route('security.edit'));
});
