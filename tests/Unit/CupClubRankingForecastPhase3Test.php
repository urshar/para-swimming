<?php

use App\Models\Athlete;
use App\Models\BaseTime;
use App\Models\BaseTimeCategory;
use App\Models\BaseTimeDiscipline;
use App\Models\BaseTimeSportClass;
use App\Models\BaseTimeVersion;
use App\Models\Club;
use App\Models\Cup;
use App\Models\Entry;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\SportClassGroup;
use App\Models\SportClassGroupMember;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Services\CupClubRankingForecastService;
use App\Services\CupStalenessService;
use App\Services\DailyRankingService;
use App\Support\ClubForecastComparison;
use App\Support\ClubRankingConfiguration;
use App\Support\PerformanceClubRankingResult;
use App\Support\StartClubRankingResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/*
 * Prognose der Vereinswertung, Phase 3 (docs/specs/cup-club-ranking-forecast.md §6):
 * Zusammenführung der echten Daten mit den aus Meldungen prognostizierten Zeilen, Vergleich zur
 * aktuellen Wertung und Staleness.
 *
 * Basiswert: 100 m Freistil LCM Herren S9 = 100,00 s — 100,00 s ergeben 1000 Punkte, 125,00 s
 * ergeben 512 Punkte.
 */
uses(TestCase::class, RefreshDatabase::class)->group('cup-club-ranking-forecast-p3');

// ── Helpers ──────────────────────────────────────────────────────────────────

function service_fc3(): CupClubRankingForecastService
{
    return app(CupClubRankingForecastService::class);
}

function nextSeq_fc3(): int
{
    static $n = 0;

    return ++$n;
}

function config_fc3(): ClubRankingConfiguration
{
    return new ClubRankingConfiguration(
        countedMeetsPerAthlete: 3,
        maxCountedAthletesPerClub: 5,
        weights: [1 => 1.0, 2 => 0.8, 3 => 0.6, 4 => 0.4, 5 => 0.2],
        includeForeignClubs: false,
        restrictedKaderTypeCodes: [],
        countedKaderAthletesPerClub: 0,
    );
}

function nation_fc3(string $code): Nation
{
    return Nation::firstOrCreate(['code' => $code], ['name_de' => $code, 'name_en' => $code, 'is_active' => true]);
}

function stroke_fc3(): StrokeType
{
    return StrokeType::firstOrCreate(['code' => 'FREE'],
        ['lenex_code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle', 'category' => 'standard', 'is_active' => true]);
}

function club_fc3(string $name, string $nationCode): Club
{
    return Club::create(['name' => $name, 'nation_id' => nation_fc3($nationCode)->id]);
}

function athlete_fc3(): Athlete
{
    $seq = nextSeq_fc3();

    return Athlete::create(['first_name' => "Vorname$seq", 'last_name' => "Nachname$seq", 'gender' => 'M',
        'nation_id' => nation_fc3('AUT')->id, 'is_active' => true]);
}

function cup_fc3(): Cup
{
    $group = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);
    SportClassGroupMember::create(['sport_class_group_id' => $group->id, 'sport_class' => 'S9']);

    $version = BaseTimeVersion::create(['label' => 'V2026', 'valid_from' => '2026-01-01']);
    $discipline = BaseTimeDiscipline::create(['stroke_type_id' => stroke_fc3()->id, 'distance' => 100,
        'relay_count' => 1, 'code' => 'FREE_100']);
    $category = BaseTimeCategory::create(['course' => 'LCM', 'gender' => 'M', 'code' => 'LCM_M', 'label' => 'LCM Männer']);
    $sportClass = BaseTimeSportClass::create(['code' => 'S9', 'sort_order' => 1]);
    BaseTime::create(['base_time_version_id' => $version->id, 'base_time_category_id' => $category->id,
        'base_time_discipline_id' => $discipline->id, 'base_time_sport_class_id' => $sportClass->id,
        'value_centiseconds' => 10000]);

    return Cup::create(['year' => 2026, 'name' => 'ÖBSV Cup 2026', 'base_time_version_id' => $version->id,
        'rounds_count' => 2, 'best_of_count' => 3]);
}

function meet_fc3(Cup $cup, string $date): Meet
{
    return Meet::create(['name' => 'Meet '.nextSeq_fc3(), 'nation_id' => nation_fc3('AUT')->id, 'course' => 'LCM',
        'start_date' => $date, 'cup_id' => $cup->id]);
}

/** Eigener Freistil-Bewerb je Aufruf (eine Meldung bzw. ein Ergebnis je Athlet und Bewerb). */
function event_fc3(Meet $meet, int $distance): SwimEvent
{
    return SwimEvent::create(['meet_id' => $meet->id, 'stroke_type_id' => stroke_fc3()->id, 'distance' => $distance,
        'relay_count' => 1, 'round' => 'TIM', 'gender' => 'M']);
}

function result_fc3(Meet $meet, int $distance, Athlete $athlete, Club $club, int $swimTime): Result
{
    return Result::create(['meet_id' => $meet->id, 'swim_event_id' => event_fc3($meet, $distance)->id,
        'athlete_id' => $athlete->id, 'club_id' => $club->id, 'swim_time' => $swimTime, 'sport_class' => 'S9']);
}

function entry_fc3(Meet $meet, int $distance, Athlete $athlete, Club $club, ?int $entryTime, string $entryCourse): Entry
{
    return Entry::create(['meet_id' => $meet->id, 'swim_event_id' => event_fc3($meet, $distance)->id,
        'athlete_id' => $athlete->id, 'club_id' => $club->id, 'entry_time' => $entryTime,
        'entry_time_code' => $entryTime === null ? 'NT' : null, 'sport_class' => 'S9', 'status' => null,
        'entry_course' => $entryCourse]);
}

/**
 * @param  Collection<int, ClubForecastComparison>  $comparisons
 * @return array{?int, int, ?int, float}
 */
function comparisonOf_fc3(Collection $comparisons, Club $club): array
{
    $c = $comparisons->get($club->id);

    return [$c->currentRank, $c->forecastRank, $c->rankChange, $c->valueDelta];
}

// ── Startwertung ─────────────────────────────────────────────────────────────

it('ergänzt die echten Starts um die Meldungen des Prognose-Meets und vergleicht mit der aktuellen Wertung', function () {
    $cup = cup_fc3();
    $real = meet_fc3($cup, '2026-05-01');
    $forecast = meet_fc3($cup, '2026-11-20');
    $clubA = club_fc3('Verein A', 'AUT');
    $clubB = club_fc3('Verein B', 'AUT');
    $athleteA = athlete_fc3();
    $athleteB = athlete_fc3();

    result_fc3($real, 50, $athleteA, $clubA, 10000);
    result_fc3($real, 100, $athleteA, $clubA, 10000);
    result_fc3($real, 50, $athleteB, $clubB, 10000);

    entry_fc3($forecast, 50, $athleteB, $clubB, 10000, 'LCM');
    entry_fc3($forecast, 100, $athleteB, $clubB, null, 'LCM');
    entry_fc3($forecast, 200, $athleteB, $clubB, 10000, 'LCM');

    $result = service_fc3()->forecastStartRanking($cup, false);

    expect($result->hasForecastMeets())->toBeTrue()
        ->and($result->forecastMeets->pluck('id')->all())->toBe([$forecast->id])
        ->and($result->ranking->map(fn (StartClubRankingResult $r): array => [$r->clubName, $r->starts, $r->meets])->all())
        ->toBe([['Verein B', 4, 2], ['Verein A', 2, 1]])
        ->and(comparisonOf_fc3($result->comparisons, $clubB))->toBe([2, 1, 1, 3.0])
        ->and(comparisonOf_fc3($result->comparisons, $clubA))->toBe([1, 2, -1, 0.0]);
});

it('führt einen Verein, der nur gemeldet hat, ohne aktuellen Rang', function () {
    $cup = cup_fc3();
    $real = meet_fc3($cup, '2026-05-01');
    $forecast = meet_fc3($cup, '2026-11-20');
    $clubA = club_fc3('Verein A', 'AUT');
    $newClub = club_fc3('Neuer Verein', 'AUT');

    result_fc3($real, 100, athlete_fc3(), $clubA, 10000);
    entry_fc3($forecast, 100, athlete_fc3(), $newClub, 10000, 'LCM');

    $comparison = service_fc3()->forecastStartRanking($cup, false)->comparisons->get($newClub->id);

    expect($comparison->currentRank)->toBeNull()
        ->and($comparison->rankChange)->toBeNull()
        ->and($comparison->currentValue)->toBe(0.0)
        ->and($comparison->forecastValue)->toBe(1.0);
});

it('wertet ein Meet mit teilweisen Ergebnissen als echt und ignoriert dessen Meldungen', function () {
    $cup = cup_fc3();
    $partial = meet_fc3($cup, '2026-11-20');
    $club = club_fc3('Verein A', 'AUT');
    $athlete = athlete_fc3();

    result_fc3($partial, 100, $athlete, $club, 10000);
    entry_fc3($partial, 200, $athlete, $club, 10000, 'LCM');

    $result = service_fc3()->forecastStartRanking($cup, false);

    expect($result->hasForecastMeets())->toBeFalse()
        ->and($result->ranking->first()->starts)->toBe(1);
});

it('entspricht ohne Prognose-Meet der aktuellen Wertung', /** @throws Throwable */ function () {
    $cup = cup_fc3();
    $real = meet_fc3($cup, '2026-05-01');
    $club = club_fc3('Verein A', 'AUT');
    $athlete = athlete_fc3();

    result_fc3($real, 100, $athlete, $club, 10000);
    app(DailyRankingService::class)->calculateForMeet($real);

    $start = service_fc3()->forecastStartRanking($cup, false);
    $performance = service_fc3()->forecastPerformanceRanking($cup, config_fc3());

    expect($start->hasForecastMeets())->toBeFalse()
        ->and(comparisonOf_fc3($start->comparisons, $club))->toBe([1, 1, 0, 0.0])
        ->and($performance->hasForecastMeets())->toBeFalse()
        ->and(comparisonOf_fc3($performance->comparisons, $club))->toBe([1, 1, 0, 0.0])
        ->and($performance->ranking->first()->totalPoints)->toBe(1000.0);
});

it('wendet den Ausland-Schalter auch auf prognostizierte Starts an', function () {
    $cup = cup_fc3();
    $forecast = meet_fc3($cup, '2026-11-20');
    $foreign = club_fc3('Gastverein', 'GER');

    entry_fc3($forecast, 100, athlete_fc3(), $foreign, 10000, 'LCM');

    expect(service_fc3()->forecastStartRanking($cup, false)->ranking)->toBeEmpty()
        ->and(service_fc3()->forecastStartRanking($cup, true)->ranking)->toHaveCount(1);
});

// ── Leistungswertung ─────────────────────────────────────────────────────────

it('ergänzt die echte Tageswertung um die prognostizierten Punkte und vergleicht mit der aktuellen Wertung', /** @throws Throwable */ function () {
    $cup = cup_fc3();
    $real = meet_fc3($cup, '2026-05-01');
    $forecast = meet_fc3($cup, '2026-11-20');
    $clubA = club_fc3('Verein A', 'AUT');
    $clubB = club_fc3('Verein B', 'AUT');
    $athleteA = athlete_fc3();
    $athleteB = athlete_fc3();

    result_fc3($real, 100, $athleteA, $clubA, 10000);
    result_fc3($real, 100, $athleteB, $clubB, 12500);
    app(DailyRankingService::class)->calculateForMeet($real);

    entry_fc3($forecast, 100, $athleteB, $clubB, 10000, 'LCM');

    $result = service_fc3()->forecastPerformanceRanking($cup, config_fc3());
    $clubBRow = $result->ranking->first();

    expect($result->ranking->map(fn (PerformanceClubRankingResult $r): array => [$r->clubName, $r->totalPoints])->all())
        ->toBe([['Verein B', 1512.0], ['Verein A', 1000.0]])
        ->and($clubBRow->athletes[0]->meetPoints)->toBe([1000, 512])
        ->and($clubBRow->athletes[0]->meetIds)->toBe([$forecast->id, $real->id])
        ->and($result->isProjected($forecast->id))->toBeTrue()
        ->and($result->isProjected($real->id))->toBeFalse()
        ->and(comparisonOf_fc3($result->comparisons, $clubB))->toBe([2, 1, 1, 1000.0])
        ->and(comparisonOf_fc3($result->comparisons, $clubA))->toBe([1, 2, -1, 0.0]);
});

it('gibt nicht bewertbare Meldungen und Meldezeiten aus anderer Bahnlänge weiter', function () {
    $cup = cup_fc3();
    $forecast = meet_fc3($cup, '2026-11-20');
    $club = club_fc3('Verein A', 'AUT');
    $shortCourse = athlete_fc3();
    $noTime = athlete_fc3();

    entry_fc3($forecast, 100, $shortCourse, $club, 10000, 'SCM');
    $ntEntry = entry_fc3($forecast, 100, $noTime, $club, null, 'LCM');

    $result = service_fc3()->forecastPerformanceRanking($cup, config_fc3());

    expect($result->unratedEntries)->toHaveCount(1)
        ->and($result->unratedEntries->first()->entryId)->toBe($ntEntry->id)
        ->and($result->isOffCourse($forecast->id, $shortCourse->id))->toBeTrue()
        ->and($result->isOffCourse($forecast->id, $noTime->id))->toBeFalse()
        ->and($result->ranking->first()->totalPoints)->toBe(1000.0);
});

it('meldet die Vereinswertung wegen eines Prognose-Meets nicht als veraltet', /** @throws Throwable */ function () {
    $cup = cup_fc3();
    $real = meet_fc3($cup, '2026-05-01');
    $forecast = meet_fc3($cup, '2026-11-20');
    $club = club_fc3('Verein A', 'AUT');
    $athlete = athlete_fc3();

    result_fc3($real, 100, $athlete, $club, 10000);
    app(DailyRankingService::class)->calculateForMeet($real);
    entry_fc3($forecast, 100, $athlete, $club, 10000, 'LCM');

    expect(app(CupStalenessService::class)->clubRankingStatus($cup)['isStale'])->toBeFalse();
});
