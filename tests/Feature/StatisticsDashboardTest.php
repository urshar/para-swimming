<?php

use App\Livewire\StatisticsDashboard;
use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\SwimRecord;
use App\Models\User;
use Livewire\Livewire;

uses()->group('statistik-p12');

// ── Helpers ──────────────────────────────────────────────────────────────────

function dash12_nation(): Nation
{
    return Nation::firstOrCreate(
        ['code' => 'AUT'],
        ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]
    );
}

function dash12_strokeType(): StrokeType
{
    return StrokeType::firstOrCreate(
        ['code' => 'FREE'],
        [
            'lenex_code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle',
            'category' => 'standard', 'is_active' => true,
        ]
    );
}

function dash12_meet(string $name, string $startDate): Meet
{
    return Meet::create([
        'name' => $name, 'nation_id' => dash12_nation()->id,
        'course' => 'LCM', 'start_date' => $startDate,
    ]);
}

/** Legt einen Start an (eigener Bewerb je Aufruf). */
function dash12_start(Meet $meet, Athlete $athlete, Club $club): Result
{
    $event = SwimEvent::create([
        'meet_id' => $meet->id, 'stroke_type_id' => dash12_strokeType()->id,
        'distance' => 100, 'gender' => 'A', 'relay_count' => 1,
    ]);

    return Result::create([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id, 'club_id' => $club->id,
        'sport_class' => 'S9', 'swim_time' => 6000,
    ]);
}

function dash12_athlete(string $lastName): Athlete
{
    return Athlete::create([
        'first_name' => 'Test', 'last_name' => $lastName, 'gender' => 'F',
        'birth_date' => '2000-01-01', 'nation_id' => dash12_nation()->id, 'is_active' => true,
    ]);
}

function dash12_club(string $name): Club
{
    return Club::create(['name' => $name, 'nation_id' => dash12_nation()->id]);
}

// ── Zugriff ──────────────────────────────────────────────────────────────────

it('leitet nicht angemeldete Besucher auf die Login-Seite', function () {
    $this->get(route('statistics.index'))->assertRedirect(route('login'));
});

it('ist für angemeldete Vereins-User zugänglich', function () {
    $club = dash12_club('Testclub');

    $this->actingAs(User::factory()->create(['club_id' => $club->id, 'is_admin' => false]))
        ->get(route('statistics.index'))
        ->assertOk()
        ->assertSee('Statistik');
});

it('ist für Admins zugänglich', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get(route('statistics.index'))
        ->assertOk();
});

// ── Jahresauswahl ────────────────────────────────────────────────────────────

it('bietet die Jahre der erfassten Veranstaltungen absteigend an', function () {
    dash12_meet('Meet 2023', '2023-05-01');
    dash12_meet('Meet 2024', '2024-05-01');
    dash12_meet('Meet 2024 II', '2024-09-01');

    $component = Livewire::actingAs(User::factory()->create())->test(StatisticsDashboard::class);

    expect($component->instance()->availableYears()->all())->toBe([2024, 2023]);
});

it('wählt beim Öffnen das jüngste Jahr mit Veranstaltungen vor', function () {
    dash12_meet('Meet 2023', '2023-05-01');
    dash12_meet('Meet 2024', '2024-05-01');

    Livewire::actingAs(User::factory()->create())
        ->test(StatisticsDashboard::class)
        ->assertSet('year', 2024);
});

it('fällt ohne Veranstaltungen auf das laufende Jahr zurück', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(StatisticsDashboard::class)
        ->assertSet('year', now()->year);
});

it('listet nur die Veranstaltungen des gewählten Jahres', function () {
    dash12_meet('Meet 2023', '2023-05-01');
    dash12_meet('Meet 2024', '2024-05-01');

    $component = Livewire::actingAs(User::factory()->create())
        ->test(StatisticsDashboard::class)
        ->set('year', 2023);

    expect($component->instance()->availableMeets()->pluck('name')->all())->toBe(['Meet 2023']);
});

it('verwirft die Veranstaltungsauswahl beim Wechsel des Jahres', function () {
    $meet = dash12_meet('Meet 2024', '2024-05-01');
    dash12_meet('Meet 2023', '2023-05-01');

    Livewire::actingAs(User::factory()->create())
        ->test(StatisticsDashboard::class)
        ->set('meetIds', [$meet->id])
        ->set('year', 2023)
        ->assertSet('meetIds', []);
});

it('hebt die Veranstaltungsauswahl auf Knopfdruck auf', function () {
    $meet = dash12_meet('Meet 2024', '2024-05-01');

    Livewire::actingAs(User::factory()->create())
        ->test(StatisticsDashboard::class)
        ->set('meetIds', [$meet->id])
        ->call('resetMeetSelection')
        ->assertSet('meetIds', []);
});

// ── Kennzahlen und Tabellen ──────────────────────────────────────────────────

it('zeigt die Kennzahlen des gewählten Jahres', function () {
    $meet = dash12_meet('Testmeet', '2024-06-01');
    $club = dash12_club('Testclub');
    $athlete = dash12_athlete('Muster');

    dash12_start($meet, $athlete, $club);
    dash12_start($meet, $athlete, $club);

    SwimRecord::create([
        'stroke_type_id' => dash12_strokeType()->id, 'athlete_id' => $athlete->id,
        'record_type' => 'AUT', 'sport_class' => 'S9', 'gender' => 'F',
        'distance' => 100, 'swim_time' => 6000, 'set_date' => '2024-06-01',
    ]);

    $stats = Livewire::actingAs(User::factory()->create())
        ->test(StatisticsDashboard::class)
        ->set('year', 2024)
        ->instance()
        ->statistics();

    expect($stats['overview']['participants'])->toBe(1)
        ->and($stats['overview']['clubs'])->toBe(1)
        ->and($stats['overview']['meets'])->toBe(1)
        ->and($stats['overview']['starts'])->toBe(2)
        ->and($stats['records']['overview']['total'])->toBe(1);
});

it('zeigt Veranstaltung, Verein, Sportler und Nation in den Tabellen', function () {
    $meet = dash12_meet('Sichtbares Meet', '2024-06-01');
    dash12_start($meet, dash12_athlete('Sichtbar'), dash12_club('Sichtbarer Verein'));

    Livewire::actingAs(User::factory()->create())
        ->test(StatisticsDashboard::class)
        ->set('year', 2024)
        ->assertSee('Sichtbares Meet')
        ->assertSee('Sichtbarer Verein')
        ->assertSee('Sichtbar, Test')
        ->assertSee('AUT');
});

it('schränkt die Auswertung auf die gewählten Veranstaltungen ein', function () {
    $selected = dash12_meet('Gewählt', '2024-03-01');
    $other = dash12_meet('Nicht gewählt', '2024-09-01');
    $club = dash12_club('Testclub');

    dash12_start($selected, dash12_athlete('Eins'), $club);
    dash12_start($other, dash12_athlete('Zwei'), $club);

    $component = Livewire::actingAs(User::factory()->create())
        ->test(StatisticsDashboard::class)
        ->set('year', 2024)
        ->set('meetIds', [$selected->id]);

    $statistics = $component->instance()->statistics();

    expect($statistics['overview']['starts'])->toBe(1)
        ->and($statistics['overview']['meets'])->toBe(1);
});

it('berechnet nur die im Dashboard dargestellten Abschnitte', function () {
    dash12_meet('Testmeet', '2024-06-01');

    $stats = Livewire::actingAs(User::factory()->create())
        ->test(StatisticsDashboard::class)
        ->instance()
        ->statistics();

    expect(array_keys($stats))->toBe(['overview', 'meets', 'clubs', 'athletes', 'nations', 'records']);
});

it('zeigt einen Hinweis, wenn für das Jahr keine Veranstaltungen erfasst sind', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(StatisticsDashboard::class)
        ->assertSee('sind keine Veranstaltungen erfasst');
});
