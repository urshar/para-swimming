<?php

use App\Models\Club;
use App\Models\Meet;
use App\Models\RelayResult;
use App\Models\RelayResultMember;
use App\Models\SwimEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('relay-results-p2');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

/**
 * Veröffentlichte Veranstaltung nur mit Staffelergebnissen: Herrenstaffel S14 mit zwei Schwimmern mit Athlet und
 * einem nur als Namenskopie, AK-Staffel mit Weltrekord-Kennzeichen.
 *
 * @return array{0: Meet, 1: SwimEvent, 2: Club}
 */
function setup_prr(): array
{
    makeStrokeType_p5();
    $meet = makeMeet_p5();
    $meet->update(['is_published' => true, 'start_date' => now()->subDays(10)->toDateString()]);
    $club = makeClub_p5();
    $event = makeEvent_p5($meet, ['event_number' => 8, 'distance' => 50, 'relay_count' => 4, 'gender' => 'A', 'sport_classes' => null]);

    $relay = RelayResult::create([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'club_id' => $club->id, 'relay_number' => 1,
        'gender' => 'M', 'relay_class' => 'S14', 'swim_time' => 14949, 'points' => 323,
    ]);
    foreach ([1, 2] as $position) {
        $athlete = makeAthlete_p5($club, 'M', ['S14']);
        $athlete->update(['last_name' => 'Staffelmann'.$position, 'birth_date' => '2001-05-05']);
        RelayResultMember::create(['relay_result_id' => $relay->id, 'position' => $position, 'athlete_id' => $athlete->id]);
    }
    RelayResultMember::create(['relay_result_id' => $relay->id, 'position' => 3, 'first_name' => 'Gast', 'last_name' => 'Kopie']);

    RelayResult::create([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'club_id' => $club->id, 'relay_number' => 2,
        'gender' => 'M', 'relay_class' => 'S14', 'swim_time' => 16000, 'status' => 'EXH', 'is_world_record' => true,
    ]);

    return [$meet, $event, $club];
}

// ── Öffentliche Ergebnisseite ─────────────────────────────────────────────────

it('zeigt Staffelergebnisse mit Wertung, Staffel, Schwimmern, Zeit und Punkten', function () {
    [$meet, , $club] = setup_prr();

    test()->get(route('public.meets.results', ['locale' => 'de', 'meet' => $meet]))
        ->assertOk()
        ->assertSee('Herren – S14')
        ->assertSeeInOrder(['Staffel', 'Schwimmer', 'Staffelklasse', 'Zeit', 'Punkte', 'Rekord'])
        ->assertSeeInOrder([$club->display_name, 'Staffelmann1', '(2001)', 'Staffelmann2', 'Kopie, Gast', 'S14', '02:29.49', '323'])
        ->assertSee($club->display_name.' 2')
        ->assertSee('Weltrekord')
        ->assertDontSee('WPS-Punkte');
});

it('zeigt den Ergebnis-Link auch bei einer Veranstaltung nur mit Staffelergebnissen', function () {
    [$meet] = setup_prr();

    test()->get(route('public.meets.show', ['locale' => 'de', 'meet' => $meet]))
        ->assertOk()
        ->assertSee(route('public.meets.results', ['locale' => 'de', 'meet' => $meet]));
});

it('zeigt Staffelergebnisse auch auf Englisch', function () {
    [$meet] = setup_prr();

    test()->get(route('public.meets.results', ['locale' => 'en', 'meet' => $meet]))
        ->assertOk()
        ->assertSee('Men – S14')
        ->assertSeeInOrder(['Relay', 'Swimmers', 'Relay class']);
});

// ── Disziplin löschen ─────────────────────────────────────────────────────────

it('löscht keinen Staffelbewerb mit Staffelergebnissen', function () {
    [, $event] = setup_prr();

    test()->actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->delete(route('events.destroy', $event))
        ->assertSessionHasErrors('event');

    expect(SwimEvent::find($event->id))->not->toBeNull()
        ->and(RelayResult::count())->toBe(2);
});
