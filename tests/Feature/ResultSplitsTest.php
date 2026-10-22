<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Result;
use App\Models\ResultSplit;
use App\Models\SwimEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('result-splits');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

function admin_rsp(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

/** @return array{0: Meet, 1: Club, 2: Athlete, 3: SwimEvent} */
function setup_rsp(int $distance): array
{
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $athlete = makeAthlete_p5($club);
    $event = makeEvent_p5($meet, ['event_number' => 1, 'distance' => $distance]);

    return [$meet, $club, $athlete, $event];
}

function payload_rsp(SwimEvent $event, Athlete $athlete, Club $club, array $splits): array
{
    return [
        'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id,
        'club_id' => $club->id,
        'swim_time' => '02:40.00',
        'splits' => $splits,
    ];
}

// ── Standard-Distanzen ────────────────────────────────────────────────────────

it('schlägt Zwischenzeiten im 50-m-Raster bis vor das Ziel vor', function () {
    $event = new SwimEvent(['distance' => 200]);
    $sprint = new SwimEvent(['distance' => 50]);

    expect($event->defaultSplitDistances('LCM'))->toBe([50, 100, 150])
        ->and($event->defaultSplitDistances('SCM'))->toBe([50, 100, 150])
        ->and($sprint->defaultSplitDistances('SCM'))->toBe([25])
        ->and($sprint->defaultSplitDistances('LCM'))->toBe([]);
});

it('übergibt dem Formular die vorgeschlagenen Distanzen je Bewerb', function () {
    [$meet, , , $event] = setup_rsp(400);

    $this->actingAs(admin_rsp())
        ->get(route('meets.results.create', $meet))
        ->assertOk()
        ->assertViewHas('splitDistances', [$event->id => [50, 100, 150, 200, 250, 300, 350]]);
});

// ── Speichern ─────────────────────────────────────────────────────────────────

it('speichert Zwischenzeiten mit Distanz und Zeit', /** @throws JsonException */ function () {
    [$meet, $club, $athlete, $event] = setup_rsp(200);

    $this->actingAs(admin_rsp())
        ->post(route('meets.results.store', $meet), payload_rsp($event, $athlete, $club, [
            ['distance' => '50', 'split_time' => '00:37.10'],
            ['distance' => '100', 'split_time' => '01:18.20'],
            ['distance' => '150', 'split_time' => '00:00.00'],
        ]))
        ->assertSessionHasNoErrors();

    expect(ResultSplit::orderBy('distance')->get(['distance', 'split_time'])->toArray())->toBe([
        ['distance' => 50, 'split_time' => 3710],
        ['distance' => 100, 'split_time' => 7820],
    ]);
});

it('verwirft eine Zwischenzeit ohne Distanz nicht still, sondern meldet einen Fehler', function () {
    [$meet, $club, $athlete, $event] = setup_rsp(200);

    $this->actingAs(admin_rsp())
        ->post(route('meets.results.store', $meet), payload_rsp($event, $athlete, $club, [
            ['distance' => '50', 'split_time' => '00:37.10'],
            ['distance' => '', 'split_time' => '01:18.20'],
        ]))
        ->assertSessionHasErrors('splits.1.distance');

    expect(Result::count())->toBe(0)
        ->and(ResultSplit::count())->toBe(0);
});

it('zeigt gespeicherte Zwischenzeiten beim Bearbeiten an', function () {
    [$meet, $club, $athlete, $event] = setup_rsp(200);
    $result = Result::create([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id,
        'club_id' => $club->id, 'swim_time' => 16000,
    ]);
    ResultSplit::create(['result_id' => $result->id, 'distance' => 50, 'split_time' => 3710]);

    $this->actingAs(admin_rsp())
        ->get(route('results.edit', $result))
        ->assertOk()
        ->assertSee('00:37.10');
});
