<?php

use App\Http\Middleware\RequireAdmin;
use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class)->group('access-control');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function admin_acc(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function clubUser_acc(Club $club): User
{
    return User::factory()->create(['is_admin' => false, 'club_id' => $club->id]);
}

/**
 * Routen hinter dem Login, die schreiben oder ein Schreib-Formular zeigen dürfen, ohne RequireAdmin:
 * eigene Vereinsmeldungen (EntryPolicy), die Export-Downloads (lesend, aber per POST) und die Konto-Routen.
 */
function allowedForClubUsers_acc(string $name): bool
{
    return (bool) preg_match(
        '/^(club-entries\.|lenex\.export|records\.export|logout|password\.|two-factor\.|verification\.|profile\.|security\.|appearance\.|user-password\.|settings)/',
        $name
    );
}

/** Schreibt die Route (nicht GET/HEAD) oder zeigt sie ein Anlege-, Bearbeiten- oder Import-Formular? */
function isWriteRoute_acc(RoutingRoute $route): bool
{
    $name = (string) $route->getName();

    return array_diff($route->methods(), ['GET', 'HEAD']) !== []
        || preg_match('/\.(create|edit)$|\.import(\.|$)|\/import$/', $name.'|'.$route->uri()) === 1;
}

// ── Routen-Audit ──────────────────────────────────────────────────────────────

it('schützt jede schreibende Route hinter dem Login mit RequireAdmin, außer den bewusst offenen', function () {
    $unprotected = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $r) => in_array('auth', $r->gatherMiddleware(), true))
        ->filter(fn (RoutingRoute $r) => isWriteRoute_acc($r))
        ->reject(fn (RoutingRoute $r) => in_array(RequireAdmin::class, $r->gatherMiddleware(), true)
            || in_array('admin', $r->gatherMiddleware(), true))
        ->reject(fn (RoutingRoute $r) => allowedForClubUsers_acc($r->getName() ?? $r->uri()))
        ->map(fn (RoutingRoute $r) => implode('|', $r->methods()).' '.($r->getName() ?? $r->uri()))
        ->values()
        ->all();

    expect($unprotected)->toBe([]);
});

it('bietet keine Selbstregistrierung an', function () {
    expect(Route::has('register'))->toBeFalse()
        ->and(Route::has('register.store'))->toBeFalse();
    test()->get('/register')->assertNotFound();
});

// ── Vereinsnutzer ─────────────────────────────────────────────────────────────

it('verweigert Vereinsnutzern Pflege, Importe und Berechnungen', function () {
    $club = makeClub_p5();
    $meet = makeMeet_p5();
    $athlete = makeAthlete_p5($club);
    $user = clubUser_acc($club);

    $forbidden = [
        ['get', route('lenex.import')],
        ['post', route('lenex.import.store')],
        ['post', route('clubs.store')],
        ['put', route('clubs.update', $club)],
        ['delete', route('clubs.destroy', $club)],
        ['post', route('nations.store')],
        ['get', route('athletes.create')],
        ['put', route('athletes.update', $athlete)],
        ['post', route('athletes.transfer-club', $athlete)],
        ['get', route('records.import')],
        ['post', route('records.import.run')],
        ['post', route('records.check', $meet)],
        ['get', route('base-times.import')],
        ['get', route('meets.create')],
        ['put', route('meets.update', $meet)],
        ['delete', route('meets.destroy', $meet)],
        ['get', route('meets.events.create', $meet)],
        ['post', route('meets.recalculate-points', $meet)],
        ['post', route('meets.wps-points.recalculate', $meet)],
        ['get', route('meets.sessions.edit', $meet)],
        ['get', route('qualifying-time-lists.create')],
        ['get', route('classifiers.create')],
    ];

    foreach ($forbidden as [$method, $url]) {
        test()->actingAs($user)->{$method}($url)->assertForbidden();
    }

    expect(Club::find($club->id))->not->toBeNull()
        ->and(Meet::find($meet->id))->not->toBeNull();
});

it('lässt Vereinsnutzer weiterhin lesen und eigene Meldungen öffnen', function () {
    $club = makeClub_p5();
    $meet = makeMeet_p5();
    $athlete = makeAthlete_p5($club);
    $user = clubUser_acc($club);

    foreach ([
        route('meets.index'), route('meets.show', $meet), route('clubs.index'), route('clubs.show', $club),
        route('athletes.index'), route('athletes.show', $athlete), route('nations.index'), route('records.index'),
        route('classifiers.index'), route('qualifying-time-lists.index'), route('lenex.export'), route('records.export'),
        route('club-entries.pick-meet'),
    ] as $url) {
        test()->actingAs($user)->get($url)->assertOk();
    }
});

it('blendet Schreib-Schaltflächen und Import-Menüpunkte für Vereinsnutzer aus', function () {
    $club = makeClub_p5();
    $meet = makeMeet_p5();
    $user = clubUser_acc($club);

    test()->actingAs($user)->get(route('meets.show', $meet))
        ->assertOk()
        ->assertDontSee(route('meets.edit', $meet))
        ->assertDontSee(route('meets.events.create', $meet))
        ->assertDontSee(route('lenex.import'))
        ->assertDontSee(route('records.import'))
        ->assertSee(route('lenex.export'));

    test()->actingAs($user)->get(route('clubs.show', $club))
        ->assertOk()
        ->assertDontSee(route('clubs.edit', $club));
});

// ── Admins ────────────────────────────────────────────────────────────────────

it('erreicht als Admin alle Anlege-Formulare (Routen-Reihenfolge vor {id}-Routen)', function () {
    $meet = makeMeet_p5();
    makeStrokeType_p5();
    $admin = admin_acc();

    foreach ([
        route('athletes.create'), route('clubs.create'), route('classifiers.create'), route('nations.create'),
        route('meets.create'), route('records.create'), route('qualifying-time-lists.create'),
        route('meets.events.create', $meet), route('lenex.import'), route('records.import'),
    ] as $url) {
        test()->actingAs($admin)->get($url)->assertOk();
    }

    test()->actingAs($admin)->get(route('athletes.show', Athlete::create([
        'first_name' => 'Max', 'last_name' => 'Muster', 'gender' => 'M', 'nation_id' => makeNation_p5()->id,
    ])))->assertOk();
});
