<?php

use App\Models\Athlete;
use App\Models\AthleteKaderMembership;
use App\Models\BaseTime;
use App\Models\BaseTimeCategory;
use App\Models\BaseTimeDiscipline;
use App\Models\BaseTimeSportClass;
use App\Models\BaseTimeVersion;
use App\Models\Club;
use App\Models\Cup;
use App\Models\CupDailyResult;
use App\Models\CupOverallResult;
use App\Models\CupTopGroupClassification;
use App\Models\KaderType;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Qualification;
use App\Models\QualifyingTime;
use App\Models\QualifyingTimeList;
use App\Models\Result;
use App\Models\SportClassGroup;
use App\Models\SportClassGroupMember;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Services\AthleteMergeService;
use App\Services\CupStalenessService;
use App\Services\DailyRankingService;
use App\Services\OverallRankingService;
use App\Services\QualificationDeterminationService;
use App\Services\TopGroupClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('athlete-merge-p2');

// Athleten zusammenführen, Phase 2: Neuberechnung von Cup-Wertungen und Qualifikationen
// (AthleteMergeRecalculationService) und "Veraltet"-Anzeige, wenn ohne Neuberechnung zusammengeführt wird.

function nation_am2(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]);
}

function club_am2(): Club
{
    return Club::firstOrCreate(['name' => 'Heimverein'], ['nation_id' => nation_am2()->id]);
}

function athlete_am2(string $lastName, string $firstName): Athlete
{
    return Athlete::create(['last_name' => $lastName, 'first_name' => $firstName, 'gender' => 'M',
        'birth_date' => '1990-08-21', 'nation_id' => nation_am2()->id, 'club_id' => club_am2()->id, 'is_active' => true]);
}

function stroke_am2(): StrokeType
{
    return StrokeType::firstOrCreate(['code' => 'FREE'],
        ['lenex_code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle', 'category' => 'standard', 'is_active' => true]);
}

/** Cup mit Basiszeit 100,00 s für 100 m Freistil S9 (LCM): Ein Ergebnis von 100,00 s ergibt 1000 Punkte. */
function cup_am2(int $year): Cup
{
    if (! SportClassGroup::where('code', 'PI')->exists()) {
        $group = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);
        SportClassGroupMember::create(['sport_class_group_id' => $group->id, 'sport_class' => 'S9']);
    }

    $version = BaseTimeVersion::create(['label' => "V$year", 'valid_from' => "$year-01-01"]);
    $discipline = BaseTimeDiscipline::firstOrCreate(['stroke_type_id' => stroke_am2()->id, 'distance' => 100, 'relay_count' => 1],
        ['code' => 'FREE_100']);
    $sportClass = BaseTimeSportClass::firstOrCreate(['code' => 'S9'], ['sort_order' => 1]);
    $category = BaseTimeCategory::firstOrCreate(['course' => 'LCM', 'gender' => 'M'], ['code' => 'LCM_M', 'label' => 'LCM Männer']);
    BaseTime::create(['base_time_version_id' => $version->id, 'base_time_category_id' => $category->id,
        'base_time_discipline_id' => $discipline->id, 'base_time_sport_class_id' => $sportClass->id,
        'value_centiseconds' => 10000]);

    return Cup::create(['year' => $year, 'name' => "ÖBSV Cup $year", 'base_time_version_id' => $version->id,
        'rounds_count' => 2, 'best_of_count' => 3, 'top_group_points_threshold' => 450]);
}

function meet_am2(?Cup $cup, string $name, string $date): Meet
{
    return Meet::create(['name' => $name, 'nation_id' => nation_am2()->id, 'course' => 'LCM', 'start_date' => $date,
        'cup_id' => $cup?->id]);
}

function result_am2(Athlete $athlete, Meet $meet): Result
{
    $event = SwimEvent::create(['meet_id' => $meet->id, 'stroke_type_id' => stroke_am2()->id, 'distance' => 100,
        'gender' => 'A']);

    return Result::create(['meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id,
        'club_id' => club_am2()->id, 'sport_class' => 'S9', 'swim_time' => 10000]);
}

/** Aktive Kaderzugehörigkeit: Der Athlet erscheint in der Topgruppen-Klassifizierung jedes Cups. */
function kader_am2(Athlete $athlete): AthleteKaderMembership
{
    $type = KaderType::firstOrCreate(['code' => 'WELTKLASSE'], ['name_de' => 'Weltklasse', 'is_active' => true]);

    return AthleteKaderMembership::create(['athlete_id' => $athlete->id, 'kader_type_id' => $type->id]);
}

/**
 * Topgruppe, Tageswertung je Cup-Wettkampf und Gesamtwertung, wie die Admin-Seiten sie auslösen.
 *
 * @throws Throwable
 */
function calculateCup_am2(Cup $cup): void
{
    app(TopGroupClassificationService::class)->calculateForCup($cup);
    foreach (Meet::where('cup_id', $cup->id)->get() as $meet) {
        app(DailyRankingService::class)->calculateForMeet($meet);
    }
    app(OverallRankingService::class)->calculateForCup($cup);
}

function qualifyingList_am2(int $year, ?int $value): QualifyingTime
{
    $list = QualifyingTimeList::create(['year' => $year, 'is_active' => true,
        'qualification_period_start' => "$year-01-01", 'qualification_period_end' => "$year-12-31"]);

    return QualifyingTime::create(['qualifying_time_list_id' => $list->id, 'stroke_type_id' => stroke_am2()->id,
        'distance' => 100, 'gender' => 'M', 'sport_class' => 'S9', 'value_centiseconds' => $value,
        'source' => QualifyingTime::SOURCE_CALCULATED]);
}

function service_am2(): AthleteMergeService
{
    return app(AthleteMergeService::class);
}

// ── Cup-Wertung ──────────────────────────────────────────────────────────────

it('berechnet nach dem Zusammenführen Topgruppe, Tages- und Gesamtwertung des Cups neu', /** @throws Throwable */ function () {
    $cup = cup_am2(2026);
    $keep = athlete_am2('Djuza', 'Slavica');
    $drop = athlete_am2('Slavica', 'Djuza');
    kader_am2(athlete_am2('Kader', 'Athlet'));
    result_am2($keep, meet_am2($cup, 'Runde 1', '2026-04-01'));
    result_am2($drop, meet_am2($cup, 'Runde 2', '2026-06-01'));
    calculateCup_am2($cup);
    $calculatedBefore = CupTopGroupClassification::where('cup_id', $cup->id)->max('calculated_at');
    $this->travel(1)->minutes();

    $report = service_am2()->merge($keep, $drop, [], [], true)['recalculation'];
    $overall = CupOverallResult::where('cup_id', $cup->id)->get();

    expect($report['cups'])->toBe([['id' => $cup->id, 'name' => 'ÖBSV Cup 2026',
        'stages' => ['Topgruppe', 'Tageswertung Runde 1', 'Tageswertung Runde 2', 'Gesamtwertung']]])
        ->and($report['errors'])->toBe([])
        ->and(CupTopGroupClassification::where('cup_id', $cup->id)->max('calculated_at'))->toBeGreaterThan($calculatedBefore)
        ->and(CupDailyResult::where('athlete_id', $keep->id)->count())->toBe(2)
        ->and($overall)->toHaveCount(1)
        ->and($overall->first()->athlete_id)->toBe($keep->id)
        ->and($overall->first()->total_points)->toBe(2000)
        ->and($overall->first()->rounds_counted)->toBe(2);
});

it('berechnet nur Stufen neu, die schon einmal berechnet wurden', /** @throws Throwable */ function () {
    $cup = cup_am2(2026);
    $keep = athlete_am2('Djuza', 'Slavica');
    $drop = athlete_am2('Slavica', 'Djuza');
    $first = meet_am2($cup, 'Runde 1', '2026-04-01');
    $second = meet_am2($cup, 'Runde 2', '2026-06-01');
    result_am2($keep, $first);
    result_am2($drop, $second);
    app(DailyRankingService::class)->calculateForMeet($first);

    $report = service_am2()->merge($keep, $drop, [], [], true)['recalculation'];

    expect($report['cups'][0]['stages'])->toBe(['Tageswertung Runde 1'])
        ->and(CupTopGroupClassification::count())->toBe(0)
        ->and(CupDailyResult::where('meet_id', $second->id)->count())->toBe(0)
        ->and(CupOverallResult::count())->toBe(0);
});

it('lässt Cups ohne Bezug zu den beiden Athleten unberührt', /** @throws Throwable */ function () {
    $cup = cup_am2(2026);
    $otherCup = cup_am2(2025);
    $keep = athlete_am2('Djuza', 'Slavica');
    $drop = athlete_am2('Slavica', 'Djuza');
    result_am2($drop, meet_am2($cup, 'Runde 1', '2026-04-01'));
    result_am2(athlete_am2('Andere', 'Person'), meet_am2($otherCup, 'Runde 2025', '2025-04-01'));
    calculateCup_am2($cup);
    calculateCup_am2($otherCup);
    $otherCalculatedAt = CupDailyResult::where('cup_id', $otherCup->id)->value('calculated_at');
    $this->travel(1)->minutes();

    $report = service_am2()->merge($keep, $drop, [], [], true)['recalculation'];

    expect(array_column($report['cups'], 'id'))->toBe([$cup->id])
        ->and(CupDailyResult::where('cup_id', $otherCup->id)->value('calculated_at'))->toEqual($otherCalculatedAt);
});

it('meldet ohne Neuberechnung die betroffenen Wertungen als veraltet', /** @throws Throwable */ function () {
    $cup = cup_am2(2026);
    $keep = athlete_am2('Djuza', 'Slavica');
    $drop = athlete_am2('Slavica', 'Djuza');
    $first = meet_am2($cup, 'Runde 1', '2026-04-01');
    $second = meet_am2($cup, 'Runde 2', '2026-06-01');
    result_am2($keep, $first);
    result_am2($drop, $second);
    kader_am2($drop);
    calculateCup_am2($cup);
    $staleness = app(CupStalenessService::class);

    expect($staleness->dailyRankingStatus($second)['isStale'])->toBeFalse()
        ->and($staleness->topGroupClassificationStatus($cup)['isStale'])->toBeFalse();

    $this->travel(1)->minutes();
    $merged = service_am2()->merge($keep, $drop, [], [], false);

    expect($merged['recalculation'])->toBeNull()
        ->and($staleness->dailyRankingStatus($second)['isStale'])->toBeTrue()
        ->and($staleness->dailyRankingStatus($first)['isStale'])->toBeFalse()
        ->and($staleness->topGroupClassificationStatus($cup)['isStale'])->toBeTrue();
});

// ── Qualifikationen ──────────────────────────────────────────────────────────

it('berechnet die Qualifikationen betroffener Richtzeitenlisten neu und meldet Fehler im Bericht', /** @throws Throwable */ function () {
    $keep = athlete_am2('Djuza', 'Slavica');
    $drop = athlete_am2('Slavica', 'Djuza');
    $time = qualifyingList_am2(2026, 11000);
    $dropResult = result_am2($drop, meet_am2(null, 'Staatsmeisterschaften', '2026-06-01'));
    app(QualificationDeterminationService::class)->calculateForList($time->qualifyingTimeList);
    $brokenTime = qualifyingList_am2(2025, null);
    Qualification::create(['qualifying_time_list_id' => $brokenTime->qualifying_time_list_id,
        'qualifying_time_id' => $brokenTime->id, 'athlete_id' => $keep->id,
        'result_id' => result_am2($keep, meet_am2(null, 'Landesmeisterschaften', '2025-06-01'))->id,
        'sport_class' => 'S9', 'swim_time_centiseconds' => 10000, 'qualified_at' => '2025-06-01']);

    $report = service_am2()->merge($keep, $drop, [], [], true)['recalculation'];
    $qualification = Qualification::where('qualifying_time_list_id', $time->qualifying_time_list_id)->sole();

    expect($report['qualifying_time_lists'])->toBe([['id' => $time->qualifying_time_list_id, 'year' => 2026, 'qualified' => 1]])
        ->and($report['errors'])->toBe(['Richtzeitenliste 2025 enthält keine berechneten Richtzeiten.'])
        ->and($qualification->athlete_id)->toBe($keep->id)
        ->and($qualification->result_id)->toBe($dropResult->id);
});

// ── Transaktion ──────────────────────────────────────────────────────────────

it('rollt auch das Zusammenführen zurück, wenn die Neuberechnung scheitert', /** @throws Throwable */ function () {
    $cup = cup_am2(2026);
    $keep = athlete_am2('Djuza', 'Slavica');
    $drop = athlete_am2('Slavica', 'Djuza');
    kader_am2(athlete_am2('Kader', 'Athlet'));
    $result = result_am2($drop, meet_am2($cup, 'Runde 1', '2026-04-01'));
    calculateCup_am2($cup);
    // Die Ranking-Services sind readonly und lassen sich nicht mocken; der Fehler kommt deshalb aus dem Model-Event.
    CupTopGroupClassification::creating(function () {
        throw new RuntimeException('Testfehler');
    });

    expect(/** @throws Throwable */ fn () => service_am2()->merge($keep, $drop, [], [], true))
        ->toThrow(RuntimeException::class, 'Testfehler')
        ->and($result->fresh()->athlete_id)->toBe($drop->id)
        ->and(Athlete::find($drop->id))->not->toBeNull();
});
