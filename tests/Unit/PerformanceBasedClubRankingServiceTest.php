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
use App\Services\CupClubRankingService;
use App\Services\PerformanceBasedClubRankingService;
use App\Support\ClubRankingConfiguration;
use App\Support\PerformanceClubRankingResult;
use App\Support\StartClubRankingResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('performance-based-club-ranking-p2');

// ── Helpers ──────────────────────────────────────────────────────────────────

function service_pcr2(): PerformanceBasedClubRankingService
{
    return new PerformanceBasedClubRankingService;
}

function nextSeq_pcr2(): int
{
    static $n = 0;

    return ++$n;
}

function config_pcr2(int $meets = 3, int $athletes = 5, bool $foreign = false): ClubRankingConfiguration
{
    return new ClubRankingConfiguration(
        countedMeetsPerAthlete: $meets,
        maxCountedAthletesPerClub: $athletes,
        weights: [1 => 1.0, 2 => 0.8, 3 => 0.6, 4 => 0.4, 5 => 0.2],
        includeForeignClubs: $foreign,
    );
}

function makeNation_pcr2(string $code): Nation
{
    return Nation::firstOrCreate(
        ['code' => $code],
        ['name_de' => $code, 'name_en' => $code, 'is_active' => true]
    );
}

function makeSportClassGroup_pcr2(): SportClassGroup
{
    return SportClassGroup::firstOrCreate(
        ['code' => 'PI'],
        ['name_de' => 'Körperbehinderung', 'sort_order' => 1]
    );
}

function makeClub_pcr2(string $name, string $nationCode = 'AUT'): Club
{
    return Club::create(['name' => $name, 'nation_id' => makeNation_pcr2($nationCode)->id]);
}

function makeAthlete_pcr2(): Athlete
{
    $seq = nextSeq_pcr2();

    return Athlete::create([
        'first_name' => "Vorname$seq",
        'last_name' => "Nachname$seq",
        'gender' => 'M',
        'nation_id' => makeNation_pcr2('AUT')->id,
        'is_active' => true,
    ]);
}

function makeCup_pcr2(int $year = 2026): Cup
{
    $version = BaseTimeVersion::create(['label' => "V$year", 'valid_from' => "$year-01-01"]);

    return Cup::create([
        'year' => $year,
        'name' => "ÖBSV Cup $year",
        'base_time_version_id' => $version->id,
        'rounds_count' => 1,
        'best_of_count' => 3,
    ]);
}

function makeMeet_pcr2(Cup $cup): Meet
{
    return Meet::create([
        'name' => 'Meet '.nextSeq_pcr2(),
        'nation_id' => makeNation_pcr2('AUT')->id,
        'start_date' => "$cup->year-05-01",
        'cup_id' => $cup->id,
    ]);
}

function makeEvent_pcr2(Meet $meet): SwimEvent
{
    $stroke = StrokeType::firstOrCreate(
        ['code' => 'FREE'],
        ['lenex_code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle']
    );

    return SwimEvent::create([
        'meet_id' => $meet->id,
        'stroke_type_id' => $stroke->id,
        'distance' => 100,
        'round' => 'TIM',
        'relay_count' => 1,
    ]);
}

/**
 * Legt eine Tageswertungs-Zeile inkl. zugrunde liegendem Result an. $status
 * = null → reguläres Ergebnis; 'EXH' → außer Konkurrenz (darf nicht werten).
 */
function makeDaily_pcr2(
    Cup $cup,
    Meet $meet,
    Athlete $athlete,
    Club $club,
    int $points,
    ?string $status = null
): CupDailyResult {
    $result = Result::create([
        'meet_id' => $meet->id,
        'swim_event_id' => makeEvent_pcr2($meet)->id,
        'athlete_id' => $athlete->id,
        'club_id' => $club->id,
        'swim_time' => 10000,
        'status' => $status,
        'sport_class' => 'S9',
    ]);

    return CupDailyResult::create([
        'cup_id' => $cup->id,
        'meet_id' => $meet->id,
        'athlete_id' => $athlete->id,
        'club_id' => $club->id,
        'result_id' => $result->id,
        'sport_class_group_id' => makeSportClassGroup_pcr2()->id,
        'gender' => 'M',
        'points' => $points,
        'calculated_at' => now(),
    ]);
}

function row_pcr2(Collection $ranking, string $clubName): ?PerformanceClubRankingResult
{
    return $ranking->firstWhere('clubName', $clubName);
}

// ── Tests: Leistungswertung ──────────────────────────────────────────────────

it('gewichtet die besten Athleten eines Vereins korrekt', function () {
    $cup = makeCup_pcr2();
    $meet = makeMeet_pcr2($cup);
    $club = makeClub_pcr2('Verein');

    foreach ([2700, 2400, 2100, 1900, 1600] as $points) {
        makeDaily_pcr2($cup, $meet, makeAthlete_pcr2(), $club, $points);
    }

    // 2700×1,00 + 2400×0,80 + 2100×0,60 + 1900×0,40 + 1600×0,20 = 6960
    $row = row_pcr2(service_pcr2()->getRanking($cup, config_pcr2()), 'Verein');

    expect($row->countedAthletes)->toBe(5)
        ->and($row->countedMeets)->toBe(1)
        ->and($row->totalPoints)->toEqualWithDelta(6960.0, 0.001);
});

it('summiert nur die konfigurierten besten Meets je Athlet', function () {
    $cup = makeCup_pcr2();
    $m1 = makeMeet_pcr2($cup);
    $m2 = makeMeet_pcr2($cup);
    $m3 = makeMeet_pcr2($cup);
    $club = makeClub_pcr2('Verein');
    $athlete = makeAthlete_pcr2();

    makeDaily_pcr2($cup, $m1, $athlete, $club, 810);
    makeDaily_pcr2($cup, $m2, $athlete, $club, 790);
    makeDaily_pcr2($cup, $m3, $athlete, $club, 760);

    // Nur die besten 2 Meets: 810 + 790 = 1600 (760 fällt weg).
    $row = row_pcr2(service_pcr2()->getRanking($cup, config_pcr2(meets: 2)), 'Verein');

    expect($row->totalPoints)->toEqualWithDelta(1600.0, 0.001)
        ->and($row->countedMeets)->toBe(2)
        ->and($row->athletes[0]->seasonValue)->toBe(1600)
        ->and($row->athletes[0]->meetPoints)->toBe([810, 790]);
});

it('berücksichtigt nur die konfigurierten Top-Athleten je Verein', function () {
    $cup = makeCup_pcr2();
    $meet = makeMeet_pcr2($cup);
    $club = makeClub_pcr2('Verein');

    foreach ([100, 90, 80, 70, 60, 50] as $points) {
        makeDaily_pcr2($cup, $meet, makeAthlete_pcr2(), $club, $points);
    }

    // Top 5: 100×1 + 90×0,8 + 80×0,6 + 70×0,4 + 60×0,2 = 260 (50 fällt weg).
    $row = row_pcr2(service_pcr2()->getRanking($cup, config_pcr2()), 'Verein');

    expect($row->countedAthletes)->toBe(5)
        ->and($row->totalPoints)->toEqualWithDelta(260.0, 0.001);
});

it('lässt einen kleinen Verein mit starken Athleten vor einem großen Verein liegen', function () {
    $cup = makeCup_pcr2();
    $meet = makeMeet_pcr2($cup);

    $small = makeClub_pcr2('Klein');
    makeDaily_pcr2($cup, $meet, makeAthlete_pcr2(), $small, 3000);
    makeDaily_pcr2($cup, $meet, makeAthlete_pcr2(), $small, 2900);

    $big = makeClub_pcr2('Gross');
    foreach ([1000, 1000, 1000, 1000, 1000] as $points) {
        makeDaily_pcr2($cup, $meet, makeAthlete_pcr2(), $big, $points);
    }

    // Klein: 3000 + 2900×0,8 = 5320; Gross: 1000×(1+0,8+0,6+0,4+0,2) = 3000.
    $ranking = service_pcr2()->getRanking($cup, config_pcr2());

    expect($ranking->pluck('clubName')->all())->toBe(['Klein', 'Gross'])
        ->and($ranking->pluck('rank')->all())->toBe([1, 2])
        ->and(row_pcr2($ranking, 'Klein')->totalPoints)->toEqualWithDelta(5320.0, 0.001)
        ->and(row_pcr2($ranking, 'Gross')->totalPoints)->toEqualWithDelta(3000.0, 0.001);
});

it('gibt einem großen Verein keinen Vorteil durch Athleten jenseits der Top-N', function () {
    $cup = makeCup_pcr2();
    $meet = makeMeet_pcr2($cup);
    $club = makeClub_pcr2('Verein');

    foreach ([1000, 1000, 1000, 1000, 1000, 1000, 1000] as $points) {
        makeDaily_pcr2($cup, $meet, makeAthlete_pcr2(), $club, $points);
    }

    // 7 Athleten, aber nur die besten 5 werten: 1000×(1+0,8+0,6+0,4+0,2) = 3000.
    $row = row_pcr2(service_pcr2()->getRanking($cup, config_pcr2()), 'Verein');

    expect($row->countedAthletes)->toBe(5)
        ->and($row->totalPoints)->toEqualWithDelta(3000.0, 0.001);
});

it('ordnet den Saisonwert bei Vereinswechsel je Verein getrennt zu', function () {
    $cup = makeCup_pcr2();
    $m1 = makeMeet_pcr2($cup);
    $m2 = makeMeet_pcr2($cup);
    $altClub = makeClub_pcr2('Alt');
    $neuClub = makeClub_pcr2('Neu');
    $athlete = makeAthlete_pcr2();

    // Meet 1 für den Alt-Verein, Meet 2 (nach Wechsel) für den Neu-Verein.
    makeDaily_pcr2($cup, $m1, $athlete, $altClub, 800);
    makeDaily_pcr2($cup, $m2, $athlete, $neuClub, 700);

    $ranking = service_pcr2()->getRanking($cup, config_pcr2());

    expect(row_pcr2($ranking, 'Alt')->totalPoints)->toEqualWithDelta(800.0, 0.001)
        ->and(row_pcr2($ranking, 'Neu')->totalPoints)->toEqualWithDelta(700.0, 0.001)
        ->and(row_pcr2($ranking, 'Alt')->countedAthletes)->toBe(1)
        ->and(row_pcr2($ranking, 'Neu')->countedAthletes)->toBe(1);
});

it('schließt EXH-Ergebnisse (außer Konkurrenz) aus', function () {
    $cup = makeCup_pcr2();
    $meet = makeMeet_pcr2($cup);
    $exhClub = makeClub_pcr2('Ausser-Konkurrenz');
    $regularClub = makeClub_pcr2('Regulaer');

    makeDaily_pcr2($cup, $meet, makeAthlete_pcr2(), $exhClub, 500, 'EXH'); // ausgeschlossen
    makeDaily_pcr2($cup, $meet, makeAthlete_pcr2(), $regularClub, 400);    // zählt

    $ranking = service_pcr2()->getRanking($cup, config_pcr2());

    expect($ranking)->toHaveCount(1)
        ->and(row_pcr2($ranking, 'Regulaer'))->not->toBeNull()
        ->and(row_pcr2($ranking, 'Ausser-Konkurrenz'))->toBeNull();
});

it('berücksichtigt nur Tageswertungen des angefragten Cups', function () {
    $cup1 = makeCup_pcr2();
    $cup2 = makeCup_pcr2(2025);
    $club = makeClub_pcr2('Verein');

    makeDaily_pcr2($cup1, makeMeet_pcr2($cup1), makeAthlete_pcr2(), $club, 500);
    makeDaily_pcr2($cup2, makeMeet_pcr2($cup2), makeAthlete_pcr2(), $club, 900);

    // Für Cup 1 dürfen die 900 Punkte aus Cup 2 nicht einfließen.
    $row = row_pcr2(service_pcr2()->getRanking($cup1, config_pcr2()), 'Verein');

    expect($row->totalPoints)->toEqualWithDelta(500.0, 0.001);
});

it('schließt ausländische Vereine standardmäßig aus', function () {
    $cup = makeCup_pcr2();
    $meet = makeMeet_pcr2($cup);

    makeDaily_pcr2($cup, $meet, makeAthlete_pcr2(), makeClub_pcr2('Austria'), 500);
    makeDaily_pcr2($cup, $meet, makeAthlete_pcr2(), makeClub_pcr2('Germania', 'GER'), 400);

    $ranking = service_pcr2()->getRanking($cup, config_pcr2());

    expect($ranking)->toHaveCount(1)
        ->and(row_pcr2($ranking, 'Austria'))->not->toBeNull()
        ->and(row_pcr2($ranking, 'Germania'))->toBeNull();
});

it('wertet ausländische Vereine, wenn per Konfiguration aktiviert', function () {
    $cup = makeCup_pcr2();
    $meet = makeMeet_pcr2($cup);

    makeDaily_pcr2($cup, $meet, makeAthlete_pcr2(), makeClub_pcr2('Austria'), 500);
    makeDaily_pcr2($cup, $meet, makeAthlete_pcr2(), makeClub_pcr2('Germania', 'GER'), 400);

    $ranking = service_pcr2()->getRanking($cup, config_pcr2(foreign: true));

    expect($ranking)->toHaveCount(2)
        ->and(row_pcr2($ranking, 'Germania'))->not->toBeNull()
        ->and(row_pcr2($ranking, 'Austria'))->not->toBeNull();
});

it('lädt die Standardkonfiguration aus config(), wenn keine übergeben wird', function () {
    config()->set('cup_club_ranking.include_foreign_clubs', true);

    $cup = makeCup_pcr2();
    $meet = makeMeet_pcr2($cup);
    makeDaily_pcr2($cup, $meet, makeAthlete_pcr2(), makeClub_pcr2('Austria'), 500);
    makeDaily_pcr2($cup, $meet, makeAthlete_pcr2(), makeClub_pcr2('Germania', 'GER'), 400);

    // Ohne übergebene Konfiguration → fromConfig() → ausländische Vereine aktiv.
    expect(service_pcr2()->getRanking($cup))->toHaveCount(2);
});

it('vergibt bei identischen Kriterien denselben Rang und reiht alphabetisch', function () {
    $cup = makeCup_pcr2();
    $meet = makeMeet_pcr2($cup);

    foreach (['Zebra', 'Adler'] as $name) {
        makeDaily_pcr2($cup, $meet, makeAthlete_pcr2(), makeClub_pcr2($name), 1000);
    }

    $ranking = service_pcr2()->getRanking($cup, config_pcr2());

    expect($ranking->pluck('clubName')->all())->toBe(['Adler', 'Zebra'])
        ->and($ranking->pluck('rank')->all())->toBe([1, 1]);
});

it('liefert eine leere Rangliste, wenn keine Tageswertungen vorliegen', function () {
    $cup = makeCup_pcr2();

    expect(service_pcr2()->getRanking($cup, config_pcr2()))->toBeEmpty();
});

// ── Tests: Fassade CupClubRankingService ─────────────────────────────────────

it('Fassade: calculateStartRanking liefert StartClubRankingResult', function () {
    $cup = makeCup_pcr2();
    $meet = makeMeet_pcr2($cup);
    makeDaily_pcr2($cup, $meet, makeAthlete_pcr2(), makeClub_pcr2('Verein'), 500);

    $ranking = app(CupClubRankingService::class)->calculateStartRanking($cup);

    expect($ranking->first())->toBeInstanceOf(StartClubRankingResult::class);
});

it('Fassade: calculatePerformanceRanking liefert PerformanceClubRankingResult', function () {
    $cup = makeCup_pcr2();
    $meet = makeMeet_pcr2($cup);
    makeDaily_pcr2($cup, $meet, makeAthlete_pcr2(), makeClub_pcr2('Verein'), 500);

    $ranking = app(CupClubRankingService::class)->calculatePerformanceRanking($cup);

    expect($ranking->first())->toBeInstanceOf(PerformanceClubRankingResult::class);
});
