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
use App\Models\RelayResult;
use App\Models\SwimEvent;
use App\Models\User;
use App\Services\WorldAquaticsPointsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('relay-results-p2');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php). makeMeet_p5(): LCM, 15.06.2025.

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function admin_rpt(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

/**
 * Veranstaltung mit ÖBSV-Punkten (WA), Staffelbewerb 4x50 m Freistil und Basiszeiten 4x50FR/S14:
 * Herren 1:40,00, Damen 2:00,00, Mixed 1:50,00.
 *
 * @return array{0: Meet, 1: SwimEvent, 2: Club}
 */
function setup_rpt(): array
{
    $stroke = makeStrokeType_p5();
    $meet = makeMeet_p5();
    $club = makeClub_p5();
    $event = makeEvent_p5($meet, ['event_number' => 8, 'distance' => 50, 'relay_count' => 4, 'gender' => 'A', 'sport_classes' => null]);
    $meet->pointSystems()->attach(PointSystem::firstOrCreate(
        ['code' => PointSystem::CODE_WORLD_AQUATICS], ['name' => 'World Aquatics', 'active' => true]
    ));

    $version = BaseTimeVersion::create(['label' => 'Test', 'valid_from' => '2021-01-01', 'valid_until' => null]);
    $discipline = BaseTimeDiscipline::create(['code' => '4x50FR', 'distance' => 50, 'relay_count' => 4, 'stroke_type_id' => $stroke->id]);
    $sportClass = BaseTimeSportClass::create(['code' => 'S14', 'sort_order' => 14]);
    foreach (['M' => 10000, 'F' => 12000, 'X' => 11000] as $gender => $baseTime) {
        $category = BaseTimeCategory::create(['code' => 'LC_'.$gender, 'course' => 'LCM', 'gender' => $gender, 'label' => $gender]);
        BaseTime::create([
            'base_time_version_id' => $version->id, 'base_time_category_id' => $category->id,
            'base_time_discipline_id' => $discipline->id, 'base_time_sport_class_id' => $sportClass->id,
            'value_centiseconds' => $baseTime,
        ]);
    }

    return [$meet, $event, $club];
}

function relay_rpt(Meet $meet, SwimEvent $event, Club $club, string $gender, ?string $class, int $time, ?int $points): RelayResult
{
    return RelayResult::create([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'club_id' => $club->id,
        'gender' => $gender, 'relay_class' => $class, 'swim_time' => $time, 'points' => $points,
    ]);
}

// ── Berechnung ────────────────────────────────────────────────────────────────

it('berechnet ÖBSV-Punkte für Staffeln über Wertung und Staffelklasse', function () {
    [$meet, $event, $club] = setup_rpt();
    $service = app(WorldAquaticsPointsService::class);

    // 1000 × (Basiszeit / Zeit)³
    expect($service->calculatePoints(relay_rpt($meet, $event, $club, 'M', 'S14', 10000, null), $meet))->toBe(1000)
        ->and($service->calculatePoints(relay_rpt($meet, $event, $club, 'F', 'S14', 15000, null), $meet))->toBe(512)
        ->and($service->calculatePoints(relay_rpt($meet, $event, $club, 'X', 'S14', 13750, null), $meet))->toBe(512)
        ->and($service->resolvePoints(relay_rpt($meet, $event, $club, 'M', null, 12000, null), $meet)[1])
        ->toBe('Staffelklasse "" nicht erkennbar');
});

it('rechnet Staffeln bei "ÖBSV-Punkte berechnen" mit und meldet übersprungene', function () {
    [$meet, $event, $club] = setup_rpt();
    $men = relay_rpt($meet, $event, $club, 'M', 'S14', 12500, 999);
    $withoutClass = relay_rpt($meet, $event, $club, 'M', null, 12000, null);

    test()->actingAs(admin_rpt())
        ->post(route('meets.recalculate-points', $meet))
        ->assertSessionHas('success', fn (string $message) => str_contains($message, '1 Punktzahl(en) aktualisiert')
            && str_contains($message, 'Staffelklasse "" nicht erkennbar'));

    expect($men->fresh()->points)->toBe(512)
        ->and($withoutClass->fresh()->points)->toBeNull()
        ->and(app(WorldAquaticsPointsService::class)->findOutdatedResults($meet)->count())->toBe(0);
});

it('berechnet beim Erfassen die Punkte, ein eingetragener Wert bleibt stehen', function () {
    [$meet, $event, $club] = setup_rpt();
    $members = array_map(fn (string $g): Athlete => makeAthlete_p5($club, $g, ['S14']), ['M', 'F', 'F', 'M']);
    $form = [
        'swim_event_id' => $event->id,
        'club_id' => $club->id,
        'members' => array_map(fn (Athlete $a) => $a->id, $members),
        'swim_time' => '02:17.50',
    ];

    test()->actingAs(admin_rpt())->post(route('meets.relay-results.store', $meet), $form);
    test()->actingAs(admin_rpt())->post(route('meets.relay-results.store', $meet), $form + ['points' => 77]);

    expect(RelayResult::orderBy('id')->pluck('points')->all())->toBe([512, 77]);
});

it('zählt veraltete Staffelpunkte im Hinweis der Ergebnisübersicht mit', function () {
    [$meet, $event, $club] = setup_rpt();
    relay_rpt($meet, $event, $club, 'F', 'S14', 15000, 600);

    expect(app(WorldAquaticsPointsService::class)->findOutdatedResults($meet)->count())->toBe(1);
});
