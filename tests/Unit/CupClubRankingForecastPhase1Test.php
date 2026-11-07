<?php

use App\Models\Athlete;
use App\Models\BaseTimeVersion;
use App\Models\Club;
use App\Models\Cup;
use App\Models\CupDailyResult;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\SportClassGroup;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Services\PerformanceBasedClubRankingService;
use App\Services\StartBasedClubRankingService;
use App\Support\ClubRankingConfiguration;
use App\Support\PerformanceClubRankingResult;
use App\Support\StartClubRankingResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/*
 * Prognose der Vereinswertung, Phase 1 (docs/specs/cup-club-ranking-forecast.md): Die
 * Wertungsberechnung beider Services ist von der Datenbeschaffung getrennt. Geprüft wird, dass
 * die neuen Einstiegspunkte rankStarts()/rankDailyResults() dasselbe liefern wie getRanking()
 * und zusätzlich übergebene (prognostizierte) Zeilen wie echte behandeln.
 */
uses(TestCase::class, RefreshDatabase::class)->group('cup-club-ranking-forecast-p1');

// ── Helpers ──────────────────────────────────────────────────────────────────

function nextSeq_fc1(): int
{
    static $n = 0;

    return ++$n;
}

function config_fc1(int $meets, int $athletes): ClubRankingConfiguration
{
    return new ClubRankingConfiguration(
        countedMeetsPerAthlete: $meets,
        maxCountedAthletesPerClub: $athletes,
        weights: [1 => 1.0, 2 => 0.8, 3 => 0.6, 4 => 0.4, 5 => 0.2],
        includeForeignClubs: false,
        restrictedKaderTypeCodes: [],
        countedKaderAthletesPerClub: 0,
    );
}

function makeNation_fc1(string $code): Nation
{
    return Nation::firstOrCreate(
        ['code' => $code],
        ['name_de' => $code, 'name_en' => $code, 'is_active' => true]
    );
}

function makeClub_fc1(string $name, string $nationCode): Club
{
    return Club::create(['name' => $name, 'nation_id' => makeNation_fc1($nationCode)->id]);
}

function makeAthlete_fc1(): Athlete
{
    $seq = nextSeq_fc1();

    return Athlete::create([
        'first_name' => "Vorname$seq",
        'last_name' => "Nachname$seq",
        'gender' => 'M',
        'nation_id' => makeNation_fc1('AUT')->id,
        'is_active' => true,
    ]);
}

function makeCup_fc1(): Cup
{
    $version = BaseTimeVersion::create(['label' => 'V2026', 'valid_from' => '2026-01-01']);

    return Cup::create([
        'year' => 2026,
        'name' => 'ÖBSV Cup 2026',
        'base_time_version_id' => $version->id,
        'rounds_count' => 1,
        'best_of_count' => 3,
    ]);
}

function makeMeet_fc1(Cup $cup): Meet
{
    return Meet::create([
        'name' => 'Meet '.nextSeq_fc1(),
        'nation_id' => makeNation_fc1('AUT')->id,
        'start_date' => '2026-05-01',
        'cup_id' => $cup->id,
    ]);
}

function makeEvent_fc1(Meet $meet, int $distance): SwimEvent
{
    $stroke = StrokeType::firstOrCreate(
        ['code' => 'FREE'],
        ['lenex_code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle']
    );

    return SwimEvent::create([
        'meet_id' => $meet->id,
        'stroke_type_id' => $stroke->id,
        'distance' => $distance,
        'round' => 'TIM',
        'relay_count' => 1,
    ]);
}

function makeResult_fc1(Meet $meet, SwimEvent $event, Athlete $athlete, Club $club): Result
{
    return Result::create([
        'meet_id' => $meet->id,
        'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id,
        'club_id' => $club->id,
        'swim_time' => 10000,
        'status' => null,
        'sport_class' => 'S9',
    ]);
}

function makeDaily_fc1(Cup $cup, Meet $meet, Athlete $athlete, Club $club, int $points): CupDailyResult
{
    $result = makeResult_fc1($meet, makeEvent_fc1($meet, 100), $athlete, $club);
    $group = SportClassGroup::firstOrCreate(
        ['code' => 'PI'],
        ['name_de' => 'Körperbehinderung', 'sort_order' => 1]
    );

    return CupDailyResult::create([
        'cup_id' => $cup->id,
        'meet_id' => $meet->id,
        'athlete_id' => $athlete->id,
        'club_id' => $club->id,
        'result_id' => $result->id,
        'sport_class_group_id' => $group->id,
        'gender' => 'M',
        'points' => $points,
        'calculated_at' => now(),
    ]);
}

/** Prognostizierte Tageswertungs-Zeile im Format von loadDailyResults() (ohne DB-Zeile). */
function projectedDaily_fc1(Athlete $athlete, Club $club, Meet $meet, int $points): stdClass
{
    return (object) [
        'athlete_id' => $athlete->id,
        'club_id' => $club->id,
        'meet_id' => $meet->id,
        'points' => $points,
    ];
}

/**
 * Vergleichbare Darstellung einer Startwertung.
 *
 * @param  Collection<int, StartClubRankingResult>  $ranking
 * @return list<array{int, string, int, int, int}>
 */
function startRows_fc1(Collection $ranking): array
{
    return $ranking
        ->map(fn (StartClubRankingResult $r): array => [$r->rank, $r->clubName, $r->starts, $r->athletes, $r->meets])
        ->all();
}

/**
 * Vergleichbare Darstellung einer Leistungswertung.
 *
 * @param  Collection<int, PerformanceClubRankingResult>  $ranking
 * @return list<array{int, string, float, int, int}>
 */
function performanceRows_fc1(Collection $ranking): array
{
    return $ranking
        ->map(fn (PerformanceClubRankingResult $r): array => [
            $r->rank, $r->clubName, $r->totalPoints, $r->countedAthletes, $r->countedMeets,
        ])
        ->all();
}

// ── Startwertung ─────────────────────────────────────────────────────────────

it('liefert über rankStarts() mit den geladenen Starts dieselbe Startwertung wie getRanking()', function () {
    $cup = makeCup_fc1();
    $meetA = makeMeet_fc1($cup);
    $meetB = makeMeet_fc1($cup);
    $clubA = makeClub_fc1('Verein A', 'AUT');
    $clubB = makeClub_fc1('Verein B', 'AUT');
    $athlete1 = makeAthlete_fc1();
    $athlete2 = makeAthlete_fc1();

    makeResult_fc1($meetA, makeEvent_fc1($meetA, 50), $athlete1, $clubA);
    makeResult_fc1($meetA, makeEvent_fc1($meetA, 100), $athlete1, $clubA);
    makeResult_fc1($meetB, makeEvent_fc1($meetB, 50), $athlete2, $clubB);

    $service = new StartBasedClubRankingService;
    $starts = $service->distinctStarts(collect([$meetA->id, $meetB->id]));

    expect(startRows_fc1($service->rankStarts($starts, false)))
        ->toBe(startRows_fc1($service->getRanking($cup, false)))
        ->and(startRows_fc1($service->rankStarts($starts, false)))
        ->toBe([[1, 'Verein A', 2, 1, 1], [2, 'Verein B', 1, 1, 1]]);
});

it('wertet über rankStarts() zusätzlich übergebene Starts wie echte', function () {
    $cup = makeCup_fc1();
    $meetReal = makeMeet_fc1($cup);
    $meetForecast = makeMeet_fc1($cup);
    $clubA = makeClub_fc1('Verein A', 'AUT');
    $clubB = makeClub_fc1('Verein B', 'AUT');
    $athlete1 = makeAthlete_fc1();
    $athlete2 = makeAthlete_fc1();

    makeResult_fc1($meetReal, makeEvent_fc1($meetReal, 50), $athlete1, $clubA);

    $service = new StartBasedClubRankingService;
    $starts = $service->distinctStarts(collect([$meetReal->id]))->concat([
        ['club_id' => $clubB->id, 'athlete_id' => $athlete2->id, 'meet_id' => $meetForecast->id],
        ['club_id' => $clubB->id, 'athlete_id' => $athlete2->id, 'meet_id' => $meetForecast->id],
    ]);

    expect(startRows_fc1($service->rankStarts($starts, false)))
        ->toBe([[1, 'Verein B', 2, 1, 1], [2, 'Verein A', 1, 1, 1]]);
});

it('schließt in rankStarts() ausländische Vereine nur ohne Ausland-Schalter aus', function () {
    $foreign = makeClub_fc1('Gastverein', 'GER');
    $athlete = makeAthlete_fc1();
    $meet = makeMeet_fc1(makeCup_fc1());
    $starts = collect([['club_id' => $foreign->id, 'athlete_id' => $athlete->id, 'meet_id' => $meet->id]]);

    $service = new StartBasedClubRankingService;

    expect($service->rankStarts($starts, false))->toBeEmpty()
        ->and(startRows_fc1($service->rankStarts($starts, true)))->toBe([[1, 'Gastverein', 1, 1, 1]])
        ->and($service->rankStarts(collect(), true))->toBeEmpty();
});

// ── Leistungswertung ─────────────────────────────────────────────────────────

it('liefert über rankDailyResults() mit loadDailyResults() dieselbe Leistungswertung wie getRanking()', function () {
    $cup = makeCup_fc1();
    $meet = makeMeet_fc1($cup);
    $clubA = makeClub_fc1('Verein A', 'AUT');
    $clubB = makeClub_fc1('Verein B', 'AUT');

    makeDaily_fc1($cup, $meet, makeAthlete_fc1(), $clubA, 800);
    makeDaily_fc1($cup, $meet, makeAthlete_fc1(), $clubA, 600);
    makeDaily_fc1($cup, $meet, makeAthlete_fc1(), $clubB, 900);

    $service = new PerformanceBasedClubRankingService;
    $config = config_fc1(3, 5);

    expect(performanceRows_fc1($service->rankDailyResults($cup, $service->loadDailyResults($cup), $config)))
        ->toBe(performanceRows_fc1($service->getRanking($cup, $config)))
        ->and(performanceRows_fc1($service->getRanking($cup, $config)))
        ->toBe([[1, 'Verein A', 1280.0, 2, 1], [2, 'Verein B', 900.0, 1, 1]]);
});

it('wertet über rankDailyResults() prognostizierte Zeilen ohne DB-Eintrag wie echte', function () {
    $cup = makeCup_fc1();
    $meetReal = makeMeet_fc1($cup);
    $meetForecast = makeMeet_fc1($cup);
    $clubA = makeClub_fc1('Verein A', 'AUT');
    $clubB = makeClub_fc1('Verein B', 'AUT');
    $athleteA = makeAthlete_fc1();
    $athleteB = makeAthlete_fc1();

    makeDaily_fc1($cup, $meetReal, $athleteA, $clubA, 700);
    makeDaily_fc1($cup, $meetReal, $athleteB, $clubB, 650);

    $service = new PerformanceBasedClubRankingService;
    $rows = $service->loadDailyResults($cup)->concat([projectedDaily_fc1($athleteB, $clubB, $meetForecast, 600)]);
    $ranking = $service->rankDailyResults($cup, $rows, config_fc1(3, 5));

    expect(performanceRows_fc1($ranking))->toBe([[1, 'Verein B', 1250.0, 1, 2], [2, 'Verein A', 700.0, 1, 1]])
        ->and($ranking->first()->athletes[0]->meetPoints)->toBe([650, 600]);
});

it('wendet die Beschränkung auf die besten Meets über echte und prognostizierte Zeilen gemeinsam an', function () {
    $cup = makeCup_fc1();
    $meet1 = makeMeet_fc1($cup);
    $meet2 = makeMeet_fc1($cup);
    $meetForecast = makeMeet_fc1($cup);
    $club = makeClub_fc1('Verein A', 'AUT');
    $athlete = makeAthlete_fc1();

    makeDaily_fc1($cup, $meet1, $athlete, $club, 500);
    makeDaily_fc1($cup, $meet2, $athlete, $club, 700);

    $service = new PerformanceBasedClubRankingService;
    $rows = $service->loadDailyResults($cup)->concat([projectedDaily_fc1($athlete, $club, $meetForecast, 800)]);
    $ranking = $service->rankDailyResults($cup, $rows, config_fc1(2, 5));

    expect($ranking)->toHaveCount(1)
        ->and($ranking->first()->totalPoints)->toBe(1500.0)
        ->and($ranking->first()->athletes[0]->meetPoints)->toBe([800, 700]);
});

it('liefert über rankDailyResults() ohne Zeilen eine leere Wertung', function () {
    expect((new PerformanceBasedClubRankingService)->rankDailyResults(makeCup_fc1(), collect(), config_fc1(3, 5)))
        ->toBeEmpty();
});
