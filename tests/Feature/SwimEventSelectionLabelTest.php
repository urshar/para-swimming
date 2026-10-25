<?php

use App\Models\Meet;
use App\Models\Result;
use App\Models\SwimEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('result-form-p1');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

function admin_rfp1(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

/** @return array{0: Meet, 1: SwimEvent, 2: SwimEvent} */
function setup_rfp1(): array
{
    $meet = makeMeet_p5();
    $prelim = makeEvent_p5($meet, ['event_number' => 7, 'distance' => 50, 'gender' => 'M', 'round' => 'PRE']);
    $final = makeEvent_p5($meet, ['event_number' => 8, 'distance' => 50, 'gender' => 'F', 'round' => 'FIN']);

    return [$meet, $prelim, $final];
}

// ── Bezeichnung ───────────────────────────────────────────────────────────────

it('bezeichnet einen Bewerb mit Nummer, Geschlecht und Runde', function () {
    [, $prelim, $final] = setup_rfp1();

    expect($prelim->selection_label)->toBe('Nr. 7 – 50m FREE Herren, Vorlauf')
        ->and($final->selection_label)->toBe('Nr. 8 – 50m FREE Damen, Finale');
});

it('lässt Geschlecht A, Runde TIM und eine fehlende Nummer weg', function () {
    $meet = makeMeet_p5();
    $event = makeEvent_p5($meet, ['event_number' => null, 'distance' => 100, 'gender' => 'A', 'round' => 'TIM']);

    expect($event->selection_label)->toBe('100m FREE');
});

it('bezeichnet Mixed-Staffeln ohne führendes Leerzeichen', function () {
    $meet = makeMeet_p5();
    $relay = makeEvent_p5($meet, ['event_number' => 12, 'distance' => 50, 'relay_count' => 4, 'gender' => 'X']);

    expect($relay->selection_label)->toBe('Nr. 12 – 4x50m FREE Mixed');
});

// ── Formulare ─────────────────────────────────────────────────────────────────

it('zeigt die eindeutige Bezeichnung im Ergebnis-Formular beim Anlegen', function () {
    [$meet] = setup_rfp1();

    $this->actingAs(admin_rfp1())
        ->get(route('meets.results.create', $meet))
        ->assertOk()
        ->assertSee('Nr. 7 – 50m FREE Herren, Vorlauf')
        ->assertSee('Nr. 8 – 50m FREE Damen, Finale');
});

it('zeigt die eindeutige Bezeichnung im Ergebnis-Formular beim Bearbeiten', function () {
    [$meet, $prelim] = setup_rfp1();
    $club = makeClub_p5();
    $athlete = makeAthlete_p5($club);
    $result = Result::create([
        'meet_id' => $meet->id,
        'swim_event_id' => $prelim->id,
        'athlete_id' => $athlete->id,
        'club_id' => $club->id,
        'swim_time' => 3000,
    ]);

    $this->actingAs(admin_rfp1())
        ->get(route('results.edit', $result))
        ->assertOk()
        ->assertSee('Nr. 7 – 50m FREE Herren, Vorlauf')
        ->assertSee('Nr. 8 – 50m FREE Damen, Finale');
});

it('zeigt die eindeutige Bezeichnung im Staffelergebnis-Formular', function () {
    $meet = makeMeet_p5();
    makeEvent_p5($meet, ['event_number' => 12, 'distance' => 50, 'relay_count' => 4, 'gender' => 'X', 'round' => 'FIN']);

    $this->actingAs(admin_rfp1())
        ->get(route('meets.relay-results.create', $meet))
        ->assertOk()
        ->assertSee('Nr. 12 – 4x50m FREE Mixed, Finale');
});

it('zeigt die eindeutige Bezeichnung im Meldungs-Formular', function () {
    [$meet] = setup_rfp1();
    $meet->update(['is_open' => true]);

    $this->actingAs(admin_rfp1())
        ->get(route('meets.entries.create', $meet))
        ->assertOk()
        ->assertSee('Nr. 7 – 50m FREE Herren, Vorlauf');
});
