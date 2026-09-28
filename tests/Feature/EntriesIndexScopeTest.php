<?php

use App\Models\Club;
use App\Models\Entry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('entries-index-scope');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php):
// makeMeet_p5(), makeClub_p5(), makeAthlete_p5(), makeEvent_p5().

function eis_admin(): User
{
    return User::firstWhere('email', 'eis_admin@example.test')
        ?? User::forceCreate([
            'name' => 'Admin EIS',
            'email' => 'eis_admin@example.test',
            'password' => bcrypt('secret'),
            'is_admin' => true,
        ]);
}

function eis_clubUser(Club $club): User
{
    return User::firstWhere('email', 'eis_club@example.test')
        ?? User::forceCreate([
            'name' => 'Club EIS',
            'email' => 'eis_club@example.test',
            'password' => bcrypt('secret'),
            'is_admin' => false,
            'club_id' => $club->id,
        ]);
}

function eis_entry(object $meet, object $event, Club $club): Entry
{
    return Entry::create([
        'meet_id' => $meet->id,
        'swim_event_id' => $event->id,
        'athlete_id' => makeAthlete_p5($club)->id,
        'club_id' => $club->id,
    ]);
}

it('zeigt Admins alle Meldungen, Vereinen nur die eigenen', function () {
    $meet = makeMeet_p5();
    $event = makeEvent_p5($meet);
    $clubA = makeClub_p5();
    $clubB = makeClub_p5();
    $entryA = eis_entry($meet, $event, $clubA);
    $entryB = eis_entry($meet, $event, $clubB);

    $nameA = $entryA->athlete->display_name;
    $nameB = $entryB->athlete->display_name;

    $this->actingAs(eis_admin())->get(route('entries.index'))
        ->assertOk()
        ->assertSee($nameA)
        ->assertSee($nameB);

    $this->actingAs(eis_clubUser($clubA))->get(route('entries.index'))
        ->assertOk()
        ->assertSee($nameA)
        ->assertDontSee($nameB);
});

it('macht Anlegen/Bearbeiten/Löschen von Meldungen admin-only', function () {
    $meet = makeMeet_p5();
    $meet->update(['is_open' => true]);
    $event = makeEvent_p5($meet);
    $club = makeClub_p5();
    $entry = eis_entry($meet, $event, $club);
    $clubUser = eis_clubUser($club);

    // Vereins-User bekommt 403 auf der Admin-CRUD (auch für die eigene Meldung).
    $this->actingAs($clubUser)->get(route('meets.entries.create', $meet))->assertForbidden();
    $this->actingAs($clubUser)->get(route('entries.edit', $entry))->assertForbidden();
    $this->actingAs($clubUser)->delete(route('entries.destroy', $entry))->assertForbidden();

    // Admin darf.
    $this->actingAs(eis_admin())->get(route('meets.entries.create', $meet))->assertOk();
    $this->actingAs(eis_admin())->get(route('entries.edit', $entry))->assertOk();
});

it('blendet für Vereine die Bearbeiten-/Löschen-Aktionen in der Liste aus', function () {
    $meet = makeMeet_p5();
    $event = makeEvent_p5($meet);
    $club = makeClub_p5();
    $entry = eis_entry($meet, $event, $club);

    $this->actingAs(eis_clubUser($club))->get(route('entries.index'))
        ->assertOk()
        ->assertDontSee(route('entries.edit', $entry), false);

    $this->actingAs(eis_admin())->get(route('entries.index'))
        ->assertOk()
        ->assertSee(route('entries.edit', $entry), false);
});
