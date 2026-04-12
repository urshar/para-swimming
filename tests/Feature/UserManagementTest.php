<?php

use App\Livewire\Admin\UserManager;
use App\Models\Club;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// ── Test-Helpers ──────────────────────────────────────────────────────────────

function makeAdmin(array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'is_admin' => true,
        'club_id' => null,
    ], $attrs));
}

function makeClubUser(Club $club, array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'is_admin' => false,
        'club_id' => $club->id,
    ], $attrs));
}

function makeClub(array $attrs = []): Club
{
    return Club::factory()->create($attrs);
}

// ═══════════════════════════════════════════════════════════════════════════
// Zugriffs-Tests
// ═══════════════════════════════════════════════════════════════════════════

describe('Zugriffskontrolle', function () {

    it('leitet nicht eingeloggte User auf Login um', function () {
        $this->get(route('admin.users.index'))
            ->assertRedirect(route('login'));
    });

    it('verweigert Vereins-Usern den Zugriff mit 403', function () {
        $club = makeClub();
        $user = makeClubUser($club);

        $this->actingAs($user)
            ->get(route('admin.users.index'))
            ->assertForbidden();
    });

    it('erlaubt Admins den Zugriff', function () {
        $admin = makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk();
    });

});

// ═══════════════════════════════════════════════════════════════════════════
// Livewire Component — Anzeige
// ═══════════════════════════════════════════════════════════════════════════

describe('UserManager Anzeige', function () {

    it('zeigt alle User in der Tabelle', function () {
        $admin = makeAdmin();
        $club = makeClub(['name' => 'Testverein Wien']);
        makeClubUser($club, ['name' => 'Max Muster']);

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->assertSee('Max Muster')
            ->assertSee('Testverein Wien');
    });

    it('filtert User per Suchfeld', function () {
        $admin = makeAdmin(['name' => 'Admin User']);
        makeClubUser(makeClub(), ['name' => 'Hans Schmidt', 'email' => 'hans@test.at']);
        makeClubUser(makeClub(), ['name' => 'Maria Huber',  'email' => 'maria@test.at']);

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->set('search', 'Hans')
            ->assertSee('Hans Schmidt')
            ->assertDontSee('Maria Huber');
    });

    it('zeigt Admin-Badge für Admins', function () {
        $admin = makeAdmin(['name' => 'Super Admin']);

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->assertSee('Admin');
    });

});

// ═══════════════════════════════════════════════════════════════════════════
// Livewire Component — User anlegen
// ═══════════════════════════════════════════════════════════════════════════

describe('User anlegen', function () {

    it('legt einen neuen Vereins-User an', function () {
        $admin = makeAdmin();
        $club = makeClub(['name' => 'SC Wien']);

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->call('openCreate')
            ->assertSet('showModal', true)
            ->set('name', 'Neue Person')
            ->set('email', 'neu@test.at')
            ->set('password', 'Secret123!')
            ->set('is_admin', false)
            ->set('club_id', $club->id)
            ->call('save');

        $this->assertDatabaseHas('users', [
            'name' => 'Neue Person',
            'email' => 'neu@test.at',
            'is_admin' => false,
            'club_id' => $club->id,
        ]);
    });

    it('legt einen Admin-User ohne Club an', function () {
        $admin = makeAdmin();
        $club = makeClub();

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->call('openCreate')
            ->set('name', 'Neuer Admin')
            ->set('email', 'newadmin@test.at')
            ->set('password', 'Secret123!')
            ->set('is_admin', true)
            ->set('club_id', $club->id)
            ->call('save');

        $this->assertDatabaseHas('users', [
            'email' => 'newadmin@test.at',
            'is_admin' => true,
            'club_id' => null,
        ]);
    });

    it('validiert Pflichtfelder', function () {
        $admin = makeAdmin();

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->call('openCreate')
            ->set('name', '')
            ->set('email', '')
            ->call('save')
            ->assertHasErrors(['name', 'email', 'password']);
    });

    it('validiert eindeutige E-Mail', function () {
        $admin = makeAdmin();
        User::factory()->create(['email' => 'existiert@test.at']);

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->call('openCreate')
            ->set('name', 'Jemand')
            ->set('email', 'existiert@test.at')
            ->set('password', 'Secret123!')
            ->call('save')
            ->assertHasErrors(['email']);
    });

    it('validiert ungültige club_id', function () {
        $admin = makeAdmin();

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->call('openCreate')
            ->set('name', 'Jemand')
            ->set('email', 'jemand@test.at')
            ->set('password', 'Secret123!')
            ->set('club_id', 99999)
            ->call('save')
            ->assertHasErrors(['club_id']);
    });

    it('schließt Modal nach erfolgreichem Anlegen', function () {
        $admin = makeAdmin();

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->call('openCreate')
            ->set('name', 'Test User')
            ->set('email', 'test@test.at')
            ->set('password', 'Secret123!')
            ->call('save')
            ->assertSet('showModal', false);
    });

});

// ═══════════════════════════════════════════════════════════════════════════
// Livewire Component — User bearbeiten
// ═══════════════════════════════════════════════════════════════════════════

describe('User bearbeiten', function () {

    it('befüllt das Formular mit bestehenden Daten', function () {
        $admin = makeAdmin();
        $club = makeClub();
        $user = makeClubUser($club, ['name' => 'Alt Name', 'email' => 'alt@test.at']);

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->call('openEdit', $user->id)
            ->assertSet('name', 'Alt Name')
            ->assertSet('email', 'alt@test.at')
            ->assertSet('is_admin', false)
            ->assertSet('club_id', $club->id)
            ->assertSet('showModal', true);
    });

    it('speichert Änderungen ohne Passwort-Änderung', function () {
        $admin = makeAdmin();
        $club = makeClub();
        $newClub = makeClub(['name' => 'Neuer Verein']);
        $user = makeClubUser($club, ['name' => 'Alt Name']);
        $oldHash = $user->password;

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->call('openEdit', $user->id)
            ->set('name', 'Neuer Name')
            ->set('club_id', $newClub->id)
            ->set('password', '')
            ->call('save');

        $user->refresh();

        expect($user->name)->toBe('Neuer Name')
            ->and($user->club_id)->toBe($newClub->id)
            ->and($user->password)->toBe($oldHash);
    });

    it('ändert das Passwort wenn angegeben', function () {
        $admin = makeAdmin();
        $user = makeClubUser(makeClub());
        $oldHash = $user->password;

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->call('openEdit', $user->id)
            ->set('password', 'NeuesPassword123!')
            ->call('save');

        $user->refresh();

        expect($user->password)->not->toBe($oldHash);
    });

    it('erlaubt gleiche E-Mail beim Bearbeiten des eigenen Users', function () {
        $admin = makeAdmin();

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->call('openEdit', $admin->id)
            ->set('name', 'Admin Neu')
            ->set('email', $admin->email)
            ->call('save')
            ->assertHasNoErrors(['email']);
    });

});

// ═══════════════════════════════════════════════════════════════════════════
// Livewire Component — User löschen
// ═══════════════════════════════════════════════════════════════════════════

describe('User löschen', function () {

    it('löscht einen anderen User', function () {
        $admin = makeAdmin();
        $user = makeClubUser(makeClub(), ['name' => 'Zu Löschen']);

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->call('delete', $user->id);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    });

    it('verhindert das Löschen des eigenen Accounts', function () {
        $admin = makeAdmin();

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->call('delete', $admin->id);

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    });

});

// ═══════════════════════════════════════════════════════════════════════════
// User-Model — Hilfsmethoden
// ═══════════════════════════════════════════════════════════════════════════

describe('User Model', function () {

    it('isAdmin() gibt true für Admins zurück', function () {
        $admin = makeAdmin();
        expect($admin->isAdmin())->toBeTrue();
    });

    it('isAdmin() gibt false für Vereins-User zurück', function () {
        $user = makeClubUser(makeClub());
        expect($user->isAdmin())->toBeFalse();
    });

    it('hasClub() gibt true zurück wenn club_id gesetzt ist', function () {
        $user = makeClubUser(makeClub());
        expect($user->hasClub())->toBeTrue();
    });

    it('hasClub() gibt false zurück wenn kein Club gesetzt ist', function () {
        $admin = makeAdmin();
        expect($admin->hasClub())->toBeFalse();
    });

    it('club-Relation lädt den zugehörigen Club', function () {
        $club = makeClub(['name' => 'SC Test']);
        $user = makeClubUser($club);

        expect($user->club->name)->toBe('SC Test');
    });

});
