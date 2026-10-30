<?php

use App\Enums\RoleTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('club-permissions-p3');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function userWithTemplate_cp3(RoleTemplate $template): User
{
    return User::factory()->create(['club_id' => makeClub_p5()->id, 'role_template' => $template]);
}

// ── Startseite nach dem Login ─────────────────────────────────────────────────

it('leitet nach dem Login auf den ersten freigegebenen Bereich weiter', function () {
    test()->actingAs(userWithTemplate_cp3(RoleTemplate::Club))
        ->get(route('dashboard'))
        ->assertRedirect(route('meets.index'));

    test()->actingAs(User::factory()->create(['is_admin' => true]))
        ->get(route('dashboard'))
        ->assertRedirect(route('meets.index'));
});

it('zeigt Benutzern ohne freigegebenen Bereich einen Hinweis statt einer 403-Seite', function () {
    test()->actingAs(userWithTemplate_cp3(RoleTemplate::Empty))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Noch kein Bereich freigegeben')
        ->assertSee(route('profile.edit'))
        ->assertDontSee(route('meets.index'));
});

// ── Navigation ────────────────────────────────────────────────────────────────

// Seit 3c (P3c-1) neu: Basiswerte lesen öffnet das Menü "Basiswerte", der Import bleibt Schreibrecht.
it('zeigt der Vorlage "Verein" dieselben Menüpunkte wie bisher einem Vereinsbenutzer, dazu die Basiswerte', function () {
    test()->actingAs(userWithTemplate_cp3(RoleTemplate::Club))
        ->get(route('meets.index'))
        ->assertOk()
        ->assertSee(route('club-entries.pick-meet'))
        ->assertSee(route('athletes.index'))
        ->assertSee(route('records.index'))
        ->assertSee(route('lenex.export'))
        ->assertSee(route('wps.rankings'))
        ->assertSee(route('base-times.versions.index'))
        ->assertDontSee(route('base-times.import'))
        ->assertDontSee(route('entries.index'))
        ->assertDontSee(route('results.index'))
        ->assertDontSee(route('lenex.import'))
        ->assertDontSee(route('records.import'))
        ->assertDontSee(route('statistics.index'))
        ->assertDontSee(route('kader-types.index'))
        ->assertDontSee(route('admin.users.index'));
});

it('zeigt der Vorlage "Trainer" nur Wettkämpfe und Vereinsmeldungen', function () {
    test()->actingAs(userWithTemplate_cp3(RoleTemplate::Coach))
        ->get(route('meets.index'))
        ->assertOk()
        ->assertSee(route('club-entries.pick-meet'))
        ->assertDontSee(route('athletes.index'))
        ->assertDontSee(route('records.index'))
        ->assertDontSee(route('lenex.export'))
        ->assertDontSee(route('wps.rankings'))
        ->assertDontSee(route('cups.overall-ranking.index'))
        ->assertDontSee('Stammdaten');
});

it('zeigt Admins alle Menüpunkte', function () {
    test()->actingAs(User::factory()->create(['is_admin' => true]))
        ->get(route('meets.index'))
        ->assertOk()
        ->assertSee(route('entries.index'))
        ->assertSee(route('lenex.import'))
        ->assertSee(route('statistics.index'))
        ->assertSee(route('base-times.versions.index'))
        ->assertSee(route('kader-types.index'))
        ->assertSee(route('admin.users.index'));
});
