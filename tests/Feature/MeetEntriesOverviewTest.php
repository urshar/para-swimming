<?php

use App\Models\Club;
use App\Models\Entry;
use App\Models\RelayEntry;
use App\Models\RelayEntryMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('meet-entries-overview');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php):
// makeMeet_p5(), makeClub_p5(), makeAthlete_p5(), makeEvent_p5().

function admin_meo(): User
{
    return User::firstWhere('email', 'admin_meo@example.test')
        ?? User::forceCreate([
            'name' => 'Admin MEO',
            'email' => 'admin_meo@example.test',
            'password' => bcrypt('secret'),
            'is_admin' => true,
        ]);
}

function clubUser_meo(Club $club): User
{
    return User::firstWhere('email', 'club_meo@example.test')
        ?? User::forceCreate([
            'name' => 'Club MEO',
            'email' => 'club_meo@example.test',
            'password' => bcrypt('secret'),
            'is_admin' => false,
            'club_id' => $club->id,
        ]);
}

it('zeigt Einzel- und Staffelmeldungen vereinsübergreifend, nach Disziplin', function () {
    $meet = makeMeet_p5();
    $einzel = makeEvent_p5($meet, ['event_number' => 1]);
    $staffel = makeEvent_p5($meet, ['event_number' => 2, 'relay_count' => 4]);

    $clubA = makeClub_p5();
    $clubB = makeClub_p5();
    $athleteA = makeAthlete_p5($clubA);
    $athleteB = makeAthlete_p5($clubB);

    Entry::create(['meet_id' => $meet->id, 'swim_event_id' => $einzel->id, 'athlete_id' => $athleteA->id, 'club_id' => $clubA->id]);
    Entry::create(['meet_id' => $meet->id, 'swim_event_id' => $einzel->id, 'athlete_id' => $athleteB->id, 'club_id' => $clubB->id]);

    $relay = RelayEntry::create(['meet_id' => $meet->id, 'swim_event_id' => $staffel->id, 'club_id' => $clubA->id, 'relay_class' => 'S20', 'status' => 'pending']);
    RelayEntryMember::create(['relay_entry_id' => $relay->id, 'athlete_id' => $athleteA->id, 'position' => 1, 'sport_class' => 'S9']);

    $this->actingAs(admin_meo())
        ->get(route('meets.entries-overview', $meet))
        ->assertOk()
        ->assertSee('Einzelmeldungen')
        ->assertSee('Staffelmeldungen')
        ->assertSee($athleteA->display_name)
        ->assertSee($athleteB->display_name)   // vereinsübergreifend, ohne Vereinsauswahl
        ->assertSee($clubA->display_name)
        ->assertSee($clubB->display_name)
        ->assertSee('S20');                    // Staffelklasse
});

it('verlinkt die bestehenden Bearbeiten-Formulare (Einzel + Staffel)', function () {
    $meet = makeMeet_p5();
    $einzel = makeEvent_p5($meet, ['event_number' => 1]);
    $staffel = makeEvent_p5($meet, ['event_number' => 2, 'relay_count' => 4]);
    $club = makeClub_p5();
    $athlete = makeAthlete_p5($club);

    $entry = Entry::create(['meet_id' => $meet->id, 'swim_event_id' => $einzel->id, 'athlete_id' => $athlete->id, 'club_id' => $club->id]);
    $relay = RelayEntry::create(['meet_id' => $meet->id, 'swim_event_id' => $staffel->id, 'club_id' => $club->id, 'relay_class' => 'S20', 'status' => 'pending']);

    $this->actingAs(admin_meo())
        ->get(route('meets.entries-overview', $meet))
        ->assertOk()
        ->assertSee(route('entries.edit', $entry), false)
        ->assertSee(route('club-entries.relay.edit', ['meet' => $meet, 'relayEntry' => $relay, 'club_id' => $club->id]), false);
});

it('filtert nach Disziplin und blendet den unpassenden Abschnitt aus', function () {
    $meet = makeMeet_p5();
    $einzelEvent = makeEvent_p5($meet, ['event_number' => 1, 'distance' => 50]);
    $relayEvent = makeEvent_p5($meet, ['event_number' => 2, 'relay_count' => 4]);
    $otherEinzel = makeEvent_p5($meet, ['event_number' => 3, 'distance' => 100]);
    $club = makeClub_p5();
    $athA = makeAthlete_p5($club);
    $athB = makeAthlete_p5($club);

    Entry::create(['meet_id' => $meet->id, 'swim_event_id' => $einzelEvent->id, 'athlete_id' => $athA->id, 'club_id' => $club->id]);
    Entry::create(['meet_id' => $meet->id, 'swim_event_id' => $otherEinzel->id, 'athlete_id' => $athB->id, 'club_id' => $club->id]);
    RelayEntry::create(['meet_id' => $meet->id, 'swim_event_id' => $relayEvent->id, 'club_id' => $club->id, 'relay_class' => 'S20', 'status' => 'pending']);

    // Filter auf eine Einzel-Disziplin: nur deren Meldung, andere Einzel-Meldung
    // weg, und der Staffel-Abschnitt (samt "keine …"-Hinweis) ausgeblendet.
    $this->actingAs(admin_meo())
        ->get(route('meets.entries-overview', ['meet' => $meet, 'event_id' => $einzelEvent->id]))
        ->assertOk()
        ->assertSee($athA->display_name)
        ->assertDontSee($athB->display_name)
        ->assertDontSee('Keine Staffelmeldungen');

    // Filter auf die Staffel-Disziplin: Einzel-Abschnitt ausgeblendet.
    $this->actingAs(admin_meo())
        ->get(route('meets.entries-overview', ['meet' => $meet, 'event_id' => $relayEvent->id]))
        ->assertOk()
        ->assertDontSee('Keine Einzelmeldungen')
        ->assertDontSee($athA->display_name);
});

it('zeigt Anlege-Buttons nur bei offenem Meet', function () {
    $meet = makeMeet_p5();

    $this->actingAs(admin_meo())
        ->get(route('meets.entries-overview', $meet))
        ->assertOk()
        ->assertDontSee('Neue Einzelmeldung')
        ->assertDontSee('Neue Staffelmeldung');

    $meet->update(['is_open' => true]);

    $this->actingAs(admin_meo())
        ->get(route('meets.entries-overview', $meet))
        ->assertOk()
        ->assertSee('Neue Einzelmeldung')
        ->assertSee('Neue Staffelmeldung')
        ->assertSee(route('meets.entries.create', $meet), false)
        ->assertSee(route('club-entries.relay.create', $meet), false);
});

it('übernimmt beim Speichern die Sportklasse aus dem Athleten, wenn das Feld leer ist', function () {
    $meet = makeMeet_p5();
    $meet->update(['is_open' => true]);
    $event = makeEvent_p5($meet); // Freistil → Kategorie S
    $club = makeClub_p5();
    $athlete = makeAthlete_p5($club); // Sportklasse S9

    $this->actingAs(admin_meo())->post(route('meets.entries.store', $meet), [
        'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id,
        'club_id' => $club->id,
    ])->assertRedirect();

    expect(Entry::first()->sport_class)->toBe('S9');
});

it('behält eine abweichend eingetragene Sportklasse', function () {
    $meet = makeMeet_p5();
    $meet->update(['is_open' => true]);
    $event = makeEvent_p5($meet);
    $club = makeClub_p5();
    $athlete = makeAthlete_p5($club);

    $this->actingAs(admin_meo())->post(route('meets.entries.store', $meet), [
        'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id,
        'club_id' => $club->id,
        'sport_class' => 'S5',
    ])->assertRedirect();

    expect(Entry::first()->sport_class)->toBe('S5');
});

it('kehrt nach dem Speichern zur Übersicht zurück (return_to), sonst zur Detailseite', function () {
    $meet = makeMeet_p5();
    $meet->update(['is_open' => true]);
    $event = makeEvent_p5($meet);
    $club = makeClub_p5();
    $overview = route('meets.entries-overview', $meet);

    $base = ['swim_event_id' => $event->id, 'club_id' => $club->id];

    $this->actingAs(admin_meo())->post(route('meets.entries.store', $meet), $base + [
        'athlete_id' => makeAthlete_p5($club)->id,
        'return_to' => $overview,
    ])->assertRedirect($overview);

    $this->actingAs(admin_meo())->post(route('meets.entries.store', $meet), $base + [
        'athlete_id' => makeAthlete_p5($club)->id,
    ])->assertRedirect(route('meets.show', $meet));

    // Open-Redirect-Schutz: externes return_to wird ignoriert.
    $this->actingAs(admin_meo())->post(route('meets.entries.store', $meet), $base + [
        'athlete_id' => makeAthlete_p5($club)->id,
        'return_to' => 'https://evil.example/x',
    ])->assertRedirect(route('meets.show', $meet));
});

it('führt beim Anlegen einer Staffel als Admin direkt über die Vereinsauswahl ins Formular', function () {
    $meet = makeMeet_p5();
    $meet->update(['is_open' => true]);
    makeEvent_p5($meet, ['relay_count' => 4]);
    $club = makeClub_p5();

    // Ohne Verein: Vereinsauswahl (statt 400 oder Umweg über die Staffelliste).
    $this->actingAs(admin_meo())
        ->get(route('club-entries.relay.create', $meet))
        ->assertOk()
        ->assertSee('Verein wählen');

    // Mit Verein: direkt das Anlege-Formular.
    $this->actingAs(admin_meo())
        ->get(route('club-entries.relay.create', ['meet' => $meet, 'club_id' => $club->id]))
        ->assertOk()
        ->assertDontSee('Verein wählen');
});

it('kehrt nach dem Speichern einer Staffel zur Übersicht zurück (return_to)', function () {
    $meet = makeMeet_p5();
    $meet->update(['is_open' => true]);
    $relayEvent = makeEvent_p5($meet, ['relay_count' => 4]);
    $club = makeClub_p5();
    $overview = route('meets.entries-overview', $meet);

    $this->actingAs(admin_meo())->post(
        route('club-entries.relay.store', ['meet' => $meet, 'club_id' => $club->id]),
        [
            'swim_event_id' => $relayEvent->id,
            'athlete_ids' => [],
            'return_to' => $overview,
        ]
    )->assertRedirect($overview);
});

it('ist nur für Admins zugänglich', function () {
    $meet = makeMeet_p5();
    $club = makeClub_p5();

    $this->get(route('meets.entries-overview', $meet))
        ->assertRedirect(route('login'));

    $this->actingAs(clubUser_meo($club))
        ->get(route('meets.entries-overview', $meet))
        ->assertForbidden();
});
