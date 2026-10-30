<?php

use App\Enums\AccessScope;
use App\Enums\Module;
use App\Enums\RoleTemplate;
use App\Models\Athlete;
use App\Models\AuditLog;
use App\Models\Club;
use App\Models\User;
use App\Services\PermissionTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('club-permissions-p5');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

/** Vereinsnutzer mit der Vorlage "Verein" (Athleten lesen + schreiben eigener Verein). */
function clubUser_cp5(Club $club): User
{
    return User::factory()->create(['club_id' => $club->id, 'name' => 'Vereinswart']);
}

/**
 * Nicht-Admin mit Athleten lesen "eigener Verein", sonst nichts.
 *
 * @throws Throwable
 */
function readerUser_cp5(Club $club): User
{
    $user = User::factory()->create(['club_id' => $club->id, 'role_template' => RoleTemplate::Empty]);
    app(PermissionTemplateService::class)->setPermissions($user, [
        Module::Athletes->value => ['read' => AccessScope::Own, 'write' => AccessScope::None],
    ]);

    return $user->fresh();
}

/** @param  array<string, mixed>  $overrides */
function payload_cp5(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Lena',
        'last_name' => 'Neuling',
        'gender' => 'F',
        'birth_date' => '2010-04-05',
        'nation_id' => makeNation_p5()->id,
        'is_active' => '1',
        'phone' => '0664 555',
    ], $overrides);
}

// ── Anlegen ───────────────────────────────────────────────────────────────────

it('lässt einen Verein Athleten anlegen, fest im eigenen Verein und ohne SDMS ID und Level', function () {
    $club = makeClub_p5();
    $other = makeClub_p5();

    $this->actingAs(clubUser_cp5($club))
        ->post(route('athletes.store'), payload_cp5([
            'club_id' => $other->id,
            'license_ipc' => '12345',
            'level' => 'A',
        ]))
        ->assertRedirect()
        ->assertSessionHas('success', 'Athlet angelegt. Die Klassifikation trägt der Verband ein.');

    $athlete = Athlete::where('last_name', 'Neuling')->sole();

    expect($athlete->club_id)->toBe($club->id)
        ->and($athlete->license_ipc)->toBeNull()
        ->and($athlete->level)->toBeNull()
        ->and($athlete->phone)->toBe('0664 555')
        ->and($athlete->clubHistory()->where('club_id', $club->id)->exists())->toBeTrue()
        ->and(AuditLog::where('auditable_type', Athlete::class)->where('auditable_id', $athlete->id)->where('event', 'created')->value('user_name'))->toBe('Vereinswart');
});

it('warnt einen Verein vor einer möglichen Dublette, ohne deren Daten zu zeigen', /** @throws JsonException */ function () {
    $club = makeClub_p5();
    $foreignClub = makeClub_p5();
    Athlete::create([
        'first_name' => 'Lena', 'last_name' => 'Neuling', 'gender' => 'F', 'birth_date' => '2010-01-01',
        'nation_id' => makeNation_p5()->id, 'club_id' => $foreignClub->id,
    ]);
    $user = clubUser_cp5($club);

    $this->actingAs($user)->from(route('athletes.create'))
        ->post(route('athletes.store'), payload_cp5())
        ->assertRedirect(route('athletes.create'))
        ->assertSessionHasErrors('duplicate');

    $this->actingAs($user)->from(route('athletes.create'))->followingRedirects()
        ->post(route('athletes.store'), payload_cp5())
        ->assertOk()
        ->assertSee('Trotzdem anlegen')
        ->assertDontSee($foreignClub->name);

    expect(Athlete::where('last_name', 'Neuling')->count())->toBe(1);

    $this->actingAs($user)
        ->post(route('athletes.store'), payload_cp5(['confirm_duplicate' => '1']))
        ->assertSessionHasNoErrors();

    expect(Athlete::where('last_name', 'Neuling')->count())->toBe(2);
});

it('warnt Admins nicht vor Dubletten und lässt Verein, SDMS ID und Level zu', /** @throws JsonException */ function () {
    $club = makeClub_p5();
    Athlete::create([
        'first_name' => 'Lena', 'last_name' => 'Neuling', 'gender' => 'F', 'birth_date' => '2010-01-01',
        'nation_id' => makeNation_p5()->id,
    ]);

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->post(route('athletes.store'), payload_cp5(['club_id' => $club->id, 'license_ipc' => '777', 'level' => 'B']))
        ->assertSessionHasNoErrors();

    $athlete = Athlete::where('license_ipc', '777')->sole();

    expect($athlete->club_id)->toBe($club->id)
        ->and($athlete->level)->toBe('B');
});

// ── Bearbeiten ────────────────────────────────────────────────────────────────

it('lässt einen Verein eigene Athleten bearbeiten, Name und Verein bleiben', function () {
    $club = makeClub_p5();
    $other = makeClub_p5();
    $athlete = makeAthlete_p5($club);
    $athlete->update(['license_ipc' => 'IPC1', 'level' => 'A']);

    $this->actingAs(clubUser_cp5($club))
        ->put(route('athletes.update', $athlete), payload_cp5([
            'first_name' => 'Anders',
            'last_name' => 'Name',
            'name_prefix' => 'van',
            'club_id' => $other->id,
            'license_ipc' => 'IPC2',
            'level' => 'B',
            'gender' => 'M',
            'email' => 'neu@example.test',
        ]))
        ->assertRedirect(route('athletes.show', $athlete));

    $athlete->refresh();

    expect($athlete->first_name)->toBe('Max')
        ->and($athlete->last_name)->toStartWith('Muster')
        ->and($athlete->name_prefix)->toBeNull()
        ->and($athlete->club_id)->toBe($club->id)
        ->and($athlete->license_ipc)->toBe('IPC1')
        ->and($athlete->level)->toBe('A')
        ->and($athlete->email)->toBe('neu@example.test')
        ->and($athlete->birth_date->format('Y-m-d'))->toBe('2010-04-05');
});

it('zeigt gesperrte Felder im Formular schreibgeschützt mit Hinweis', function () {
    $club = makeClub_p5();
    $athlete = makeAthlete_p5($club);

    $this->actingAs(clubUser_cp5($club))
        ->get(route('athletes.edit', $athlete))
        ->assertOk()
        ->assertSee('Änderung per Antrag')
        ->assertSee('nur Verband')
        ->assertDontSee('name="club_id"', false);

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get(route('athletes.edit', $athlete))
        ->assertOk()
        ->assertDontSee('Änderung per Antrag')
        ->assertSee('name="club_id"', false);
});

it('verweigert fremde Athleten, reinen Lesern und den Admin-Funktionen', /** @throws Throwable */ function () {
    $club = makeClub_p5();
    $own = makeAthlete_p5($club);
    $foreign = makeAthlete_p5(makeClub_p5());
    $user = clubUser_cp5($club);
    $reader = readerUser_cp5($club);

    $this->actingAs($user)->get(route('athletes.edit', $foreign))->assertForbidden();
    $this->actingAs($user)->put(route('athletes.update', $foreign), payload_cp5())->assertForbidden();
    $this->actingAs($user)->delete(route('athletes.destroy', $own))->assertForbidden();
    $this->actingAs($user)->post(route('athletes.transfer-club', $own))->assertForbidden();
    $this->actingAs($user)->get(route('athletes.merge.create', $own))->assertForbidden();
    $this->actingAs($reader)->get(route('athletes.create'))->assertForbidden();
    $this->actingAs($reader)->get(route('athletes.edit', $own))->assertForbidden();

    expect(Athlete::find($own->id))->not->toBeNull();
});

// ── Schaltflächen ─────────────────────────────────────────────────────────────

it('zeigt Vereinen Anlegen und Bearbeiten, aber keine Admin-Schaltflächen', function () {
    $club = makeClub_p5();
    $athlete = makeAthlete_p5($club);
    $user = clubUser_cp5($club);

    $this->actingAs($user)->get(route('athletes.index'))
        ->assertOk()
        ->assertSee(route('athletes.create'))
        ->assertSee(route('athletes.edit', $athlete))
        ->assertDontSee('name="_method" value="DELETE"', false)
        ->assertDontSee(route('athletes.duplicates.index'));

    $this->actingAs($user)->get(route('athletes.show', $athlete))
        ->assertOk()
        ->assertSee(route('athletes.edit', $athlete))
        ->assertDontSee(route('athletes.merge.create', $athlete));
});

it('zeigt "Noch nicht klassifiziert" bei Athleten ohne Sportklasse', function () {
    $club = makeClub_p5();
    $athlete = makeAthlete_p5($club, 'M', []);

    $this->actingAs(clubUser_cp5($club))->get(route('athletes.show', $athlete))
        ->assertOk()
        ->assertSee('Noch nicht klassifiziert');
    $this->actingAs(clubUser_cp5($club))->get(route('athletes.index'))
        ->assertOk()
        ->assertSee('Noch nicht klassifiziert');
});
