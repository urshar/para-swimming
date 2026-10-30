<?php

use App\Enums\Module;
use App\Enums\RoleTemplate;
use App\Http\Middleware\RequireAdmin;
use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class)->group('access-control');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function admin_acc(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

/** Vereinsbenutzer mit der Vorlage "Verein" (die UserFactory wendet sie an). */
function clubUser_acc(Club $club): User
{
    return User::factory()->create(['is_admin' => false, 'club_id' => $club->id]);
}

/**
 * Konto-Routen hinter dem Login, die weder ein Modul noch Admin brauchen (Profil, Sicherheit, Darstellung,
 * Abmelden, 2FA, E-Mail-Bestätigung).
 */
function isAccountRoute_acc(string $name): bool
{
    return (bool) preg_match(
        '/^(logout|password\.|two-factor\.|verification\.|profile\.|security\.|appearance\.|user-password\.|settings)/',
        $name
    );
}

/** Export-Downloads: per POST, aber lesend — dürfen mit "module:…,read" geschützt sein. */
function isExportDownload_acc(string $name): bool
{
    return in_array($name, ['lenex.export.download', 'records.export.download'], true);
}

/** Schreibt die Route (nicht GET/HEAD) oder zeigt sie ein Anlege-, Bearbeiten- oder Import-Formular? */
function isWriteRoute_acc(RoutingRoute $route): bool
{
    $name = (string) $route->getName();

    return array_diff($route->methods(), ['GET', 'HEAD']) !== []
        || preg_match('/\.(create|edit)$|\.import(\.|$)|\/import$/', $name.'|'.$route->uri()) === 1;
}

function isAdminOnly_acc(RoutingRoute $route): bool
{
    $middleware = $route->gatherMiddleware();

    return in_array(RequireAdmin::class, $middleware, true) || in_array('admin', $middleware, true);
}

/** @return list<string> "module:…"-Einträge der Route */
function moduleMiddleware_acc(RoutingRoute $route): array
{
    return array_values(array_filter(
        $route->gatherMiddleware(),
        fn ($m) => is_string($m) && str_starts_with($m, 'module:'),
    ));
}

/** @return Collection<int, RoutingRoute> */
function authRoutes_acc(): Collection
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $r) => in_array('auth', $r->gatherMiddleware(), true))
        ->reject(fn (RoutingRoute $r) => isAccountRoute_acc($r->getName() ?? $r->uri()));
}

function routeLabel_acc(RoutingRoute $route): string
{
    return implode('|', $route->methods()).' '.($route->getName() ?? $route->uri());
}

// ── Routen-Audit ──────────────────────────────────────────────────────────────

it('schützt jede Route hinter dem Login mit einem Modulrecht oder Admin, außer den Konto-Routen', function () {
    $unprotected = authRoutes_acc()
        ->reject(fn (RoutingRoute $r) => isAdminOnly_acc($r) || moduleMiddleware_acc($r) !== [])
        ->map(fn (RoutingRoute $r) => routeLabel_acc($r))
        ->values()
        ->all();

    expect($unprotected)->toBe([]);
});

it('verlangt für jede schreibende Route Schreibrecht oder Admin, außer den Export-Downloads', function () {
    $unprotected = authRoutes_acc()
        ->filter(fn (RoutingRoute $r) => isWriteRoute_acc($r))
        ->reject(fn (RoutingRoute $r) => isAdminOnly_acc($r))
        ->reject(fn (RoutingRoute $r) => collect(moduleMiddleware_acc($r))
            ->contains(fn (string $m) => preg_match('/^module:[a-z_]+,write(,all)?$/', $m) === 1))
        ->reject(fn (RoutingRoute $r) => isExportDownload_acc($r->getName() ?? ''))
        ->map(fn (RoutingRoute $r) => routeLabel_acc($r))
        ->values()
        ->all();

    expect($unprotected)->toBe([]);
});

it('nennt in "module:" nur bestehende Module, Stufen und Bereiche', function () {
    $invalid = authRoutes_acc()
        ->flatMap(fn (RoutingRoute $r) => moduleMiddleware_acc($r))
        ->unique()
        ->reject(function (string $m) {
            $parts = explode(',', substr($m, strlen('module:')));

            return Module::tryFrom($parts[0]) !== null
                && in_array($parts[1] ?? '', ['read', 'write'], true)
                && in_array($parts[2] ?? null, [null, 'all'], true)
                && count($parts) <= 3;
        })
        ->values()
        ->all();

    expect($invalid)->toBe([]);
});

it('lässt Benutzer ohne Rechte nur ins eigene Konto', function () {
    $club = makeClub_p5();
    $meet = makeMeet_p5();
    $user = User::factory()->withoutPermissions()->create(['club_id' => $club->id]);

    foreach ([
        route('meets.index'), route('meets.show', $meet), route('clubs.index'), route('athletes.index'),
        route('records.index'), route('lenex.export'), route('club-entries.pick-meet'), route('statistics.index'),
    ] as $url) {
        test()->actingAs($user)->get($url)->assertForbidden();
    }

    test()->actingAs($user)->get(route('profile.edit'))->assertOk();
});

it('lässt die Vorlage "Trainer" melden, aber keine Stammdaten ansehen', function () {
    $club = makeClub_p5();
    $user = User::factory()->create(['club_id' => $club->id, 'role_template' => RoleTemplate::Coach]);

    test()->actingAs($user)->get(route('meets.index'))->assertOk();
    test()->actingAs($user)->get(route('club-entries.pick-meet'))->assertOk();
    test()->actingAs($user)->get(route('athletes.index'))->assertForbidden();
    test()->actingAs($user)->get(route('records.index'))->assertForbidden();
});

it('verlangt für verbandsweite Ansichten den Bereich "alle"', function () {
    $club = makeClub_p5();
    $meet = makeMeet_p5();
    $user = clubUser_acc($club);

    // Vorlage "Verein": Meldungen lesen nur "eigener Verein".
    test()->actingAs($user)->get(route('entries.index'))->assertForbidden();
    test()->actingAs($user)->get(route('meets.entries-overview', $meet))->assertForbidden();
    test()->actingAs($user)->get(route('meets.entry-lists.sportpass.pdf', $meet))->assertForbidden();
    test()->actingAs($user)->get(route('meets.fees.index', $meet))->assertOk();
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
        // Den eigenen Verein bearbeiten darf die Vorlage "Verein" seit Phase 7 (ClubPermissionsClubsTest).
        ['put', route('clubs.update', makeClub_p5())],
        ['delete', route('clubs.destroy', $club)],
        ['post', route('nations.store')],
        // Eigene Athleten anlegen/bearbeiten darf die Vorlage "Verein" seit Phase 5 (ClubPermissionsAthletesTest).
        ['put', route('athletes.update', makeAthlete_p5(makeClub_p5()))],
        ['delete', route('athletes.destroy', $athlete)],
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

    $foreignClub = makeClub_p5();
    test()->actingAs($user)->get(route('clubs.show', $foreignClub))
        ->assertOk()
        ->assertDontSee(route('clubs.edit', $foreignClub));
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
