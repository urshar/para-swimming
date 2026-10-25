<?php

use App\Models\Entry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('result-form-p2');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php). Der Filter selbst läuft im Browser
// (resources/js/result-form.js); hier wird geprüft, dass das Formular die nötigen Daten bekommt.

function admin_rfp2(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

it('übergibt dem Anlegen-Formular das Geschlecht je Bewerb und je Athlet', function () {
    $meet = makeMeet_p5();
    $men = makeEvent_p5($meet, ['event_number' => 1, 'gender' => 'M']);
    $mixed = makeEvent_p5($meet, ['event_number' => 2, 'gender' => 'A']);
    $club = makeClub_p5();
    $male = makeAthlete_p5($club);
    $female = makeAthlete_p5($club, 'F');

    $response = $this->actingAs(admin_rfp2())
        ->get(route('meets.results.create', $meet))
        ->assertOk();

    // toEqual: Reihenfolge egal (Athleten sind nach den durchnummerierten Testnamen sortiert).
    expect($response->viewData('eventGenders')->all())->toEqual([$men->id => 'M', $mixed->id => 'A'])
        ->and($response->viewData('athleteGenders')->all())->toEqual([$male->id => 'M', $female->id => 'F']);
});

it('übergibt die gemeldeten Athleten je Bewerb und zeigt dann den Umschalter', function () {
    $meet = makeMeet_p5();
    $first = makeEvent_p5($meet, ['event_number' => 1]);
    $second = makeEvent_p5($meet, ['event_number' => 2]);
    $club = makeClub_p5();
    $a = makeAthlete_p5($club);
    $b = makeAthlete_p5($club);
    foreach ([[$first, $a], [$first, $b], [$second, $b]] as [$event, $athlete]) {
        Entry::create(['meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id,
            'club_id' => $club->id]);
    }

    $response = $this->actingAs(admin_rfp2())
        ->get(route('meets.results.create', $meet))
        ->assertOk()
        ->assertSee('Nur zu diesem Bewerb gemeldete Athleten');

    expect($response->viewData('enteredAthletes')->all())
        ->toBe([$first->id => [$a->id, $b->id], $second->id => [$b->id]]);
});

it('blendet den Umschalter aus, wenn es zum Wettkampf keine Meldungen gibt', function () {
    $meet = makeMeet_p5();
    makeEvent_p5($meet, ['event_number' => 1]);

    $this->actingAs(admin_rfp2())
        ->get(route('meets.results.create', $meet))
        ->assertOk()
        ->assertDontSee('Nur zu diesem Bewerb gemeldete Athleten');
});

it('bindet jede Athleten-Option an den Filter', function () {
    $meet = makeMeet_p5();
    makeEvent_p5($meet, ['event_number' => 1]);
    $athlete = makeAthlete_p5(makeClub_p5());

    $this->actingAs(admin_rfp2())
        ->get(route('meets.results.create', $meet))
        ->assertOk()
        ->assertSee('x-show="athleteVisible('.$athlete->id.')"', false);
});
