<?php

use App\Models\Athlete;
use App\Models\BaseTime;
use App\Models\BaseTimeCategory;
use App\Models\BaseTimeDiscipline;
use App\Models\BaseTimeSportClass;
use App\Models\BaseTimeVersion;
use App\Models\Club;
use App\Models\Meet;
use App\Models\PointSystem;
use App\Models\Result;
use App\Models\SwimEvent;
use App\Models\User;
use App\Services\MeetResultListService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('result-entry-automation');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function admin_rea(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

/** Veranstaltung (LCM, 15.06.2025) mit 100 m Freistil Herren, Verein und Athlet S9/SB8. */
function setup_rea(): array
{
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $athlete = makeAthlete_p5($club, 'M', ['S9', 'SB8']);
    $event = makeEvent_p5($meet, ['event_number' => 1]);

    return [$meet, $club, $athlete, $event];
}

/** Basiszeit 60,00 s für LCM Herren 100 m Freistil S9, gültig am Wettkampfdatum. */
function baseTime_rea(SwimEvent $event): void
{
    $version = BaseTimeVersion::create(['label' => 'V1', 'valid_from' => '2025-01-01', 'valid_until' => '2025-12-31']);
    $category = BaseTimeCategory::create(['code' => 'LC_MEN', 'course' => 'LCM', 'gender' => 'M', 'label' => 'LC Men']);
    $discipline = BaseTimeDiscipline::create([
        'code' => '100FR', 'distance' => 100, 'relay_count' => 1, 'stroke_type_id' => $event->stroke_type_id,
    ]);
    $sportClass = BaseTimeSportClass::create(['code' => 'S9', 'sort_order' => 1]);
    BaseTime::create([
        'base_time_version_id' => $version->id, 'base_time_category_id' => $category->id,
        'base_time_discipline_id' => $discipline->id, 'base_time_sport_class_id' => $sportClass->id,
        'value_centiseconds' => 6000, 'value_type' => BaseTime::TYPE_MANUAL,
    ]);
}

function enableWa_rea(Meet $meet): void
{
    $system = PointSystem::firstOrCreate(
        ['code' => PointSystem::CODE_WORLD_AQUATICS],
        ['name' => 'World Aquatics Punkte', 'active' => true],
    );
    $meet->pointSystems()->attach($system->id);
}

function payload_rea(SwimEvent $event, Athlete $athlete, Club $club, array $extra): array
{
    return array_merge([
        'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id,
        'club_id' => $club->id,
        'swim_time' => '01:05.00',
    ], $extra);
}

function result_rea(Meet $meet, SwimEvent $event, Athlete $athlete, ?int $time, ?string $class, ?string $status): Result
{
    return Result::create([
        'meet_id' => $meet->id,
        'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id,
        'club_id' => $athlete->club_id,
        'swim_time' => $time,
        'sport_class' => $class,
        'status' => $status,
    ]);
}

/** @return list<array{label: string, rows: list<array{place: ?int, result: Result}>}> */
function groups_rea(Meet $meet): array
{
    return app(MeetResultListService::class)->byEvent($meet, null)->first()['groups'];
}

// ── Sportklasse ───────────────────────────────────────────────────────────────

it('übernimmt bei leerer Sportklasse die zur Lage passende Klasse des Athleten', function () {
    [$meet, $club, $athlete, $event] = setup_rea();

    $this->actingAs(admin_rea())
        ->post(route('meets.results.store', $meet), payload_rea($event, $athlete, $club, []));

    expect(Result::first()->sport_class)->toBe('S9');
});

it('behält eine eingetragene Sportklasse und leitet sie beim Bearbeiten nur bei leerem Feld ab', function () {
    [$meet, $club, $athlete, $event] = setup_rea();
    $admin = admin_rea();

    $this->actingAs($admin)
        ->post(route('meets.results.store', $meet), payload_rea($event, $athlete, $club, ['sport_class' => 'S10']));
    $result = Result::first();
    expect($result->sport_class)->toBe('S10');

    $this->put(route('results.update', $result), payload_rea($event, $athlete, $club, ['sport_class' => '']));
    expect($result->fresh()->sport_class)->toBe('S9');
});

// ── Punkte ────────────────────────────────────────────────────────────────────

it('berechnet beim Speichern die Punkte, wenn World Aquatics für die Veranstaltung aktiviert ist', function () {
    [$meet, $club, $athlete, $event] = setup_rea();
    baseTime_rea($event);
    enableWa_rea($meet);

    $this->actingAs(admin_rea())
        ->post(route('meets.results.store', $meet), payload_rea($event, $athlete, $club, []))
        ->assertSessionHas('success', 'Ergebnis gespeichert.');

    expect(Result::first()->points)->toBe((int) round(1000 * (60 / 65) ** 3));
});

it('rechnet ohne aktiviertes Punktesystem nicht', function () {
    [$meet, $club, $athlete, $event] = setup_rea();
    baseTime_rea($event);

    $this->actingAs(admin_rea())
        ->post(route('meets.results.store', $meet), payload_rea($event, $athlete, $club, []));

    expect(Result::first()->points)->toBeNull();
});

it('behält manuell eingetragene Punkte und nennt den Grund, wenn nicht gerechnet werden kann', function () {
    [$meet, $club, $athlete, $event] = setup_rea();
    baseTime_rea($event);
    enableWa_rea($meet);
    $admin = admin_rea();

    $this->actingAs($admin)
        ->post(route('meets.results.store', $meet), payload_rea($event, $athlete, $club, ['points' => '500']));
    expect(Result::first()->points)->toBe(500);

    $this->post(route('meets.results.store', $meet), payload_rea($event, makeAthlete_p5($club), $club, [
        'swim_time' => '', 'status' => 'DNS',
    ]))->assertSessionHas('success', 'Ergebnis gespeichert. Punkte nicht berechnet: keine gültige Schwimmzeit.');
});

it('rechnet beim Bearbeiten neu, wenn die Punkte unverändert mitgeschickt werden, und behält einen geänderten Wert', function () {
    [$meet, $club, $athlete, $event] = setup_rea();
    baseTime_rea($event);
    enableWa_rea($meet);
    $admin = admin_rea();

    $this->actingAs($admin)
        ->post(route('meets.results.store', $meet), payload_rea($event, $athlete, $club, []));
    $result = Result::first();
    $oldPoints = $result->points;

    // Zeit korrigiert, Punktefeld unverändert vorbelegt → neu gerechnet
    $this->put(route('results.update', $result), payload_rea($event, $athlete, $club, [
        'swim_time' => '01:00.00', 'points' => (string) $oldPoints,
    ]));
    expect($result->fresh()->points)->toBe(1000);

    // Punkte bewusst geändert → bleiben
    $this->put(route('results.update', $result), payload_rea($event, $athlete, $club, ['points' => '777']));
    expect($result->fresh()->points)->toBe(777);
});

// ── Ergebnisliste (PDF) ───────────────────────────────────────────────────────

it('gruppiert die Ergebnisliste nach Sportklasse mit eigener Platzierung und gleichem Platz bei gleicher Zeit', function () {
    [$meet, $club, , $event] = setup_rea();
    $a = result_rea($meet, $event, makeAthlete_p5($club), 6500, 'S9', null);
    $b = result_rea($meet, $event, makeAthlete_p5($club), 6400, 'S9', null);
    $c = result_rea($meet, $event, makeAthlete_p5($club), 6500, 'S9', null);
    $dns = result_rea($meet, $event, makeAthlete_p5($club), null, 'S9', 'DNS');
    $ak = result_rea($meet, $event, makeAthlete_p5($club), 6000, 'S9', 'EXH');
    $s10 = result_rea($meet, $event, makeAthlete_p5($club), 7000, 'S10', null);
    $s4 = result_rea($meet, $event, makeAthlete_p5($club), 9000, 'S4', null);

    $groups = groups_rea($meet);
    $s9 = collect($groups)->firstWhere('label', 'Sportklasse S9')['rows'];

    expect(array_column($groups, 'label'))->toBe(['Sportklasse S4', 'Sportklasse S9', 'Sportklasse S10'])
        ->and(array_column($s9, 'place'))->toBe([1, 2, 2, null, null])
        ->and(array_map(fn (array $r) => $r['result']->id, $s9))
        ->sequence(
            fn ($id) => $id->toBe($b->id),
            fn ($id) => $id->toBeIn([$a->id, $c->id]),
            fn ($id) => $id->toBeIn([$a->id, $c->id]),
            fn ($id) => $id->toBe($ak->id),
            fn ($id) => $id->toBe($dns->id),
        )
        ->and($groups[0]['rows'][0]['result']->id)->toBe($s4->id)
        ->and($groups[2]['rows'][0]['place'])->toBe(1)
        ->and($groups[2]['rows'][0]['result']->id)->toBe($s10->id);
});

it('liefert die Ergebnisliste als PDF, auch nur für eine Disziplin', function () {
    [$meet, , $athlete, $event] = setup_rea();
    result_rea($meet, $event, $athlete, 6500, 'S9', null);

    $this->actingAs(admin_rea())
        ->get(route('meets.results-overview.pdf', ['meet' => $meet, 'event_id' => $event->id]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

// ── Zugriffsschutz ────────────────────────────────────────────────────────────

it('sperrt die Ergebnisverwaltung für Vereinsnutzer', function () {
    [$meet, $club, $athlete, $event] = setup_rea();
    $result = result_rea($meet, $event, $athlete, 6500, 'S9', null);
    $clubUser = User::factory()->create(['is_admin' => false, 'club_id' => $club->id]);

    $this->actingAs($clubUser);
    $this->get(route('results.index'))->assertForbidden();
    $this->get(route('results.show', $result))->assertForbidden();
    $this->get(route('results.edit', $result))->assertForbidden();
    $this->get(route('meets.results.create', $meet))->assertForbidden();
    $this->get(route('meets.results-overview.pdf', $meet))->assertForbidden();
    $this->post(route('meets.results.store', $meet), payload_rea($event, $athlete, $club, []))->assertForbidden();
    $this->put(route('results.update', $result), payload_rea($event, $athlete, $club, []))->assertForbidden();
    $this->delete(route('results.destroy', $result))->assertForbidden();

    expect(Result::count())->toBe(1);
});
