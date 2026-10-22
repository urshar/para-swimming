<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\RelayResult;
use App\Models\Result;
use App\Models\SwimEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('manual-places');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

function admin_mpl(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

/** @return array{0: Meet, 1: Club, 2: Athlete, 3: SwimEvent} */
function setup_mpl(bool $keepFilePlaces): array
{
    $meet = makeMeet_p5();
    $meet->update(['keep_file_places' => $keepFilePlaces]);
    $club = makeClub_p5();
    $athlete = makeAthlete_p5($club);
    $event = makeEvent_p5($meet, ['event_number' => 1]);

    return [$meet, $club, $athlete, $event];
}

function payload_mpl(SwimEvent $event, Athlete $athlete, Club $club, array $extra): array
{
    return array_merge([
        'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id,
        'club_id' => $club->id,
        'swim_time' => '01:05.00',
    ], $extra);
}

// ── Einzelergebnis ────────────────────────────────────────────────────────────

it('übernimmt bei "Plätze nicht neu berechnen" den eingegebenen Platz', function () {
    [$meet, $club, $athlete, $event] = setup_mpl(true);

    $this->actingAs(admin_mpl())
        ->post(route('meets.results.store', $meet), payload_mpl($event, $athlete, $club, ['place' => '7']));
    $result = Result::first();

    $this->put(route('results.update', $result), payload_mpl($event, $athlete, $club, ['place' => '5']));

    expect($result->fresh()->place)->toBe(5);
});

it('berechnet den Platz ohne die Einstellung und ignoriert einen mitgeschickten Wert', function () {
    [$meet, $club, $athlete, $event] = setup_mpl(false);

    $this->actingAs(admin_mpl())
        ->post(route('meets.results.store', $meet), payload_mpl($event, $athlete, $club, ['place' => '7']));

    expect(Result::first()->place)->toBe(1);
});

it('zeigt das Platzfeld nur bei "Plätze nicht neu berechnen"', function () {
    [$meet] = setup_mpl(false);
    $admin = admin_mpl();

    $this->actingAs($admin)->get(route('meets.results.create', $meet))
        ->assertOk()
        ->assertDontSee('name="place"', false)
        ->assertSee('Eigenen Platz eintragen');

    $meet->update(['keep_file_places' => true]);
    $this->get(route('meets.results.create', $meet))
        ->assertOk()
        ->assertSee('name="place"', false);
});

// ── Staffelergebnis ───────────────────────────────────────────────────────────

it('übernimmt auch im Staffelergebnis den eingegebenen Platz', function () {
    [$meet, $club] = setup_mpl(true);
    $relayEvent = makeEvent_p5($meet, ['event_number' => 2, 'relay_count' => 4, 'distance' => 50]);

    $this->actingAs(admin_mpl())
        ->post(route('meets.relay-results.store', $meet), [
            'swim_event_id' => $relayEvent->id,
            'club_id' => $club->id,
            'swim_time' => '02:40.00',
            'place' => '3',
        ])
        ->assertSessionHasNoErrors();

    expect(RelayResult::first()->place)->toBe(3);
});

// ── Veranstaltung ─────────────────────────────────────────────────────────────

// Die Vorbelegung beim Nationswechsel selbst läuft in meet-form.js (Alpine) und ist hier nicht abgedeckt.
it('lässt "Plätze nicht neu berechnen" für Österreich aus und zeigt beim Bearbeiten den gespeicherten Wert', function () {
    makeNation_p5();
    Nation::create(['code' => 'MEX', 'name_de' => 'Mexiko', 'name_en' => 'Mexico', 'is_active' => true]);
    // @json() maskiert die Anführungszeichen im x-data-Attribut als ".
    $config = fn (string $key, string $value): string => '"'.$key.'":'.$value;

    $this->actingAs(admin_mpl())->get(route('meets.create'))
        ->assertOk()
        ->assertSee('Plätze nicht neu berechnen')
        ->assertSee($config('keepFilePlaces', 'false'), false);

    $foreignMeet = makeMeet_p5();
    $foreignMeet->update(['nation_id' => Nation::where('code', 'MEX')->value('id'), 'keep_file_places' => true]);
    $this->get(route('meets.edit', $foreignMeet))
        ->assertOk()
        ->assertSee($config('keepFilePlaces', 'true'), false)
        ->assertSee($config('isEdit', 'true'), false);
});
