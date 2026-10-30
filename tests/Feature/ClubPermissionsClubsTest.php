<?php

use App\Enums\AccessScope;
use App\Enums\Module;
use App\Models\AuditLog;
use App\Models\Club;
use App\Models\Nation;
use App\Models\User;
use App\Services\LenexResolverService;
use App\Services\PermissionTemplateService;
use App\Support\AuditHistory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('club-permissions-p7');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

/** Vereinsnutzer mit der Vorlage "Verein" (Vereine schreiben eigener Verein). */
function clubUser_cp7(Club $club): User
{
    return User::factory()->create(['club_id' => $club->id]);
}

function admin_cp7(): User
{
    return User::factory()->create(['is_admin' => true]);
}

function otherNation_cp7(): Nation
{
    return Nation::firstOrCreate(['code' => 'GER'], ['name_de' => 'Deutschland', 'name_en' => 'Germany', 'is_active' => true]);
}

/** @return array<string, mixed> */
function clubPayload_cp7(Club $club, array $overrides = []): array
{
    return array_merge([
        'name' => $club->name,
        'short_name' => $club->short_name,
        'code' => $club->code,
        'nation_id' => $club->nation_id,
        'type' => $club->fresh()->type,
    ], $overrides);
}

// ── Eigener Verein ────────────────────────────────────────────────────────────

it('lässt einen Verein Name, Code und Kontakt pflegen, Verbandsfelder bleiben', function () {
    $club = makeClub_p5();
    $user = clubUser_cp7($club);

    $this->actingAs($user)->get(route('clubs.edit', $club))
        ->assertOk()
        ->assertSee('nur Verband')
        ->assertDontSee('name="nation_id"', false);

    $this->actingAs($user)->put(route('clubs.update', $club), clubPayload_cp7($club, [
        'name' => 'Neuer Name',
        'code' => ' nv01 ',
        'contact_name' => 'Eva Kontakt',
        'contact_email' => 'eva@example.org',
        'address_street' => 'Hauptstraße 1',
        'address_zip' => '1010',
        'address_city' => 'Wien',
        'address_country' => 'AUT',
        'nation_id' => otherNation_cp7()->id,
        'type' => 'VERBAND',
        'regional_association' => 'WBSV',
        'swrid' => '12345',
    ]))->assertRedirect(route('clubs.show', $club));

    $club->refresh();
    expect($club->name)->toBe('Neuer Name')
        ->and($club->code)->toBe('NV01')
        ->and($club->contact_name)->toBe('Eva Kontakt')
        ->and($club->contact_email)->toBe('eva@example.org')
        ->and($club->address_city)->toBe('Wien')
        ->and($club->address_country)->toBe('AUT')
        ->and($club->nation_id)->toBe(makeNation_p5()->id)
        ->and($club->type)->toBe('CLUB')
        ->and($club->regional_association)->toBeNull()
        ->and($club->swrid)->toBeNull();
});

it('verweigert das Bearbeiten fremder Vereine und reinen Lesern', /** @throws Throwable */ function () {
    $club = makeClub_p5();
    $foreign = makeClub_p5();
    $user = clubUser_cp7($club);

    $this->actingAs($user)->get(route('clubs.edit', $foreign))->assertForbidden();
    $this->actingAs($user)->put(route('clubs.update', $foreign), clubPayload_cp7($foreign, ['name' => 'X']))
        ->assertForbidden();
    expect($foreign->fresh()->name)->not->toBe('X');

    $reader = User::factory()->create(['club_id' => $club->id]);
    app(PermissionTemplateService::class)->setPermissions($reader, [
        Module::Clubs->value => ['read' => AccessScope::All, 'write' => AccessScope::None],
    ]);
    $this->actingAs($reader->fresh())->get(route('clubs.edit', $club))->assertForbidden();
});

it('lässt Admins auch Nation, Typ, Landesverband und SWRID ändern', function () {
    $club = makeClub_p5();

    $this->actingAs(admin_cp7())->put(route('clubs.update', $club), clubPayload_cp7($club, [
        'nation_id' => otherNation_cp7()->id,
        'type' => 'VERBAND',
        'regional_association' => 'WBSV',
        'swrid' => '12345',
    ]))->assertRedirect(route('clubs.show', $club));

    $club->refresh();
    expect($club->nation_id)->toBe(otherNation_cp7()->id)
        ->and($club->type)->toBe('VERBAND')
        ->and($club->regional_association)->toBe('WBSV')
        ->and($club->swrid)->toBe('12345');
});

// ── Code eindeutig je Nation ──────────────────────────────────────────────────

it('verlangt einen je Nation eindeutigen Code, auch gegenüber gelöschten Vereinen', /** @throws Throwable */ function () {
    $taken = makeClub_p5();
    $club = makeClub_p5();
    $admin = admin_cp7();

    $this->actingAs($admin)->put(route('clubs.update', $club), clubPayload_cp7($club, ['code' => strtolower($taken->code)]))
        ->assertSessionHasErrors(['code' => 'Der Code ist in dieser Nation schon an "'.$taken->name.'" vergeben.']);

    $this->actingAs($admin)->post(route('clubs.store'), [
        'name' => 'Ausland', 'code' => $taken->code, 'nation_id' => otherNation_cp7()->id, 'type' => 'CLUB',
    ])->assertSessionHasNoErrors();

    $deleted = Club::create(['name' => 'Alter Verein', 'code' => 'ALT1', 'nation_id' => makeNation_p5()->id]);
    $deleted->delete();
    $this->actingAs($admin)->put(route('clubs.update', $club), clubPayload_cp7($club, ['code' => 'ALT1']))
        ->assertSessionHasErrors(['code' => 'Der Code ist in dieser Nation schon an "Alter Verein" (gelöscht) vergeben.']);

    // Der eigene Code bleibt beim Speichern gültig.
    $this->actingAs($admin)->put(route('clubs.update', $club), clubPayload_cp7($club))->assertSessionHasNoErrors();
});

it('sichert die Eindeutigkeit zusätzlich über den Datenbank-Index ab, ohne Code mehrfach erlaubt', function () {
    $nationId = makeNation_p5()->id;
    Club::create(['name' => 'Ohne Code 1', 'nation_id' => $nationId]);
    Club::create(['name' => 'Ohne Code 2', 'code' => '', 'nation_id' => $nationId]);
    Club::create(['name' => 'Mit Code', 'code' => 'DUP', 'nation_id' => $nationId]);

    expect(Club::whereNull('code')->count())->toBe(2)
        ->and(fn () => Club::create(['name' => 'Doppelt', 'code' => 'dup', 'nation_id' => $nationId]))
        ->toThrow(QueryException::class);
});

it('legt einen Verein beim LENEX-Import ohne Code an, wenn der Code schon vergeben ist', function () {
    $taken = makeClub_p5();
    $resolver = app(LenexResolverService::class);

    $club = $resolver->createClub(['name' => 'Importverein', 'code' => $taken->code, 'nation_id' => $taken->nation_id]);
    $other = $resolver->createClub(['name' => 'Freier Code', 'code' => 'FREI1', 'nation_id' => $taken->nation_id]);

    expect($club->code)->toBeNull()
        ->and($other->code)->toBe('FREI1')
        ->and($resolver->getClubsWithoutCode())->toBe(['Importverein'])
        ->and(Club::withoutCodeNote(['Importverein']))
        ->toBe(' Ohne Code angelegt, weil der Code in der Nation schon vergeben ist: Importverein.')
        ->and(Club::withoutCodeNote([]))->toBe('');
});

// ── Anzeige ───────────────────────────────────────────────────────────────────

it('zeigt die Kontaktdaten nur Admins und dem eigenen Verein', function () {
    $club = makeClub_p5();
    $club->update(['contact_name' => 'Eva Kontakt', 'contact_email' => 'eva@example.org']);

    $this->actingAs(admin_cp7())->get(route('clubs.show', $club))->assertSee('Eva Kontakt')->assertSee('eva@example.org');
    $this->actingAs(clubUser_cp7($club))->get(route('clubs.show', $club))->assertSee('Eva Kontakt');
    $this->actingAs(clubUser_cp7(makeClub_p5()))->get(route('clubs.show', $club))
        ->assertOk()
        ->assertDontSee('Eva Kontakt')
        ->assertDontSee('eva@example.org');
});

it('führt Vereinsnutzer über "Mein Verein" auf die eigene Vereinsseite', function () {
    $club = makeClub_p5();
    $user = clubUser_cp7($club);

    $this->actingAs($user)->get(route('clubs.index'))
        ->assertSee('Mein Verein')
        ->assertSee(route('clubs.show', $club));
    $this->actingAs(admin_cp7())->get(route('clubs.index'))->assertDontSee('Mein Verein');
});

it('protokolliert Änderungen der Kontaktdaten mit lesbarer Bezeichnung', function () {
    $club = makeClub_p5();

    $this->actingAs(clubUser_cp7($club))->put(route('clubs.update', $club), clubPayload_cp7($club, [
        'contact_name' => 'Eva Kontakt',
    ]));

    $history = AuditHistory::build(AuditLog::forClub($club), true);
    $labels = collect($history['entries'])->pluck('changes')->flatten(1)->pluck('label');

    expect($labels)->toContain('Kontaktperson');
});
