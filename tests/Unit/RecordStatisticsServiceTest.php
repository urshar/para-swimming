<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
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

function rec9_club(): Club
{
    return Club::firstOrCreate(['name' => 'Testverein'], ['nation_id' => rec9_nation()->id]);
}

function rec9_meet(string $name = 'Standardmeet', string $date = '2024-06-01'): Meet
{
    return Meet::firstOrCreate(
        ['name' => $name],
        ['nation_id' => rec9_nation()->id, 'course' => 'LCM', 'start_date' => $date],
    );
}

/** Ein Ergebnis an der Veranstaltung — der Anker, über den ein Rekord an einem Meet hängt. */
function rec9_result(Meet $meet): Result
{
    $event = SwimEvent::create([
        'meet_id' => $meet->id, 'stroke_type_id' => rec9_strokeType()->id,
        'distance' => 100, 'gender' => 'A', 'relay_count' => 1,
    ]);

    return Result::create([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id,
        'athlete_id' => rec9_athlete()->id, 'club_id' => rec9_club()->id,
        'sport_class' => 'S9', 'swim_time' => 6000,
    ]);
}

/**
 * Legt einen Rekord an. Sofern kein result_id vorgegeben ist, wird der Rekord
 * an einer Veranstaltung verankert (Standardmeet), damit er dem seit Erik
 * bestätigten Veranstaltungsbezug genügt und in der Auswertung mitzählt.
 * Über $meet lässt sich der Rekord einer bestimmten Veranstaltung zuordnen.
 */
function rec9_record(array $attrs = [], ?Meet $meet = null): SwimRecord
{
    if (! array_key_exists('result_id', $attrs)) {
        $attrs['result_id'] = rec9_result($meet ?? rec9_meet())->id;
    }

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

// ── Veranstaltungsbezug (Erik: nur Rekorde der ausgewählten Veranstaltungen) ──

it('zählt ohne Meet-Auswahl nur Rekorde mit Veranstaltungsbezug (Option B)', function () {
    rec9_record();                          // mit result_id (Standardmeet)
    rec9_record(['result_id' => null]);     // historischer Bestand ohne Bezug

    expect(rec9_service()->overview(rec9_config())['total'])->toBe(1);
})->group('statistik-p9');

it('zählt bei Meet-Auswahl nur Rekorde der gewählten Veranstaltungen', function () {
    $cup = rec9_meet('ÖBSV Cup Runde 1', '2024-03-02');
    $international = rec9_meet('World Series', '2024-05-01');

    rec9_record(meet: $cup);
    rec9_record(meet: $cup);
    rec9_record(meet: $international);

    $config = rec9_config(['meet_ids' => [$cup->id]]);

    expect(rec9_service()->overview($config)['total'])->toBe(2);
})->group('statistik-p9');

it('trennt die Rekorde verschiedener Veranstaltungen', function () {
    $cup = rec9_meet('ÖBSV Cup Runde 1', '2024-03-02');
    $other = rec9_meet('ÖBSV Cup Runde 2', '2024-04-06');

    rec9_record(meet: $cup);
    rec9_record(meet: $other);

    expect(rec9_service()->overview(rec9_config(['meet_ids' => [$cup->id]]))['total'])->toBe(1)
        ->and(rec9_service()->overview(rec9_config(['meet_ids' => [$other->id]]))['total'])->toBe(1)
        ->and(rec9_service()->overview(rec9_config(['meet_ids' => [$cup->id, $other->id]]))['total'])->toBe(2);
})->group('statistik-p9');

it('wirkt der Meet-Filter auch auf die Aufstellung je Sportler', function () {
    $cup = rec9_meet('ÖBSV Cup Runde 1', '2024-03-02');
    $other = rec9_meet('World Series', '2024-05-01');
    $anna = rec9_athlete(['first_name' => 'Anna', 'last_name' => 'Auer']);

    rec9_record(['athlete_id' => $anna->id], meet: $cup);
    rec9_record(['athlete_id' => $anna->id], meet: $other);

    $rows = rec9_service()->byAthlete(rec9_config(['meet_ids' => [$cup->id]]));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['records'])->toBe(1);
})->group('statistik-p9');

it('greift der Meet-Filter auch bei den Rekordarten', function () {
    $cup = rec9_meet('ÖBSV Cup Runde 1', '2024-03-02');
    $other = rec9_meet('World Series', '2024-05-01');

    rec9_record(['record_type' => 'AUT'], meet: $cup);
    rec9_record(['record_type' => 'WR'], meet: $other);

    $rows = rec9_service()->byRecordType(rec9_config(['meet_ids' => [$cup->id]]));

    expect($rows->pluck('record_type')->all())->toBe(['AUT']);
})->group('statistik-p9');

it('bleibt beim set_date-Jahr, auch wenn ein Meet gewählt ist', function () {
    $cup = rec9_meet('ÖBSV Cup Runde 1', '2024-03-02');

    rec9_record(['set_date' => '2024-06-01'], meet: $cup);
    rec9_record(['set_date' => '2023-06-01'], meet: $cup);

    expect(rec9_service()->overview(rec9_config(['meet_ids' => [$cup->id]]))['total'])->toBe(1);
})->group('statistik-p9');
