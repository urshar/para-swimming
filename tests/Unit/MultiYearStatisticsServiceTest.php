<?php

/** @noinspection PhpUnhandledExceptionInspection Pest-Test-Closures fangen Exceptions selbst ab. */

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\SwimRecord;
use App\Services\MultiYearStatisticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('statistics-multi-year-chart');

// ── Helpers ──────────────────────────────────────────────────────────────────

function multiYear_service(): MultiYearStatisticsService
{
    return app(MultiYearStatisticsService::class);
}

function multiYear_nation(): Nation
{
    return Nation::firstOrCreate(
        ['code' => 'AUT'],
        ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true],
    );
}

function multiYear_club(): Club
{
    return Club::create(['name' => 'Club '.uniqid(), 'nation_id' => multiYear_nation()->id]);
}

function multiYear_athlete(string $gender = 'M'): Athlete
{
    return Athlete::create([
        'first_name' => 'Max',
        'last_name' => 'Muster '.uniqid(),
        'gender' => $gender,
        'nation_id' => multiYear_nation()->id,
        'is_active' => true,
    ]);
}

function multiYear_strokeType(): StrokeType
{
    return StrokeType::firstOrCreate(
        ['code' => 'FREE'],
        [
            'lenex_code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle',
            'category' => 'standard', 'is_active' => true,
        ],
    );
}

function multiYear_meet(int $year): Meet
{
    return Meet::create([
        'name' => 'Meet '.uniqid(),
        'nation_id' => multiYear_nation()->id,
        'course' => 'LCM',
        'start_date' => "$year-06-01",
    ]);
}

function multiYear_event(Meet $meet, int $relayCount, string $gender): SwimEvent
{
    return SwimEvent::create([
        'meet_id' => $meet->id,
        'stroke_type_id' => multiYear_strokeType()->id,
        'distance' => 100,
        'gender' => $gender,
        'relay_count' => $relayCount,
    ]);
}

/** Ein Einzelstart eines neuen Athleten des gewünschten Geschlechts im Jahr $year. */
function multiYear_individualStart(int $year, string $gender): Result
{
    $meet = multiYear_meet($year);

    return Result::create([
        'meet_id' => $meet->id,
        'swim_event_id' => multiYear_event($meet, 1, 'A')->id,
        'athlete_id' => multiYear_athlete($gender)->id,
        'club_id' => multiYear_club()->id,
        'sport_class' => 'S9',
        'swim_time' => 6000,
    ]);
}

/** Eine Staffel-Ergebniszeile (ein Schwimmer) im Jahr $year mit Event-Geschlecht $eventGender. */
function multiYear_relayStart(int $year, string $eventGender): Result
{
    $meet = multiYear_meet($year);

    return Result::create([
        'meet_id' => $meet->id,
        'swim_event_id' => multiYear_event($meet, 4, $eventGender)->id,
        'athlete_id' => multiYear_athlete()->id,
        'club_id' => multiYear_club()->id,
        'sport_class' => 'S9',
        'swim_time' => 24000,
    ]);
}

/** Zeile eines bestimmten Jahres aus der Zeitreihe. */
function multiYear_row(array $series, int $year): array
{
    return collect($series['rows'])->firstWhere('year', $year);
}

// ── Zeitraum ─────────────────────────────────────────────────────────────────

it('baut die Zeitreihe aus Anker-Jahr und den Vorjahren in aufsteigender Reihenfolge', function () {
    $series = multiYear_service()->series(2026, span: 5);

    expect($series['anchor_year'])->toBe(2026)
        ->and($series['span'])->toBe(5)
        ->and($series['years'])->toBe([2022, 2023, 2024, 2025, 2026])
        ->and($series['rows'])->toHaveCount(5)
        ->and(array_column($series['rows'], 'year'))->toBe([2022, 2023, 2024, 2025, 2026]);
});

it('ordnet Einzelstarts und Teilnehmer dem richtigen Jahr und Geschlecht zu', function () {
    // 2025: zwei Herren (je 1 Start), eine Dame (2 Starts → 1 Teilnehmerin, 2 Starts).
    multiYear_individualStart(2025, 'M');
    multiYear_individualStart(2025, 'M');
    $dame = multiYear_athlete('F');
    $meet = multiYear_meet(2025);
    foreach ([0, 1] as $ignored) {
        Result::create([
            'meet_id' => $meet->id,
            'swim_event_id' => multiYear_event($meet, 1, 'A')->id,
            'athlete_id' => $dame->id,
            'club_id' => multiYear_club()->id,
            'sport_class' => 'S9',
            'swim_time' => 6000,
        ]);
    }

    $series = multiYear_service()->series(2026, span: 5);
    $row2025 = multiYear_row($series, 2025);
    $row2024 = multiYear_row($series, 2024);

    expect($row2025['starts_m'])->toBe(2)
        ->and($row2025['participants_m'])->toBe(2)
        ->and($row2025['starts_f'])->toBe(2)
        ->and($row2025['participants_f'])->toBe(1)
        ->and($row2024['starts_m'])->toBe(0)
        ->and($row2024['starts_f'])->toBe(0);
});

it('zählt Staffelstarts pro Athlet je Jahr und Typ und lässt sie aus den Einzelserien heraus', function () {
    multiYear_relayStart(2024, 'X');
    multiYear_relayStart(2024, 'X');
    multiYear_relayStart(2024, 'M');

    $series = multiYear_service()->series(2026, span: 5);
    $row2024 = multiYear_row($series, 2024);

    expect($row2024['relay_x'])->toBe(2)
        ->and($row2024['relay_m'])->toBe(1)
        ->and($row2024['relay_f'])->toBe(0)
        ->and($row2024['starts_m'])->toBe(0)   // Staffeln zählen nicht als Einzelstarts
        ->and($series['relay_genders'])->toBe(['M', 'F', 'X']);
});

// ── Geschlechter-Serien ──────────────────────────────────────────────────────

it('führt standardmäßig nur Herren und Damen als Einzelserien', function () {
    multiYear_individualStart(2025, 'M');
    multiYear_individualStart(2025, 'F');

    $series = multiYear_service()->series(2026, span: 5);

    expect($series['individual_genders'])->toBe(['M', 'F']);
});

it('ergänzt die Serie N (nicht binär) nur, wenn dazu Daten vorliegen', function () {
    multiYear_individualStart(2025, 'N');

    $series = multiYear_service()->series(2026, span: 5);
    $row2025 = multiYear_row($series, 2025);

    expect($series['individual_genders'])->toBe(['M', 'F', 'N'])
        ->and($row2025['starts_n'])->toBe(1);
});

it('begrenzt die Spanne auf mindestens ein Jahr', function () {
    $series = multiYear_service()->series(2026, span: 0);

    expect($series['span'])->toBe(1)
        ->and($series['years'])->toBe([2026]);
});

// ── Weitere Jahres-Trends (Rekorde, Veranstaltungen, Status) ──────────────────

/** Ein aufgestellter Rekord mit set_date im Jahr $year. */
function multiYear_record(int $year): void
{
    $result = multiYear_individualStart($year, 'M');

    SwimRecord::create([
        'stroke_type_id' => multiYear_strokeType()->id,
        'result_id' => $result->id,
        'record_type' => 'AUT',
        'sport_class' => 'S9',
        'gender' => 'M',
        'distance' => 100,
        'swim_time' => 6000,
        'set_date' => "$year-06-01",
    ]);
}

/** Ein Einzelergebnis mit gegebenem Status im Jahr $year. */
function multiYear_statusResult(int $year, ?string $status): void
{
    $meet = multiYear_meet($year);

    Result::create([
        'meet_id' => $meet->id,
        'swim_event_id' => multiYear_event($meet, 1, 'A')->id,
        'athlete_id' => multiYear_athlete()->id,
        'club_id' => multiYear_club()->id,
        'sport_class' => 'S9',
        'swim_time' => 6000,
        'status' => $status,
    ]);
}

it('zählt aufgestellte Rekorde je Jahr', function () {
    multiYear_record(2023);
    multiYear_record(2023);
    multiYear_record(2024);

    $trend = multiYear_service()->recordsPerYear(2026, span: 5);

    expect($trend['years'])->toBe([2022, 2023, 2024, 2025, 2026])
        ->and($trend['values'])->toBe([0, 2, 1, 0, 0]);
});

it('zählt Veranstaltungen mit Start je Jahr', function () {
    multiYear_individualStart(2024, 'M'); // eigene Veranstaltung
    multiYear_individualStart(2024, 'F'); // zweite Veranstaltung
    multiYear_individualStart(2023, 'M');

    $trend = multiYear_service()->meetsPerYear(2026, span: 5);

    expect($trend['values'])->toBe([0, 1, 2, 0, 0]);
});

it('bildet die Status-Zeitreihe gesamt je Jahr ab', function () {
    multiYear_statusResult(2024, null);   // regulär
    multiYear_statusResult(2024, 'DSQ');
    multiYear_statusResult(2024, 'DNS');

    $trend = multiYear_service()->statusTrend(2026, span: 5);
    $i = array_search(2024, $trend['years'], true);

    expect($trend['years'])->toBe([2022, 2023, 2024, 2025, 2026])
        ->and($trend['statuses']['regular'][$i])->toBe(1)
        ->and($trend['statuses']['DSQ'][$i])->toBe(1)
        ->and($trend['statuses']['DNS'][$i])->toBe(1)
        ->and($trend['statuses']['WDR'][$i])->toBe(0);
});
