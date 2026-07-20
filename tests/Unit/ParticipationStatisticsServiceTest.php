<?php

/** @noinspection PhpUnhandledExceptionInspection Pest-Test-Closures fangen Exceptions selbst ab. */

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Services\ParticipationStatisticsService;
use App\Support\ReportConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────────────────────

function stat2_service(): ParticipationStatisticsService
{
    return new ParticipationStatisticsService;
}

function stat2_config(array $overrides = []): ReportConfiguration
{
    return ReportConfiguration::fromArray(array_merge(['year' => 2024], $overrides));
}

function stat2_nation(string $code = 'AUT'): Nation
{
    return Nation::firstOrCreate(
        ['code' => $code],
        ['name_de' => $code, 'name_en' => $code, 'is_active' => true]
    );
}

function stat2_club(string $nationCode = 'AUT'): Club
{
    return Club::create([
        'name' => 'Club '.uniqid(),
        'nation_id' => stat2_nation($nationCode)->id,
    ]);
}

function stat2_athlete(array $attrs = []): Athlete
{
    return Athlete::create(array_merge([
        'first_name' => 'Max',
        'last_name' => 'Muster',
        'gender' => 'M',
        'nation_id' => stat2_nation()->id,
        'is_active' => true,
    ], $attrs));
}

function stat2_meet(array $attrs = []): Meet
{
    return Meet::create(array_merge([
        'name' => 'Meet '.uniqid(),
        'nation_id' => stat2_nation()->id,
        'course' => 'LCM',
        'start_date' => '2024-06-01',
    ], $attrs));
}

function stat2_strokeType(): StrokeType
{
    return StrokeType::firstOrCreate(
        ['code' => 'FREE'],
        [
            'lenex_code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle',
            'category' => 'standard', 'is_active' => true,
        ]
    );
}

function stat2_event(Meet $meet, int $relayCount = 1): SwimEvent
{
    return SwimEvent::create([
        'meet_id' => $meet->id,
        'stroke_type_id' => stat2_strokeType()->id,
        'distance' => 100,
        'gender' => 'A',
        'relay_count' => $relayCount,
    ]);
}

/** Ein Start = ein Ergebnis in einem eigenen Bewerb (eigener SwimEvent, sofern nicht via 'event' übergeben). */
function stat2_start(Athlete $athlete, Club $club, Meet $meet, array $attrs = []): Result
{
    $event = $attrs['event'] ?? stat2_event($meet);
    unset($attrs['event']);

    return Result::create(array_merge([
        'meet_id' => $meet->id,
        'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id,
        'club_id' => $club->id,
        'sport_class' => 'S9',
        'swim_time' => 6000,
    ], $attrs));
}

// ── Teilnehmer- / Start- / Teilnahme-Definition (Spec Phase 2) ───────────────

it('zählt einen Athleten mit 5 Starts als 1 Teilnehmer und 5 Starts', function () {
    $meet = stat2_meet();
    $athlete = stat2_athlete();
    $club = stat2_club();

    for ($i = 0; $i < 5; $i++) {
        stat2_start($athlete, $club, $meet);
    }

    $o = stat2_service()->overview(stat2_config());

    expect($o['participants'])->toBe(1)
        ->and($o['starts'])->toBe(5);
})->group('statistik-p2');

it('zählt einen Athleten bei 3 Veranstaltungen als 3 Teilnahmen', function () {
    $athlete = stat2_athlete();
    $club = stat2_club();

    for ($i = 0; $i < 3; $i++) {
        stat2_start($athlete, $club, stat2_meet());
    }

    $o = stat2_service()->overview(stat2_config());

    expect($o['participations'])->toBe(3)
        ->and($o['participants'])->toBe(1)
        ->and($o['meets'])->toBe(3);
})->group('statistik-p2');

it('bildet das Spec-Beispiel ab: 5+3 Starts bei 2 Meets = 1 Teilnehmer, 2 Teilnahmen, 8 Starts', function () {
    $athlete = stat2_athlete();
    $club = stat2_club();
    $meet1 = stat2_meet();
    $meet2 = stat2_meet();

    for ($i = 0; $i < 5; $i++) {
        stat2_start($athlete, $club, $meet1);
    }
    for ($i = 0; $i < 3; $i++) {
        stat2_start($athlete, $club, $meet2);
    }

    $o = stat2_service()->overview(stat2_config());

    expect($o['participants'])->toBe(1)
        ->and($o['participations'])->toBe(2)
        ->and($o['starts'])->toBe(8)
        ->and($o['meets'])->toBe(2);
})->group('statistik-p2');

// ── Start-Definition B: angetreten vs. nicht angetreten ──────────────────────

it('zählt reguläre, DSQ-, DNF- und EXH-Ergebnisse als Start, DNS/SICK/WDR jedoch nicht', function () {
    $meet = stat2_meet();
    $club = stat2_club();

    // Angetreten (zählen): reguläres Ergebnis (null), EXH, DSQ, DNF — je eigener Athlet.
    foreach ([null, 'EXH', 'DSQ', 'DNF'] as $status) {
        stat2_start(stat2_athlete(), $club, $meet, ['status' => $status]);
    }

    // Nicht angetreten (zählen nicht): DNS, SICK, WDR — typischerweise ohne Zeit.
    foreach (['DNS', 'SICK', 'WDR'] as $status) {
        stat2_start(stat2_athlete(), $club, $meet, ['status' => $status, 'swim_time' => null]);
    }

    $o = stat2_service()->overview(stat2_config());

    expect($o['starts'])->toBe(4)
        ->and($o['participants'])->toBe(4);
})->group('statistik-p2');

// ── Vereinsstatistik ─────────────────────────────────────────────────────────

it('trennt österreichische und ausländische Vereine', function () {
    $meet = stat2_meet();

    stat2_start(stat2_athlete(), stat2_club(), $meet);
    stat2_start(stat2_athlete(), stat2_club(), $meet);
    stat2_start(stat2_athlete(), stat2_club('GER'), $meet);

    $o = stat2_service()->overview(stat2_config());

    expect($o['clubs'])->toBe(2)
        ->and($o['foreign_clubs'])->toBe(1);
})->group('statistik-p2');

it('zählt denselben Verein bei mehreren Athleten nur einmal', function () {
    $meet = stat2_meet();
    $club = stat2_club();

    stat2_start(stat2_athlete(), $club, $meet);
    stat2_start(stat2_athlete(), $club, $meet);

    $o = stat2_service()->overview(stat2_config());

    expect($o['clubs'])->toBe(1);
})->group('statistik-p2');

// ── Staffeln ausklammern ─────────────────────────────────────────────────────

it('klammert Staffelergebnisse (relay_count > 1) aus der Startzählung aus', function () {
    $meet = stat2_meet();
    $club = stat2_club();
    $athlete = stat2_athlete();

    stat2_start($athlete, $club, $meet); // Einzelstart
    stat2_start($athlete, $club, $meet, ['event' => stat2_event($meet, relayCount: 4)]); // Staffel

    $o = stat2_service()->overview(stat2_config());

    expect($o['starts'])->toBe(1);
})->group('statistik-p2');

// ── Auswertungsumfang: Meet-Auswahl vs. Zeitraum ─────────────────────────────

it('berücksichtigt bei gesetzten meet_ids nur die ausgewählten Veranstaltungen', function () {
    $club = stat2_club();
    $inMeet = stat2_meet();
    $outMeet = stat2_meet();

    stat2_start(stat2_athlete(), $club, $inMeet);
    stat2_start(stat2_athlete(), $club, $outMeet);

    $o = stat2_service()->overview(stat2_config(['meet_ids' => [$inMeet->id]]));

    expect($o['meets'])->toBe(1)
        ->and($o['starts'])->toBe(1);
})->group('statistik-p2');

it('berücksichtigt im Zeitraum-Modus nur Meets mit start_date im Zeitraum', function () {
    $club = stat2_club();
    $inMeet = stat2_meet(['start_date' => '2024-05-01']);
    $outMeet = stat2_meet(['start_date' => '2023-05-01']);

    stat2_start(stat2_athlete(), $club, $inMeet);
    stat2_start(stat2_athlete(), $club, $outMeet);

    $o = stat2_service()->overview(stat2_config(['year' => 2024]));

    expect($o['meets'])->toBe(1)
        ->and($o['starts'])->toBe(1);
})->group('statistik-p2');

it('zählt Veranstaltungen ohne relevante Starts nicht mit', function () {
    $club = stat2_club();
    $withStart = stat2_meet();
    $empty = stat2_meet();

    stat2_start(stat2_athlete(), $club, $withStart);

    $o = stat2_service()->overview(stat2_config(['meet_ids' => [$withStart->id, $empty->id]]));

    expect($o['meets'])->toBe(1);
})->group('statistik-p2');

// ── Leerer Datenbestand ──────────────────────────────────────────────────────

it('liefert für einen leeren Zeitraum durchgehend Nullwerte', function () {
    $o = stat2_service()->overview(stat2_config());

    expect($o)->toBe([
        'meets' => 0,
        'participants' => 0,
        'clubs' => 0,
        'foreign_clubs' => 0,
        'starts' => 0,
        'participations' => 0,
    ]);
})->group('statistik-p2');

// ── Status-Aufschlüsselung (DNS/SICK/WDR/DSQ/DNF/EXH + regulär) ───────────────

it('schlüsselt die Ergebnisse nach Status auf (inkl. regulär, DNS, SICK, WDR)', function () {
    $meet = stat2_meet();
    $club = stat2_club();

    for ($i = 0; $i < 3; $i++) {
        stat2_start(stat2_athlete(), $club, $meet); // regulär (status = null)
    }
    foreach ([['EXH', 1], ['DSQ', 2], ['DNF', 1], ['DNS', 4], ['SICK', 2], ['WDR', 1]] as [$status, $count]) {
        for ($i = 0; $i < $count; $i++) {
            stat2_start(stat2_athlete(), $club, $meet, ['status' => $status, 'swim_time' => null]);
        }
    }

    $b = stat2_service()->statusBreakdown(stat2_config());

    expect($b)->toBe([
        'regular' => 3, 'EXH' => 1, 'DSQ' => 2, 'DNS' => 4, 'DNF' => 1, 'SICK' => 2, 'WDR' => 1,
    ]);
})->group('statistik-p2');

it('stimmt mit overview() überein: Summe = alle Ergebnisse, regular+EXH+DSQ+DNF = Starts', function () {
    $meet = stat2_meet();
    $club = stat2_club();

    stat2_start(stat2_athlete(), $club, $meet);                                   // regulär
    stat2_start(stat2_athlete(), $club, $meet);                                   // regulär
    stat2_start(stat2_athlete(), $club, $meet, ['status' => 'DSQ', 'swim_time' => null]);
    stat2_start(stat2_athlete(), $club, $meet, ['status' => 'DNF', 'swim_time' => null]);
    stat2_start(stat2_athlete(), $club, $meet, ['status' => 'EXH']);
    stat2_start(stat2_athlete(), $club, $meet, ['status' => 'DNS', 'swim_time' => null]);
    stat2_start(stat2_athlete(), $club, $meet, ['status' => 'WDR', 'swim_time' => null]);

    $svc = stat2_service();
    $b = $svc->statusBreakdown(stat2_config());
    $o = $svc->overview(stat2_config());

    expect(array_sum($b))->toBe(7)
        ->and($b['regular'] + $b['EXH'] + $b['DSQ'] + $b['DNF'])->toBe($o['starts'])
        ->and($o['starts'])->toBe(5);
})->group('statistik-p2');

it('zählt bei der Status-Aufschlüsselung nur Meets im Zeitraum', function () {
    $club = stat2_club();
    $in = stat2_meet(['start_date' => '2024-05-01']);
    $out = stat2_meet(['start_date' => '2023-05-01']);

    stat2_start(stat2_athlete(), $club, $in, ['status' => 'DNS', 'swim_time' => null]);
    stat2_start(stat2_athlete(), $club, $out, ['status' => 'DNS', 'swim_time' => null]);

    $b = stat2_service()->statusBreakdown(stat2_config(['year' => 2024]));

    expect($b['DNS'])->toBe(1);
})->group('statistik-p2');

it('klammert Staffeln auch bei der Status-Aufschlüsselung aus', function () {
    $meet = stat2_meet();
    $club = stat2_club();

    stat2_start(stat2_athlete(), $club, $meet, ['status' => 'DNS', 'swim_time' => null]);
    stat2_start(stat2_athlete(), $club, $meet, [
        'status' => 'DNS', 'swim_time' => null, 'event' => stat2_event($meet, relayCount: 4),
    ]);

    $b = stat2_service()->statusBreakdown(stat2_config());

    expect($b['DNS'])->toBe(1);
})->group('statistik-p2');

it('liefert eine stabile Nullstruktur bei leerem Datenbestand (Status)', function () {
    $b = stat2_service()->statusBreakdown(stat2_config());

    expect($b)->toBe([
        'regular' => 0, 'EXH' => 0, 'DSQ' => 0, 'DNS' => 0, 'DNF' => 0, 'SICK' => 0, 'WDR' => 0,
    ]);
})->group('statistik-p2');

// ── Veranstaltungsstatistik (Spec Phase 3) ───────────────────────────────────

it('liefert pro Veranstaltung Teilnehmer und Starts', function () {
    $athlete = stat2_athlete();
    $club = stat2_club();
    $meet1 = stat2_meet(['name' => 'Meet Eins', 'start_date' => '2024-03-01']);
    $meet2 = stat2_meet(['name' => 'Meet Zwei', 'start_date' => '2024-06-01']);

    for ($i = 0; $i < 5; $i++) {
        stat2_start($athlete, $club, $meet1);
    }
    for ($i = 0; $i < 3; $i++) {
        stat2_start($athlete, $club, $meet2);
    }

    $byId = stat2_service()->byMeet(stat2_config())->keyBy('meet_id');

    expect($byId)->toHaveCount(2)
        ->and($byId[$meet1->id]['participants'])->toBe(1)
        ->and($byId[$meet1->id]['starts'])->toBe(5)
        ->and($byId[$meet2->id]['participants'])->toBe(1)
        ->and($byId[$meet2->id]['starts'])->toBe(3);
})->group('statistik-p3');

it('zählt einen Athleten je Veranstaltung nur einmal als Teilnehmer', function () {
    $meet = stat2_meet();
    $club = stat2_club();
    $a1 = stat2_athlete();
    $a2 = stat2_athlete();

    for ($i = 0; $i < 4; $i++) {
        stat2_start($a1, $club, $meet);
    }
    stat2_start($a2, $club, $meet);

    $row = stat2_service()->byMeet(stat2_config())->firstWhere('meet_id', $meet->id);

    expect($row['participants'])->toBe(2)
        ->and($row['starts'])->toBe(5);
})->group('statistik-p3');

it('berücksichtigt bei der Veranstaltungsstatistik nur Starts (DNS/SICK/WDR nicht)', function () {
    $meet = stat2_meet();
    $club = stat2_club();

    stat2_start(stat2_athlete(), $club, $meet);
    stat2_start(stat2_athlete(), $club, $meet, ['status' => 'DNS', 'swim_time' => null]);

    $row = stat2_service()->byMeet(stat2_config())->firstWhere('meet_id', $meet->id);

    expect($row['participants'])->toBe(1)
        ->and($row['starts'])->toBe(1);
})->group('statistik-p3');

it('führt Veranstaltungen ganz ohne Starts nicht auf', function () {
    $club = stat2_club();
    $withStart = stat2_meet();
    $onlyDns = stat2_meet();

    stat2_start(stat2_athlete(), $club, $withStart);
    stat2_start(stat2_athlete(), $club, $onlyDns, ['status' => 'DNS', 'swim_time' => null]);

    $rows = stat2_service()->byMeet(stat2_config());

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['meet_id'])->toBe($withStart->id);
})->group('statistik-p3');

it('sortiert die Veranstaltungen chronologisch nach start_date', function () {
    $club = stat2_club();
    $june = stat2_meet(['start_date' => '2024-06-01']);
    $march = stat2_meet(['start_date' => '2024-03-01']);
    $sept = stat2_meet(['start_date' => '2024-09-01']);

    foreach ([$june, $march, $sept] as $meet) {
        stat2_start(stat2_athlete(), $club, $meet);
    }

    $order = stat2_service()->byMeet(stat2_config())->pluck('meet_id')->all();

    expect($order)->toBe([$march->id, $june->id, $sept->id]);
})->group('statistik-p3');

it('beschränkt die Veranstaltungsstatistik auf ausgewählte meet_ids', function () {
    $club = stat2_club();
    $in = stat2_meet();
    $out = stat2_meet();

    stat2_start(stat2_athlete(), $club, $in);
    stat2_start(stat2_athlete(), $club, $out);

    $rows = stat2_service()->byMeet(stat2_config(['meet_ids' => [$in->id]]));

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['meet_id'])->toBe($in->id);
})->group('statistik-p3');

it('liefert eine leere Collection, wenn keine Starts existieren', function () {
    expect(stat2_service()->byMeet(stat2_config()))->toBeEmpty();
})->group('statistik-p3');

it('enthält pro Zeile die erwarteten Schlüssel inkl. start_date', function () {
    $club = stat2_club();
    $meet = stat2_meet(['name' => 'Test Cup', 'start_date' => '2024-04-15']);

    stat2_start(stat2_athlete(), $club, $meet);

    $row = stat2_service()->byMeet(stat2_config())->first();

    expect($row)->toHaveKeys(['meet_id', 'meet', 'start_date', 'participants', 'starts'])
        ->and($row['meet'])->toBe('Test Cup')
        ->and($row['start_date'])->toBe('2024-04-15');
})->group('statistik-p3');
