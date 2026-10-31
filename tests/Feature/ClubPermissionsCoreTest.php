<?php

use App\Enums\AccessScope;
use App\Enums\Module;
use App\Enums\RoleTemplate;
use App\Models\Club;
use App\Models\User;
use App\Models\UserModulePermission;
use App\Services\PermissionTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class)->group('club-permissions-p1');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function makeUser_cp1(?Club $club, bool $isAdmin = false): User
{
    static $i = 0;
    $i++;

    return User::create([
        'name' => 'Benutzer '.$i,
        'email' => "benutzer$i@example.test",
        'password' => 'password',
        'is_admin' => $isAdmin,
        'club_id' => $club?->id,
    ]);
}

function grant_cp1(User $user, Module $module, AccessScope $read, AccessScope $write): void
{
    UserModulePermission::create([
        'user_id' => $user->id,
        'module' => $module,
        'read_scope' => $read,
        'write_scope' => $write,
    ]);
    $user->unsetRelation('modulePermissions');
}

function service_cp1(): PermissionTemplateService
{
    return app(PermissionTemplateService::class);
}

// ── Rechte am Benutzer ────────────────────────────────────────────────────────

it('gibt Admins in jedem Modul Lese- und Schreibzugriff auf alle', function () {
    $admin = makeUser_cp1(null, true);

    foreach (Module::cases() as $module) {
        expect($admin->readScope($module))->toBe(AccessScope::All)
            ->and($admin->writeScope($module))->toBe(AccessScope::All)
            ->and(Gate::forUser($admin)->allows('module.write', $module))->toBeTrue();
    }
});

it('gibt Benutzern ohne gespeicherte Rechte keinen Zugriff', function () {
    $user = makeUser_cp1(makeClub_p5());

    expect($user->canRead(Module::Athletes))->toBeFalse()
        ->and($user->canWrite(Module::Athletes))->toBeFalse()
        ->and($user->readScope(Module::Meets))->toBe(AccessScope::None)
        ->and(Gate::forUser($user)->allows('module.read', Module::Athletes))->toBeFalse();
});

it('liefert die gespeicherten Bereiche je Modul', function () {
    $user = makeUser_cp1(makeClub_p5());
    grant_cp1($user, Module::Athletes, AccessScope::All, AccessScope::Own);

    expect($user->readScope(Module::Athletes))->toBe(AccessScope::All)
        ->and($user->writeScope(Module::Athletes))->toBe(AccessScope::Own)
        ->and($user->canWrite(Module::Athletes))->toBeTrue()
        ->and($user->canRead(Module::Clubs))->toBeFalse()
        ->and(Gate::forUser($user)->allows('module.read', Module::Athletes))->toBeTrue()
        ->and(Gate::forUser($user)->allows('module.write', Module::Athletes))->toBeTrue()
        ->and(Gate::forUser($user)->allows('module.write', Module::Clubs))->toBeFalse();
});

it('wertet "eigener Verein" ohne zugeordneten Verein als kein Zugriff', function () {
    $user = makeUser_cp1(null);
    grant_cp1($user, Module::Athletes, AccessScope::Own, AccessScope::Own);
    grant_cp1($user, Module::Meets, AccessScope::All, AccessScope::None);

    expect($user->readScope(Module::Athletes))->toBe(AccessScope::None)
        ->and($user->canWrite(Module::Athletes))->toBeFalse()
        ->and($user->readScope(Module::Meets))->toBe(AccessScope::All);
});

// ── Module und Vorlagen ───────────────────────────────────────────────────────

it('lässt Schreiben nicht weiter reichen als Lesen und nur erlaubte Bereiche zu', function () {
    expect(Module::Athletes->allows(AccessScope::All, AccessScope::Own))->toBeTrue()
        ->and(Module::Athletes->allows(AccessScope::Own, AccessScope::All))->toBeFalse()
        ->and(Module::Athletes->allows(AccessScope::None, AccessScope::Own))->toBeFalse()
        ->and(Module::Nations->allows(AccessScope::Own, AccessScope::None))->toBeFalse()
        ->and(Module::Results->allows(AccessScope::Own, AccessScope::Own))->toBeFalse()
        ->and(Module::Statistics->allows(AccessScope::All, AccessScope::All))->toBeFalse()
        ->and(Module::Statistics->allows(AccessScope::All, AccessScope::None))->toBeTrue();
});

it('enthält in jeder Vorlage nur gültige Module und Kombinationen', function () {
    foreach (RoleTemplate::cases() as $template) {
        foreach ($template->permissions() as $key => $scopes) {
            $module = Module::tryFrom($key);

            expect($module)->not->toBeNull()
                ->and($module->allows($scopes['read'], $scopes['write']))->toBeTrue();
        }
    }
});

// ── PermissionTemplateService ─────────────────────────────────────────────────

it('wendet eine Vorlage an und merkt sie am Benutzer', function () {
    $user = makeUser_cp1(makeClub_p5());
    grant_cp1($user, Module::Statistics, AccessScope::All, AccessScope::None);

    service_cp1()->applyTemplate($user, RoleTemplate::Coach);

    expect($user->fresh()->role_template)->toBe(RoleTemplate::Coach)
        ->and($user->writeScope(Module::Entries))->toBe(AccessScope::Own)
        ->and($user->readScope(Module::Meets))->toBe(AccessScope::All)
        ->and($user->canRead(Module::Statistics))->toBeFalse()
        ->and(UserModulePermission::where('user_id', $user->id)->count())->toBe(2);
});

it('speichert für die Vorlage "Ohne Rechte" keine Zeilen', function () {
    $user = makeUser_cp1(makeClub_p5());
    service_cp1()->applyTemplate($user, RoleTemplate::Club);

    service_cp1()->applyTemplate($user, RoleTemplate::Empty);

    expect(UserModulePermission::where('user_id', $user->id)->count())->toBe(0)
        ->and($user->fresh()->role_template)->toBe(RoleTemplate::Empty);
});

it('wendet eine Vorlage für alle Nicht-Admins mit dieser Vorlage neu an', function () {
    $club = makeClub_p5();
    $first = makeUser_cp1($club);
    $second = makeUser_cp1($club);
    $other = makeUser_cp1($club);
    $admin = makeUser_cp1(null, true);

    service_cp1()->applyTemplate($first, RoleTemplate::Coach);
    service_cp1()->applyTemplate($second, RoleTemplate::Coach);
    service_cp1()->applyTemplate($other, RoleTemplate::Club);
    $admin->update(['role_template' => RoleTemplate::Coach]);

    // Einzelne Abweichung, die beim Neu-Anwenden verloren geht.
    service_cp1()->setPermissions($first, [
        Module::Entries->value => ['read' => AccessScope::Own, 'write' => AccessScope::None],
    ]);

    $count = service_cp1()->reapplyTemplate(RoleTemplate::Coach);

    expect($count)->toBe(2)
        ->and($first->fresh()->writeScope(Module::Entries))->toBe(AccessScope::Own)
        ->and($first->fresh()->readScope(Module::Meets))->toBe(AccessScope::All)
        ->and($other->fresh()->canWrite(Module::Athletes))->toBeTrue()
        ->and(UserModulePermission::where('user_id', $admin->id)->count())->toBe(0);
});

it('setzt die Matrix einzeln und behält die gemerkte Vorlage', function () {
    $user = makeUser_cp1(makeClub_p5());
    service_cp1()->applyTemplate($user, RoleTemplate::Club);

    service_cp1()->setPermissions($user, [
        Module::Athletes->value => ['read' => AccessScope::All, 'write' => AccessScope::Own],
        Module::Statistics->value => ['read' => AccessScope::All, 'write' => AccessScope::None],
        Module::Records->value => ['read' => AccessScope::None, 'write' => AccessScope::None],
    ]);

    expect($user->readScope(Module::Athletes))->toBe(AccessScope::All)
        ->and($user->canRead(Module::Statistics))->toBeTrue()
        ->and($user->canRead(Module::Meets))->toBeFalse()
        ->and($user->fresh()->role_template)->toBe(RoleTemplate::Club)
        ->and(UserModulePermission::where('user_id', $user->id)->count())->toBe(2);
});

it('lehnt ungültige Matrix-Einträge ab und lässt die Rechte unverändert', function (array $matrix, bool $withClub) {
    $user = makeUser_cp1($withClub ? makeClub_p5() : null);
    grant_cp1($user, Module::Meets, AccessScope::All, AccessScope::None);

    expect(fn () => service_cp1()->setPermissions($user, $matrix))->toThrow(ValidationException::class)
        ->and(UserModulePermission::where('user_id', $user->id)->count())->toBe(1)
        ->and($user->fresh()->canRead(Module::Meets))->toBeTrue();
})->with([
    'Schreiben weiter als Lesen' => [
        [Module::Athletes->value => ['read' => AccessScope::Own, 'write' => AccessScope::All]], true,
    ],
    'Bereich im Modul nicht erlaubt' => [
        [Module::Nations->value => ['read' => AccessScope::Own, 'write' => AccessScope::None]], true,
    ],
    'unbekanntes Modul' => [
        ['gibt-es-nicht' => ['read' => AccessScope::All, 'write' => AccessScope::None]], true,
    ],
    'eigener Verein ohne Verein' => [
        [Module::Athletes->value => ['read' => AccessScope::Own, 'write' => AccessScope::None]], false,
    ],
]);

// ── Route-Middleware ──────────────────────────────────────────────────────────

it('schützt Routen mit der Middleware module:<modul>,<stufe>', function () {
    Route::middleware(['web', 'auth', 'module:athletes,write'])->get('/_test/cp1-write', fn () => 'ok');
    Route::middleware(['web', 'auth', 'module:athletes,read'])->get('/_test/cp1-read', fn () => 'ok');

    $reader = makeUser_cp1(makeClub_p5());
    grant_cp1($reader, Module::Athletes, AccessScope::Own, AccessScope::None);
    $nobody = makeUser_cp1(makeClub_p5());
    $admin = makeUser_cp1(null, true);

    test()->actingAs($reader)->get('/_test/cp1-read')->assertOk();
    test()->actingAs($reader)->get('/_test/cp1-write')->assertForbidden();
    test()->actingAs($nobody)->get('/_test/cp1-read')->assertForbidden();
    test()->actingAs($admin)->get('/_test/cp1-write')->assertOk();
});

it('meldet einen Tippfehler im Modulnamen der Middleware als Fehler statt still zu sperren', function () {
    Route::middleware(['web', 'auth', 'module:athlets,read'])->get('/_test/cp1-typo', fn () => 'ok');

    test()->withoutExceptionHandling();

    expect(fn () => test()->actingAs(makeUser_cp1(null, true))->get('/_test/cp1-typo'))
        ->toThrow(InvalidArgumentException::class);
});
