<?php

use App\Models\Athlete;
use App\Models\Classifier;
use App\Models\Coach;
use App\Models\Result;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('name-search');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function admin_ns(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

/** Drei Athleten: der gesuchte, einer mit gleichem Nachnamen, einer mit gleichem Vornamen. */
function athletes_ns(): array
{
    $club = makeClub_p5();
    $names = [['Matteo', 'Aigner'], ['Leonie', 'Aigner'], ['Matteo', 'Brunnhuber']];

    return array_map(function (array $name) use ($club): Athlete {
        $athlete = makeAthlete_p5($club);
        $athlete->update(['first_name' => $name[0], 'last_name' => $name[1]]);

        return $athlete;
    }, $names);
}

/** Suchbegriffe, die alle nur "Matteo Aigner" treffen müssen. */
dataset('Schreibweisen von Matteo Aigner', ['Aigner, M', 'Aigner, Matteo', 'Aigner Matteo', 'Matteo Aigner', 'aigner,matteo']);

// ── Athletenliste ─────────────────────────────────────────────────────────────

it('findet Athleten in der Athletenliste mit Nachname und Vorname', function (string $search) {
    athletes_ns();

    $this->actingAs(admin_ns())
        ->get(route('athletes.index', ['search' => $search]))
        ->assertOk()
        ->assertSee('Aigner')
        ->assertDontSee('Leonie')
        ->assertDontSee('Brunnhuber');
})->with('Schreibweisen von Matteo Aigner');

it('findet in der Athletenliste mit nur einem Wort weiterhin alle Treffer', function () {
    athletes_ns();

    $this->actingAs(admin_ns())
        ->get(route('athletes.index', ['search' => 'Aigner']))
        ->assertOk()
        ->assertSee('Matteo')
        ->assertSee('Leonie')
        ->assertDontSee('Brunnhuber');
});

it('findet in der Athletenliste nichts, wenn ein Suchteil nicht passt', function () {
    athletes_ns();

    $this->actingAs(admin_ns())
        ->get(route('athletes.index', ['search' => 'Aigner, X']))
        ->assertOk()
        ->assertSee('Keine Athleten gefunden.')
        ->assertDontSee('Matteo')
        ->assertDontSee('Leonie');
});

it('kombiniert in der Athletenliste Name und Lizenz', function () {
    [$matteo, $leonie] = athletes_ns();
    $matteo->update(['license' => 'LIZ-4711']);
    $leonie->update(['license' => 'LIZ-0815']);

    $this->actingAs(admin_ns())
        ->get(route('athletes.index', ['search' => 'Aigner 4711']))
        ->assertOk()
        ->assertSee('Matteo')
        ->assertDontSee('Leonie');
});

// ── Ergebnisse (Trait SearchesAthletes, auch von den Meldungen genutzt) ──────

it('findet Ergebnisse mit Nachname und Vorname', function (string $search) {
    $meet = makeMeet_p5();
    $event = makeEvent_p5($meet);
    foreach (athletes_ns() as $athlete) {
        Result::create([
            'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id,
            'club_id' => $athlete->club_id, 'swim_time' => 65000, 'sport_class' => 'S9',
        ]);
    }

    $this->actingAs(admin_ns())
        ->get(route('results.index', ['search' => $search]))
        ->assertOk()
        ->assertSee('Aigner')
        ->assertDontSee('Leonie')
        ->assertDontSee('Brunnhuber');
})->with('Schreibweisen von Matteo Aigner');

// ── Trainer und Klassifizierer ────────────────────────────────────────────────

it('findet Trainer mit Nachname und Vorname', function (string $search) {
    $club = makeClub_p5();
    foreach ([['Matteo', 'Aigner'], ['Leonie', 'Aigner'], ['Matteo', 'Brunnhuber']] as [$first, $last]) {
        Coach::create(['club_id' => $club->id, 'first_name' => $first, 'last_name' => $last, 'is_active' => true]);
    }

    $this->actingAs(admin_ns())
        ->get(route('coaches.index', ['search' => $search]))
        ->assertOk()
        ->assertSee('Aigner')
        ->assertDontSee('Leonie')
        ->assertDontSee('Brunnhuber');
})->with('Schreibweisen von Matteo Aigner');

it('findet Klassifizierer mit Nachname und Vorname', function (string $search) {
    foreach ([['Matteo', 'Aigner'], ['Leonie', 'Aigner'], ['Matteo', 'Brunnhuber']] as [$first, $last]) {
        Classifier::create(['first_name' => $first, 'last_name' => $last, 'type' => Classifier::TYPE_MED]);
    }

    $this->actingAs(admin_ns())
        ->get(route('classifiers.index', ['search' => $search]))
        ->assertOk()
        ->assertSee('Aigner')
        ->assertDontSee('Leonie')
        ->assertDontSee('Brunnhuber');
})->with('Schreibweisen von Matteo Aigner');

// ── Sammelansicht ─────────────────────────────────────────────────────────────

it('findet in der Sammelansicht mit Nachname und Vorname', function (string $search) {
    $meet = makeMeet_p5();
    $event = makeEvent_p5($meet);
    foreach (athletes_ns() as $athlete) {
        Result::create([
            'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id,
            'club_id' => $athlete->club_id, 'swim_time' => 65000, 'place' => 1, 'sport_class' => 'S9',
        ]);
    }

    $this->actingAs(admin_ns())
        ->get(route('meets.results-overview', ['meet' => $meet, 'search' => $search]))
        ->assertOk()
        ->assertSee('Aigner')
        ->assertDontSee('Leonie')
        ->assertDontSee('Brunnhuber');
})->with('Schreibweisen von Matteo Aigner');
