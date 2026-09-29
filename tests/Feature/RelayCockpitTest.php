<?php

use App\Models\Club;
use App\Models\RelayEntry;
use App\Models\RelayEntryMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('entries-cockpit');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

function rct_admin(): User
{
    return User::firstWhere('email', 'rct_admin@example.test')
        ?? User::forceCreate([
            'name' => 'Admin RCT',
            'email' => 'rct_admin@example.test',
            'password' => bcrypt('secret'),
            'is_admin' => true,
        ]);
}

function rct_clubUser(Club $club): User
{
    return User::firstWhere('email', 'rct_club@example.test')
        ?? User::forceCreate([
            'name' => 'Club RCT',
            'email' => 'rct_club@example.test',
            'password' => bcrypt('secret'),
            'is_admin' => false,
            'club_id' => $club->id,
        ]);
}

/**
 * Legt eine Staffelmeldung mit $members Mitgliedern an. $attrs überschreibt die
 * Defaults (status, entry_time). Der Status-Default 'pending' kommt vom Model.
 */
function rct_relay(object $meet, object $event, Club $club, array $attrs = [], int $members = 0): RelayEntry
{
    $relay = RelayEntry::create(array_merge([
        'meet_id' => $meet->id,
        'swim_event_id' => $event->id,
        'club_id' => $club->id,
    ], $attrs));

    foreach (range(1, $members) as $position) {
        RelayEntryMember::create([
            'relay_entry_id' => $relay->id,
            'athlete_id' => makeAthlete_p5($club)->id,
            'position' => $position,
        ]);
    }

    return $relay;
}

it('zeigt das Staffel-Cockpit nur Admins und blendet die Tabs ein', function () {
    $club = makeClub_p5();

    $this->actingAs(rct_clubUser($club))->get(route('relay-entries.index'))->assertForbidden();

    $this->actingAs(rct_admin())->get(route('relay-entries.index'))
        ->assertOk()
        ->assertSee('Staffel')
        ->assertSee(route('entries.index'), false);
});

it('filtert das Staffel-Cockpit nach Status und Problemen', function () {
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $event = makeEvent_p5($meet, ['relay_count' => 4]);

    rct_relay($meet, $event, $club, ['status' => 'pending', 'entry_time' => 12000], 4);
    rct_relay($meet, $event, $club, ['status' => 'confirmed', 'entry_time' => 12000], 4);
    rct_relay($meet, $event, $club, ['status' => 'pending', 'entry_time' => 12000], 2); // unvollständig
    rct_relay($meet, $event, $club, ['status' => 'pending', 'entry_time' => null], 4);  // ohne Meldezeit

    $admin = rct_admin();

    $this->actingAs($admin)->get(route('relay-entries.index', ['status' => 'confirmed']))
        ->assertOk()->assertViewHas('relayEntries', fn ($p) => $p->total() === 1);

    $this->actingAs($admin)->get(route('relay-entries.index', ['problem' => 'incomplete']))
        ->assertOk()->assertViewHas('relayEntries', fn ($p) => $p->total() === 1);

    $this->actingAs($admin)->get(route('relay-entries.index', ['problem' => 'no_time']))
        ->assertOk()->assertViewHas('relayEntries', fn ($p) => $p->total() === 1);
});

it('berechnet die Kennzahlen des Staffel-Cockpits', function () {
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $event = makeEvent_p5($meet, ['relay_count' => 4]);

    rct_relay($meet, $event, $club, ['status' => 'pending', 'entry_time' => 12000], 4);
    rct_relay($meet, $event, $club, ['status' => 'confirmed', 'entry_time' => 12000], 4);
    rct_relay($meet, $event, $club, ['status' => 'pending', 'entry_time' => 12000], 2);
    rct_relay($meet, $event, $club, ['status' => 'pending', 'entry_time' => null], 4);

    $this->actingAs(rct_admin())->get(route('relay-entries.index'))
        ->assertOk()
        ->assertViewHas('counts', fn ($c) => $c['total'] === 4 && $c['pending'] === 3
            && $c['confirmed'] === 1 && $c['incomplete'] === 1 && $c['no_time'] === 1);
});

it('sucht im Staffel-Cockpit nach Verein', function () {
    $meet = makeMeet_p5();
    $event = makeEvent_p5($meet, ['relay_count' => 4]);
    $clubA = makeClub_p5();
    $clubB = makeClub_p5();
    rct_relay($meet, $event, $clubA, [], 4);
    rct_relay($meet, $event, $clubB, [], 4);

    $this->actingAs(rct_admin())->get(route('relay-entries.index', ['search' => $clubA->name]))
        ->assertOk()
        ->assertSee($clubA->display_name)
        ->assertDontSee($clubB->display_name);
});
