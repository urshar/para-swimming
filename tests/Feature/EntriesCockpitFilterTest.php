<?php

use App\Models\Club;
use App\Models\Entry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('entries-cockpit');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php):
// makeMeet_p5(), makeClub_p5(), makeAthlete_p5(), makeEvent_p5().

function ecf_admin(): User
{
    return User::firstWhere('email', 'ecf_admin@example.test')
        ?? User::forceCreate([
            'name' => 'Admin ECF',
            'email' => 'ecf_admin@example.test',
            'password' => bcrypt('secret'),
            'is_admin' => true,
        ]);
}

/**
 * Legt eine Meldung mit frischem Athleten an. $attrs überschreibt die Defaults
 * (z. B. status, entry_time, sport_class).
 */
function ecf_entry(object $meet, object $event, Club $club, array $attrs = []): Entry
{
    return Entry::create(array_merge([
        'meet_id' => $meet->id,
        'swim_event_id' => $event->id,
        'athlete_id' => makeAthlete_p5($club)->id,
        'club_id' => $club->id,
    ], $attrs));
}

it('filtert nach Status WDR', function () {
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $normal = ecf_entry($meet, makeEvent_p5($meet), $club);
    $wdr = ecf_entry($meet, makeEvent_p5($meet), $club, ['status' => 'WDR']);

    $this->actingAs(ecf_admin())->get(route('entries.index', ['status' => 'WDR']))
        ->assertOk()
        ->assertSee($wdr->athlete->display_name)
        ->assertDontSee($normal->athlete->display_name);
});

it('filtert nach Status Normal (Meldungen ohne besonderen Status)', function () {
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $normal = ecf_entry($meet, makeEvent_p5($meet), $club);
    $wdr = ecf_entry($meet, makeEvent_p5($meet), $club, ['status' => 'WDR']);

    $this->actingAs(ecf_admin())->get(route('entries.index', ['status' => 'NORMAL']))
        ->assertOk()
        ->assertSee($normal->athlete->display_name)
        ->assertDontSee($wdr->athlete->display_name);
});

it('filtert nach Problem: ohne Meldezeit', function () {
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $withTime = ecf_entry($meet, makeEvent_p5($meet), $club, ['entry_time' => 6000]);
    $noTime = ecf_entry($meet, makeEvent_p5($meet), $club, ['entry_time' => null, 'entry_time_code' => 'NT']);

    $this->actingAs(ecf_admin())->get(route('entries.index', ['problem' => 'no_time']))
        ->assertOk()
        ->assertSee($noTime->athlete->display_name)
        ->assertDontSee($withTime->athlete->display_name);
});

it('filtert nach Problem: ohne Sportklasse', function () {
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $withClass = ecf_entry($meet, makeEvent_p5($meet), $club, ['sport_class' => 'S10']);
    $noClass = ecf_entry($meet, makeEvent_p5($meet), $club, ['sport_class' => null]);

    $this->actingAs(ecf_admin())->get(route('entries.index', ['problem' => 'no_class']))
        ->assertOk()
        ->assertSee($noClass->athlete->display_name)
        ->assertDontSee($withClass->athlete->display_name);
});

it('zeigt ohne Filter alle Meldungen', function () {
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $a = ecf_entry($meet, makeEvent_p5($meet), $club, ['status' => 'WDR']);
    $b = ecf_entry($meet, makeEvent_p5($meet), $club);

    $this->actingAs(ecf_admin())->get(route('entries.index'))
        ->assertOk()
        ->assertSee($a->athlete->display_name)
        ->assertSee($b->athlete->display_name);
});
