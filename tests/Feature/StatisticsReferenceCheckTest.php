<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\SwimRecord;

uses()->group('statistik-p16');

// ── Helpers ──────────────────────────────────────────────────────────────────

function ref16_nation(): Nation
{
    return Nation::firstOrCreate(
        ['code' => 'AUT'],
        ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]
    );
}

function ref16_strokeType(): StrokeType
{
    return StrokeType::firstOrCreate(
        ['code' => 'FREE'],
        [
            'lenex_code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle',
            'category' => 'standard', 'is_active' => true,
        ]
    );
}

function ref16_meet(string $name, string $startDate): Meet
{
    return Meet::create([
        'name' => $name, 'nation_id' => ref16_nation()->id,
        'course' => 'LCM', 'start_date' => $startDate,
    ]);
}

function ref16_athlete(string $lastName): Athlete
{
    return Athlete::create([
        'first_name' => 'Test', 'last_name' => $lastName, 'gender' => 'F',
        'birth_date' => '2000-01-01', 'nation_id' => ref16_nation()->id, 'is_active' => true,
    ]);
}

function ref16_start(Meet $meet, Athlete $athlete, Club $club, ?string $status = null): Result
{
    $event = SwimEvent::create([
        'meet_id' => $meet->id, 'stroke_type_id' => ref16_strokeType()->id,
        'distance' => 100, 'gender' => 'A', 'relay_count' => 1,
    ]);

    return Result::create([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id, 'club_id' => $club->id,
        'sport_class' => 'S9', 'status' => $status,
        'swim_time' => $status === null ? 6000 : null,
    ]);
}

/**
 * Datenbestand 2024: 2 Sportler, 1 Verein, 1 Veranstaltung,
 * 3 Starts (2 regulär + 1 DSQ) sowie 1 DNS (zählt nicht) und 1 Rekord.
 */
function ref16_seed(): void
{
    $meet = ref16_meet('Testmeet', '2024-06-01');
    $club = Club::create(['name' => 'Testverein', 'nation_id' => ref16_nation()->id]);
    $anna = ref16_athlete('Anna');
    $berta = ref16_athlete('Berta');

    ref16_start($meet, $anna, $club);
    ref16_start($meet, $anna, $club, 'DSQ');
    ref16_start($meet, $berta, $club);
    ref16_start($meet, $berta, $club, 'DNS');

    SwimRecord::create([
        'stroke_type_id' => ref16_strokeType()->id, 'athlete_id' => $anna->id,
        'record_type' => 'AUT', 'sport_class' => 'S9', 'gender' => 'F',
        'distance' => 100, 'swim_time' => 6000, 'set_date' => '2024-06-01',
    ]);
}

// ── Ausführung ───────────────────────────────────────────────────────────────

it('läuft auch ohne Daten fehlerfrei durch', function () {
    $this->artisan('statistics:reference-check', ['year' => 2024])
        ->expectsOutputToContain('Referenzabgleich Jahresbericht 2024')
        ->assertSuccessful();
});

it('weist auf einen leeren Datenbestand hin, statt lauter Nullen zu vergleichen', function () {
    $this->artisan('statistics:reference-check', ['year' => 2024, '--starts' => 1464])
        ->expectsOutputToContain('keine Veranstaltungen mit Starts erfasst')
        ->expectsOutputToContain('überhaupt keine Veranstaltungen erfasst')
        ->doesntExpectOutputToContain('Abweichung')
        ->assertSuccessful();
});

it('nennt die Jahre, für die Daten vorliegen', function () {
    ref16_seed(); // Daten ausschließlich für 2024

    $this->artisan('statistics:reference-check', ['year' => 2023])
        ->expectsOutputToContain('Für 2023 sind keine Veranstaltungen')
        ->expectsOutputToContain('Daten liegen vor für: 2024')
        ->assertSuccessful();
});

it('gibt die ermittelten Kennzahlen aus', function () {
    ref16_seed();

    $this->artisan('statistics:reference-check', ['year' => 2024])
        ->expectsOutputToContain('Sportler')
        ->expectsOutputToContain('Österreichische Vereine')
        ->expectsOutputToContain('Neue Rekorde')
        ->assertSuccessful();
});

it('meldet keine Abweichung, wenn die Referenzwerte zutreffen', function () {
    ref16_seed();

    $this->artisan('statistics:reference-check', [
        'year' => 2024,
        '--participants' => 2,
        '--clubs' => 1,
        '--starts' => 3,   // 2 regulär + 1 DSQ; das DNS zählt nicht
        '--records' => 1,
    ])
        ->expectsOutputToContain('Keine Abweichungen')
        ->assertSuccessful();
});

it('weist eine Abweichung samt möglicher Ursachen aus', function () {
    ref16_seed();

    $this->artisan('statistics:reference-check', [
        'year' => 2024,
        '--starts' => 99,
    ])
        ->expectsOutputToContain('weichen ab')
        ->expectsOutputToContain('andere Definition von "Start"')
        ->assertSuccessful();
});

it('zeigt die Startzahl unter den möglichen Startdefinitionen', function () {
    ref16_seed();

    $this->artisan('statistics:reference-check', ['year' => 2024])
        ->expectsOutputToContain('Startdefinition')
        ->expectsOutputToContain('Alle Ergebniszeilen')
        ->expectsOutputToContain('Nur gewertete Ergebnisse')
        ->assertSuccessful();
});

it('listet die ausgewerteten Veranstaltungen auf', function () {
    ref16_seed();

    $this->artisan('statistics:reference-check', ['year' => 2024])
        ->expectsOutputToContain('Ausgewertete Veranstaltungen')
        ->expectsOutputToContain('Testmeet')
        ->assertSuccessful();
});

it('wertet nur das angegebene Jahr aus', function () {
    ref16_seed();
    $club = Club::create(['name' => 'Anderer Verein', 'nation_id' => ref16_nation()->id]);
    ref16_start(ref16_meet('Meet 2023', '2023-06-01'), ref16_athlete('Cilli'), $club);

    $this->artisan('statistics:reference-check', [
        'year' => 2023,
        '--starts' => 1,
        '--participants' => 1,
    ])
        ->expectsOutputToContain('Keine Abweichungen')
        ->assertSuccessful();
});

it('berücksichtigt einen abweichenden Schwellenwert für Mehrfachteilnahmen', function () {
    ref16_seed();

    $this->artisan('statistics:reference-check', ['year' => 2024, '--threshold' => 5])
        ->expectsOutputToContain('Sportler mit mind. 5 Teilnahmen')
        ->assertSuccessful();
});
