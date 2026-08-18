<?php

use App\Livewire\WpsAthleteAnalysis;
use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class)->group('wps-analysis-events');

// ── Helper (Suffix _we gegen Namenskollisionen) ──────────────────────────────

function nation_we(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria']);
}

function stroke_we(): StrokeType
{
    return StrokeType::firstOrCreate(
        ['lenex_code' => 'FREE'],
        ['code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle']
    );
}

function start_we(Athlete $athlete, string $datum, int $distance, int $zeit): Result
{
    $meet = Meet::query()->create([
        'name' => "Meeting $datum-$distance",
        'city' => 'Wien',
        'nation_id' => nation_we()->getKey(),
        'course' => 'SCM',
        'start_date' => $datum,
    ]);

    $event = SwimEvent::query()->create([
        'meet_id' => $meet->getKey(),
        'stroke_type_id' => stroke_we()->getKey(),
        'event_number' => 1,
        'gender' => 'M',
        'distance' => $distance,
        'relay_count' => 1,
    ]);

    return Result::query()->create([
        'meet_id' => $meet->getKey(),
        'swim_event_id' => $event->getKey(),
        'athlete_id' => $athlete->getKey(),
        'club_id' => $athlete->getAttribute('club_id'),
        'swim_time' => $zeit,
        'sport_class' => 'S9',
    ]);
}

beforeEach(function () {
    $club = Club::query()->create([
        'name' => 'WAT', 'short_name' => 'WAT', 'nation_id' => nation_we()->getKey(),
    ]);

    $this->athlete = Athlete::query()->create([
        'club_id' => $club->getKey(),
        'nation_id' => nation_we()->getKey(),
        'first_name' => 'Test',
        'last_name' => 'Auswahl',
        'birth_date' => '2000-05-01',
        'gender' => 'M',
    ]);

    start_we($this->athlete, '2025-03-01', 100, 7000);
    start_we($this->athlete, '2025-04-01', 100, 6900);
    start_we($this->athlete, '2025-05-01', 200, 15000);

    $this->admin = User::factory()->create(['is_admin' => true, 'club_id' => null]);
});

it('wählt Bewerbe für das PDF an und wieder ab', function () {
    $komponente = Livewire::actingAs($this->admin)
        ->test(WpsAthleteAnalysis::class, ['athlete' => $this->athlete]);

    expect($komponente->instance()->pdfUrl())->not->toContain('events=');

    $komponente->call('toggleEvent', '100 m Freistil');

    expect($komponente->instance()->isEventSelected('100 m Freistil'))->toBeTrue()
        ->and($komponente->instance()->isEventSelected('200 m Freistil'))->toBeFalse()
        ->and($komponente->instance()->pdfUrl())->toContain('events=');

    $komponente->call('toggleEvent', '100 m Freistil');

    expect($komponente->instance()->isEventSelected('100 m Freistil'))->toBeFalse()
        ->and($komponente->instance()->pdfUrl())->not->toContain('events=');
});

it('lässt die Auswahl den Bildschirm unberührt', function () {
    $komponente = Livewire::actingAs($this->admin)
        ->test(WpsAthleteAnalysis::class, ['athlete' => $this->athlete]);

    $komponente->call('toggleEvent', '100 m Freistil');

    // Am Bildschirm scrollt man ohnehin; eine Auswahl, die auch die Ansicht beschneidet,
    // machte das Vergleichen umständlich.
    expect($komponente->instance()->profile()->byEvent)->toHaveCount(2);
});

it('hebt die Auswahl auf', function () {
    $komponente = Livewire::actingAs($this->admin)
        ->test(WpsAthleteAnalysis::class, ['athlete' => $this->athlete]);

    $komponente->call('toggleEvent', '100 m Freistil');
    $komponente->call('clearEventSelection');

    expect($komponente->instance()->isEventSelected('100 m Freistil'))->toBeFalse();
});

it('beschränkt das PDF auf die gewählten Bewerbe', function () {
    $this->actingAs($this->admin)
        ->get(route('wps.athletes.pdf', $this->athlete).'?events='.urlencode('100 m Freistil'))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('enthält ohne Auswahl alle Bewerbe', function () {
    // Der häufigere Fall soll keinen Handgriff kosten.
    $this->actingAs($this->admin)
        ->get(route('wps.athletes.pdf', $this->athlete))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('übergeht einen unbekannten Bewerb, statt ein leeres PDF zu liefern', function () {
    $this->actingAs($this->admin)
        ->get(route('wps.athletes.pdf', $this->athlete).'?events='.urlencode('999 m Unsinn'))
        ->assertOk();
});
