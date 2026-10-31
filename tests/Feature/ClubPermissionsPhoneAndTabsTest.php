<?php

use App\Enums\AccessScope;
use App\Enums\Module;
use App\Models\Club;
use App\Models\Coach;
use App\Models\User;
use App\Services\PermissionTemplateService;
use App\Support\ListUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('club-permissions-p10b');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

/** Vereinsnutzer mit der Vorlage "Verein" (Verein und Trainer schreiben eigener Verein). */
function clubUser_cp10b(Club $club): User
{
    return User::factory()->create(['club_id' => $club->id]);
}

/** @return array<string, mixed> */
function clubPayload_cp10b(Club $club, array $overrides): array
{
    return array_merge([
        'name' => $club->name, 'short_name' => $club->short_name, 'code' => $club->code,
        'nation_id' => $club->nation_id, 'type' => $club->fresh()->type,
    ], $overrides);
}

// ── Telefonnummer ─────────────────────────────────────────────────────────────

it('speichert die Telefonnummer des Vereins und zeigt sie nur Admin und eigenem Verein', /** @throws Throwable */ function () {
    $club = makeClub_p5();
    $user = clubUser_cp10b($club);

    $this->actingAs($user)->put(route('clubs.update', $club), clubPayload_cp10b($club, ['contact_phone' => 'abc']))
        ->assertSessionHasErrors('contact_phone');
    $this->actingAs($user)->put(route('clubs.update', $club), clubPayload_cp10b($club, ['contact_phone' => '+43 (1) 234-56/78']))
        ->assertSessionHasNoErrors();

    expect($club->fresh()->contact_phone)->toBe('+43 (1) 234-56/78');

    $this->actingAs($user)->get(route('clubs.show', $club))
        ->assertSee('+43 (1) 234-56/78')
        ->assertSee('href="tel:+4312345678"', false);
    $this->actingAs(clubUser_cp10b(makeClub_p5()))->get(route('clubs.show', $club))
        ->assertOk()
        ->assertDontSee('+43 (1) 234-56/78');
});

it('speichert die Telefonnummer eines Trainers', /** @throws Throwable */ function () {
    $club = makeClub_p5();
    $user = clubUser_cp10b($club);

    $this->actingAs($user)->post(route('coaches.store'), [
        'first_name' => 'Tina', 'last_name' => 'Telefon', 'phone' => '0664 123 45 67', 'is_active' => '1',
    ])->assertSessionHasNoErrors();

    $coach = Coach::sole();
    expect($coach->phone)->toBe('0664 123 45 67');

    $this->actingAs($user)->get(route('coaches.show', $coach))->assertSee('href="tel:06641234567"', false);
    $this->actingAs($user)->get(route('clubs.show', [$club, 'reiter' => 'trainer']))->assertSee('0664 123 45 67');
});

// ── Reiter auf der Vereinsseite ───────────────────────────────────────────────

it('zeigt Athleten und Trainer in getrennten Reitern mit der Anlegen-Schaltfläche oben', function () {
    $club = makeClub_p5();
    makeAthlete_p5($club)->update(['last_name' => 'Reiterathlet']);
    Coach::create(['club_id' => $club->id, 'first_name' => 'Tina', 'last_name' => 'Reitertrainer', 'is_active' => true]);
    $user = clubUser_cp10b($club);

    $this->actingAs($user)->get(route('clubs.show', $club))
        ->assertOk()
        ->assertSee('Athleten (1)')
        ->assertSee('Trainer (1)')
        ->assertSee('Reiterathlet')
        ->assertDontSee('Reitertrainer')
        ->assertDontSee(route('coaches.create', ['club_id' => $club->id]));

    $this->actingAs($user)->get(route('clubs.show', [$club, 'reiter' => 'trainer']))
        ->assertOk()
        ->assertSee('Reitertrainer')
        ->assertDontSee('Reiterathlet')
        ->assertSee(route('coaches.create', ['club_id' => $club->id]));
});

it('zeigt den Trainer-Reiter nur mit Leserecht für die Trainer des Vereins', function () {
    $club = makeClub_p5();
    $reader = User::factory()->create(['club_id' => $club->id]);
    app(PermissionTemplateService::class)->setPermissions($reader, [
        Module::Clubs->value => ['read' => AccessScope::All, 'write' => AccessScope::None],
    ]);

    // Ohne Trainer-Recht fällt "?reiter=trainer" auf die Athleten zurück.
    $this->actingAs($reader->fresh())->get(route('clubs.show', [$club, 'reiter' => 'trainer']))
        ->assertOk()
        ->assertDontSee('Trainer (')
        ->assertSee('Die Athleten dieses Vereins sind für Sie nicht sichtbar.');
});

it('führt von Trainer-Detailseite und -Formular zurück auf den Trainer-Reiter', /** @throws Throwable */ function () {
    $club = makeClub_p5();
    $coach = Coach::create(['club_id' => $club->id, 'first_name' => 'Tina', 'last_name' => 'Zurueck', 'is_active' => true]);
    $user = clubUser_cp10b($club);
    $tabUrl = route('clubs.show', [$club, 'reiter' => 'trainer']);

    $this->actingAs($user)->get($tabUrl)->assertOk();

    expect(ListUrl::to('coaches'))->toBe($tabUrl);
    $this->actingAs($user)->get(route('coaches.show', $coach))->assertSee('href="'.$tabUrl.'"', false);
    $this->actingAs($user)->get(route('coaches.create'))->assertSee('href="'.$tabUrl.'"', false);
});
