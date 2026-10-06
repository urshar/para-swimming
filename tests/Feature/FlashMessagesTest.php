<?php

use App\Livewire\Admin\UserManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class)->group('flash-messages');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

function admin_fl(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

describe('Zentrale Flash-Meldungen im Layout', function () {

    it('zeigt den blockierten Vereins-Löschversuch in der Vereinsliste an', function () {
        $club = makeClub_p5();
        makeAthlete_p5($club);

        $response = $this->actingAs(admin_fl())
            ->from(route('clubs.index'))
            ->followingRedirects()
            ->delete(route('clubs.destroy', $club));

        $response->assertOk()
            ->assertSee('role="alert"', false)
            ->assertSee('Club kann nicht gelöscht werden — es sind noch 1 Athleten zugeordnet.');

        expect($club->fresh())->not->toBeNull();
    });

    it('zeigt die Erfolgsmeldung nach dem Löschen eines Athleten in der Athleten-Liste an', function () {
        $athlete = makeAthlete_p5(makeClub_p5());

        $this->actingAs(admin_fl())
            ->followingRedirects()
            ->delete(route('athletes.destroy', $athlete))
            ->assertOk()
            ->assertSee('role="status"', false)
            ->assertSee('Athlet gelöscht.');
    });

    it('zeigt eine Meldung nur einmal an, auch auf früheren Seiten mit eigenem Block', function () {
        $meet = makeMeet_p5();

        $html = $this->actingAs(admin_fl())
            ->withSession(['success' => 'Einmalige Meldung', 'error' => 'Einmaliger Fehler'])
            ->get(route('meets.show', $meet))
            ->assertOk()
            ->getContent();

        expect(substr_count($html, 'Einmalige Meldung'))->toBe(1)
            ->and(substr_count($html, 'Einmaliger Fehler'))->toBe(1);
    });

    it('zeigt ohne Flash-Daten keine Meldung an', function () {
        $this->actingAs(admin_fl())
            ->get(route('clubs.index'))
            ->assertOk()
            ->assertDontSee('data-flash=', false);
    });
});

describe('Benutzerverwaltung (Livewire)', function () {

    it('zeigt die Erfolgsmeldung nach einer Livewire-Aktion in der Komponente an', function () {
        $user = User::factory()->create(['is_admin' => false, 'club_id' => null]);

        Livewire::actingAs(admin_fl())
            ->test(UserManager::class)
            ->call('delete', $user->id)
            ->assertSee('Benutzer gelöscht.');
    });

    it('trägt die Meldung einer Livewire-Aktion nicht auf die nächste Seite weiter', function () {
        $user = User::factory()->create(['is_admin' => false, 'club_id' => null]);
        $admin = admin_fl();

        Livewire::actingAs($admin)
            ->test(UserManager::class)
            ->call('delete', $user->id);

        // Livewire::test läuft ohne Session-Middleware: das Speichern am Request-Ende (das die now()-Daten
        // verwirft) hier nachstellen.
        session()->save();

        $this->actingAs($admin)
            ->get(route('clubs.index'))
            ->assertDontSee('Benutzer gelöscht.');
    });
});
