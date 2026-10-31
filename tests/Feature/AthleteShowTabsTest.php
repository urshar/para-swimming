<?php

/** @noinspection PhpUnhandledExceptionInspection Pest-Test-Closures fangen Exceptions selbst ab. */

use App\Enums\AccessScope;
use App\Enums\RoleTemplate;
use App\Models\Athlete;
use App\Models\Club;
use App\Models\Nation;
use App\Models\User;
use App\Services\PermissionTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('athlete-show-tabs');

// ── Helpers ──────────────────────────────────────────────────────────────────

function makeNation_ast(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'AUT', 'name_en' => 'AUT', 'is_active' => true]);
}

function makeClub_ast(string $name): Club
{
    return Club::create(['name' => $name, 'nation_id' => makeNation_ast()->id]);
}

function makeAthlete_ast(): Athlete
{
    return Athlete::create([
        'first_name' => 'Max', 'last_name' => 'Huber', 'gender' => 'M', 'nation_id' => makeNation_ast()->id,
        'is_active' => true,
    ]);
}

function makeAdmin_ast(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

/**
 * @param  array<string, array{read: AccessScope, write: AccessScope}>  $matrix
 *
 * @throws Throwable
 */
function makeUser_ast(array $matrix): User
{
    $user = User::factory()->create([
        'club_id' => makeClub_ast('Leseverein')->id,
        'role_template' => RoleTemplate::Empty,
    ]);
    app(PermissionTemplateService::class)->setPermissions($user, $matrix);

    return $user->fresh();
}

// ── Reiter ───────────────────────────────────────────────────────────────────

it('öffnet ohne Reiter-Parameter die Ergebnisse und rendert nur diesen Reiter', function () {
    $athlete = makeAthlete_ast();

    $this->actingAs(makeAdmin_ast())
        ->get(route('athletes.show', $athlete))
        ->assertOk()
        ->assertSee('Noch keine Ergebnisse vorhanden.')
        ->assertSee(route('athletes.show', [$athlete, 'reiter' => 'klassifikation']), false)
        ->assertSee(route('athletes.show', [$athlete, 'reiter' => 'cup']), false)
        ->assertDontSee('Noch keine Klassifikationen eingetragen.')
        ->assertDontSee('Noch keine Vereinshistory vorhanden.')
        ->assertDontSee('Kein Nationalkader-Eintrag vorhanden.');
});

it('rendert den gewählten Reiter', function (string $tab, string $expected) {
    $this->actingAs(makeAdmin_ast())
        ->get(route('athletes.show', [makeAthlete_ast(), 'reiter' => $tab]))
        ->assertOk()
        ->assertSee($expected)
        ->assertDontSee('Noch keine Ergebnisse vorhanden.');
})->with([
    'Klassifikation' => ['klassifikation', 'Noch keine Klassifikationen eingetragen.'],
    'Vereine' => ['vereine', 'Noch keine Vereinshistory vorhanden.'],
    'Level & Kader' => ['level-kader', 'Kein Nationalkader-Eintrag vorhanden.'],
    'ÖBSV Cup' => ['cup', 'Noch in keinem Cup-Jahr eingestuft.'],
]);

it('fällt bei einem unbekannten Reiter auf die Ergebnisse zurück', function () {
    $this->actingAs(makeAdmin_ast())
        ->get(route('athletes.show', [makeAthlete_ast(), 'reiter' => 'gibt-es-nicht']))
        ->assertOk()
        ->assertSee('Noch keine Ergebnisse vorhanden.');
});

it('bietet den Cup-Reiter ohne Leserecht für die Cup-Wertung nicht an', function () {
    $athlete = makeAthlete_ast();
    $user = makeUser_ast(['athletes' => ['read' => AccessScope::All, 'write' => AccessScope::None]]);

    $this->actingAs($user)
        ->get(route('athletes.show', [$athlete, 'reiter' => 'cup']))
        ->assertOk()
        ->assertSee('Noch keine Ergebnisse vorhanden.')
        ->assertDontSee(route('athletes.show', [$athlete, 'reiter' => 'cup']), false)
        ->assertDontSee('ÖBSV Cup — Top-Gruppe');
});

it('öffnet nach dem Anlegen (?neue_klassifikation=1) den Klassifikations-Reiter', function () {
    $this->actingAs(makeAdmin_ast())
        ->get(route('athletes.show', [makeAthlete_ast(), 'neue_klassifikation' => 1]))
        ->assertOk()
        ->assertSee('Noch keine Klassifikationen eingetragen.')
        ->assertDontSee('Noch keine Ergebnisse vorhanden.');
});

// ── Weiterleitung nach dem Speichern auf den passenden Reiter ─────────────────

it('führt nach der Ummeldung zurück auf den Reiter "Vereine"', function () {
    $athlete = makeAthlete_ast();

    $this->actingAs(makeAdmin_ast())
        ->post(route('athletes.transfer-club', $athlete), [
            'club_id' => makeClub_ast('Neuer Verein')->id,
            'joined_at' => '2026-10-01',
        ])
        ->assertRedirect(route('athletes.show', ['athlete' => $athlete, 'reiter' => 'vereine']));
});

it('führt nach dem Eintragen einer Klassifikation zurück auf den Reiter "Klassifikation"', function () {
    $athlete = makeAthlete_ast();

    $this->actingAs(makeAdmin_ast())
        ->post(route('athletes.classifications.store', $athlete), [
            'classified_at' => '2026-10-01',
            'classification_scope' => 'NAT',
            'classification_status' => 'CONFIRMED',
        ])
        ->assertRedirect(route('athletes.show', ['athlete' => $athlete, 'reiter' => 'klassifikation']));
});

it('führt nach dem Eintragen eines Levels zurück auf den Reiter "Level & Kader"', function () {
    $athlete = makeAthlete_ast();

    $this->actingAs(makeAdmin_ast())
        ->post(route('athletes.levels.store', $athlete), [
            'level' => 'A',
            'changed_at' => '2026-10-01',
        ])
        ->assertRedirect(route('athletes.show', ['athlete' => $athlete, 'reiter' => 'level-kader']));

    expect($athlete->fresh()->level)->toBe('A');
});
