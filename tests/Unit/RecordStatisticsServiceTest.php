<?php

use App\Models\Athlete;
use App\Models\Nation;
use App\Models\StrokeType;
use App\Models\SwimRecord;
use App\Services\RecordStatisticsService;
use App\Support\ReportConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────────────────────

function rec9_service(): RecordStatisticsService
{
    return new RecordStatisticsService;
}

function rec9_config(array $overrides = []): ReportConfiguration
{
    return ReportConfiguration::fromArray(array_merge(['year' => 2024], $overrides));
}

function rec9_nation(string $code = 'AUT'): Nation
{
    return Nation::firstOrCreate(
        ['code' => $code],
        ['name_de' => $code, 'name_en' => $code, 'is_active' => true]
    );
}

function rec9_strokeType(): StrokeType
{
    return StrokeType::firstOrCreate(
        ['code' => 'FREE'],
        [
            'lenex_code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle',
            'category' => 'standard', 'is_active' => true,
        ]
    );
}

function rec9_athlete(array $attrs = []): Athlete
{
    return Athlete::create(array_merge([
        'first_name' => 'Max',
        'last_name' => 'Muster',
        'gender' => 'M',
        'nation_id' => rec9_nation()->id,
        'is_active' => true,
    ], $attrs));
}

function rec9_record(array $attrs = []): SwimRecord
{
    return SwimRecord::create(array_merge([
        'stroke_type_id' => rec9_strokeType()->id,
        'record_type' => 'AUT',
        'sport_class' => 'S9',
        'gender' => 'M',
        'distance' => 100,
        'swim_time' => 6000,
        'set_date' => '2024-06-01',
    ], $attrs));
}

// ── Zeitraumabgrenzung ───────────────────────────────────────────────────────

it('zählt die im Berichtszeitraum aufgestellten Rekorde', function () {
    rec9_record();
    rec9_record(['set_date' => '2024-09-01']);

    expect(rec9_service()->overview(rec9_config())['total'])->toBe(2);
})->group('statistik-p9');

it('schließt Rekorde außerhalb des Zeitraums aus', function () {
    rec9_record(['set_date' => '2024-06-01']);
    rec9_record(['set_date' => '2023-12-31']);
    rec9_record(['set_date' => '2025-01-01']);

    expect(rec9_service()->overview(rec9_config())['total'])->toBe(1);
})->group('statistik-p9');

it('bezieht die Randtage des Zeitraums ein', function () {
    rec9_record(['set_date' => '2024-01-01']);
    rec9_record(['set_date' => '2024-12-31']);

    expect(rec9_service()->overview(rec9_config())['total'])->toBe(2);
})->group('statistik-p9');

it('ignoriert Rekorde ohne Datum', function () {
    rec9_record();
    rec9_record(['set_date' => null]);

    expect(rec9_service()->overview(rec9_config())['total'])->toBe(1);
})->group('statistik-p9');

it('berücksichtigt einen frei gewählten Zeitraum statt des Kalenderjahres', function () {
    rec9_record(['set_date' => '2024-03-15']);
    rec9_record(['set_date' => '2024-09-15']);

    $config = rec9_config(['date_from' => '2024-01-01', 'date_to' => '2024-06-30']);

    expect(rec9_service()->overview($config)['total'])->toBe(1);
})->group('statistik-p9');

// ── Statusbehandlung ─────────────────────────────────────────────────────────

it('zählt ungültige Rekorde und Zielzeiten nicht mit', function () {
    rec9_record();
    rec9_record(['record_status' => 'INVALID']);
    rec9_record(['record_status' => 'TARGETTIME']);

    expect(rec9_service()->overview(rec9_config())['total'])->toBe(1);
})->group('statistik-p9');

it('zählt inzwischen überbotene Rekorde weiterhin für ihr Aufstellungsjahr', function () {
    // Rekord wurde 2024 geschwommen und später überboten.
    rec9_record(['record_status' => 'APPROVED.HISTORY', 'is_current' => false]);
    rec9_record(['record_status' => 'PENDING.HISTORY', 'is_current' => false]);

    expect(rec9_service()->overview(rec9_config())['total'])->toBe(2);
})->group('statistik-p9');

it('zählt noch nicht ratifizierte Rekorde mit', function () {
    rec9_record(['record_status' => 'PENDING']);

    expect(rec9_service()->overview(rec9_config())['total'])->toBe(1);
})->group('statistik-p9');

// ── Rekordarten ──────────────────────────────────────────────────────────────

it('weist österreichische Rekorde und Jugendrekorde getrennt aus', function () {
    rec9_record(['record_type' => 'AUT']);
    rec9_record(['record_type' => 'AUT']);
    rec9_record(['record_type' => 'AUT.JR']);
    rec9_record(['record_type' => 'AUT.WBSV']); // Regionalrekord

    $o = rec9_service()->overview(rec9_config());

    expect($o['total'])->toBe(4)
        ->and($o['austrian'])->toBe(2)
        ->and($o['austrian_junior'])->toBe(1);
})->group('statistik-p9');

it('zählt Staffelrekorde anhand der Staffelgröße', function () {
    rec9_record();
    rec9_record(['relay_count' => 4, 'athlete_id' => null]);

    $o = rec9_service()->overview(rec9_config());

    expect($o['relay'])->toBe(1)
        ->and($o['total'])->toBe(2);
})->group('statistik-p9');

it('weist Rekorde ohne zugeordneten Athleten aus', function () {
    rec9_record(['athlete_id' => rec9_athlete()->id]);
    rec9_record(['athlete_id' => null, 'relay_count' => 4]);

    expect(rec9_service()->overview(rec9_config())['without_athlete'])->toBe(1);
})->group('statistik-p9');

it('liefert bei leerem Datenbestand durchgehend Nullwerte', function () {
    expect(rec9_service()->overview(rec9_config()))->toBe([
        'total' => 0,
        'austrian' => 0,
        'austrian_junior' => 0,
        'relay' => 0,
        'without_athlete' => 0,
    ]);
})->group('statistik-p9');

// ── Rekorde pro Athlet ───────────────────────────────────────────────────────

it('zählt die Rekorde pro Athlet und vergibt Ränge', function () {
    $many = rec9_athlete(['last_name' => 'Viel', 'first_name' => 'Anna']);
    $few = rec9_athlete(['last_name' => 'Wenig', 'first_name' => 'Bob']);

    for ($i = 0; $i < 3; $i++) {
        rec9_record(['athlete_id' => $many->id]);
    }
    rec9_record(['athlete_id' => $few->id]);

    $rows = rec9_service()->byAthlete(rec9_config());

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['athlete_id'])->toBe($many->id)
        ->and($rows[0]['rank'])->toBe(1)
        ->and($rows[0]['records'])->toBe(3)
        ->and($rows[1]['rank'])->toBe(2)
        ->and($rows[1]['records'])->toBe(1);
})->group('statistik-p9');

it('bricht Gleichstand bei Rekorden über den Namen', function () {
    $zeta = rec9_athlete(['last_name' => 'Zeta', 'first_name' => 'A']);
    $alpha = rec9_athlete(['last_name' => 'Alpha', 'first_name' => 'A']);

    rec9_record(['athlete_id' => $zeta->id]);
    rec9_record(['athlete_id' => $alpha->id]);

    $rows = rec9_service()->byAthlete(rec9_config());

    expect($rows->pluck('athlete_id')->all())->toBe([$alpha->id, $zeta->id]);
})->group('statistik-p9');

it('führt Rekorde ohne Athleten nicht in der Athletenliste', function () {
    rec9_record(['athlete_id' => rec9_athlete()->id]);
    rec9_record(['athlete_id' => null, 'relay_count' => 4]);

    expect(rec9_service()->byAthlete(rec9_config()))->toHaveCount(1);
})->group('statistik-p9');

it('berücksichtigt bei den Athletenrekorden nur den Berichtszeitraum', function () {
    $athlete = rec9_athlete();

    rec9_record(['athlete_id' => $athlete->id, 'set_date' => '2024-06-01']);
    rec9_record(['athlete_id' => $athlete->id, 'set_date' => '2023-06-01']);

    expect(rec9_service()->byAthlete(rec9_config())->first()['records'])->toBe(1);
})->group('statistik-p9');

it('enthält pro Athletenzeile die erwarteten Schlüssel', function () {
    rec9_record(['athlete_id' => rec9_athlete()->id]);

    expect(rec9_service()->byAthlete(rec9_config())->first())
        ->toHaveKeys(['rank', 'athlete_id', 'athlete', 'nation', 'records']);
})->group('statistik-p9');

it('liefert eine leere Collection, wenn keine Rekorde existieren (Athleten)', function () {
    expect(rec9_service()->byAthlete(rec9_config()))->toBeEmpty();
})->group('statistik-p9');

// ── Rekordarten-Aufstellung ──────────────────────────────────────────────────

it('gruppiert die Rekorde nach Rekordart und reiht sie absteigend', function () {
    rec9_record(['record_type' => 'AUT']);
    rec9_record(['record_type' => 'AUT']);
    rec9_record(['record_type' => 'AUT.JR']);

    $rows = rec9_service()->byRecordType(rec9_config());

    expect($rows->pluck('record_type')->all())->toBe(['AUT', 'AUT.JR'])
        ->and($rows[0]['records'])->toBe(2)
        ->and($rows[1]['records'])->toBe(1);
})->group('statistik-p9');

it('nimmt neue Rekordarten automatisch auf', function () {
    rec9_record(['record_type' => 'XYZ.NEU']);

    expect(rec9_service()->byRecordType(rec9_config())->pluck('record_type')->all())->toBe(['XYZ.NEU']);
})->group('statistik-p9');

it('liefert eine leere Collection, wenn keine Rekorde existieren (Rekordarten)', function () {
    expect(rec9_service()->byRecordType(rec9_config()))->toBeEmpty();
})->group('statistik-p9');

it('bezieht Veranstaltungen am ersten und letzten Tag des Zeitraums ein', function () {
    $club = stat2_club();

    stat2_start(stat2_athlete(), $club, stat2_meet(['start_date' => '2024-01-01']));
    stat2_start(stat2_athlete(), $club, stat2_meet(['start_date' => '2024-12-31']));

    $o = stat2_service()->overview(stat2_config());

    expect($o['meets'])->toBe(2)
        ->and($o['starts'])->toBe(2);
})->group('statistik-p2');

it('schließt Veranstaltungen einen Tag außerhalb des Zeitraums aus', function () {
    $club = stat2_club();

    stat2_start(stat2_athlete(), $club, stat2_meet(['start_date' => '2023-12-31']));
    stat2_start(stat2_athlete(), $club, stat2_meet(['start_date' => '2025-01-01']));

    expect(stat2_service()->overview(stat2_config())['meets'])->toBe(0);
})->group('statistik-p2');

it('führt Veranstaltungen am Zeitraumende auch in der Veranstaltungsstatistik', function () {
    $meet = stat2_meet(['start_date' => '2024-12-31', 'name' => 'Silvester Cup']);

    stat2_start(stat2_athlete(), stat2_club(), $meet);

    $rows = stat2_service()->byMeet(stat2_config());

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['meet'])->toBe('Silvester Cup');
})->group('statistik-p3');
