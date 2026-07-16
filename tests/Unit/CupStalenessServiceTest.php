<?php

/** @noinspection PhpUnhandledExceptionInspection Pest-Test-Closures fangen Exceptions selbst ab. */

use App\Models\Athlete;
use App\Models\AthleteKaderMembership;
use App\Models\BaseTimeVersion;
use App\Models\Club;
use App\Models\Cup;
use App\Models\CupDailyResult;
use App\Models\CupOverallResult;
use App\Models\CupTopGroupClassification;
use App\Models\KaderType;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\SportClassGroup;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Services\CupStalenessService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────────────────────

/** Setzt eine Zeitstempel-Spalte exakt, ohne dass Eloquents Auto-Timestamping eingreift. */
function touchAt_cup11(string $table, int $id, string $column, Carbon $at): void
{
    DB::table($table)->where('id', $id)->update([$column => $at]);
}

function makeNation_cup11(string $code = 'AUT'): Nation
{
    return Nation::firstOrCreate(
        ['code' => $code],
        ['name_de' => $code, 'name_en' => $code, 'is_active' => true]
    );
}

function makeClub_cup11(): Club
{
    return Club::create(['name' => 'Testclub', 'nation_id' => makeNation_cup11()->id]);
}

function makeAthlete_cup11(): Athlete
{
    return Athlete::create([
        'first_name' => 'Max', 'last_name' => 'Mustermann', 'gender' => 'M',
        'nation_id' => makeNation_cup11()->id, 'is_active' => true,
    ]);
}

function makeCup_cup11(): Cup
{
    $version = BaseTimeVersion::create(['label' => 'V1', 'valid_from' => '2021-01-01']);

    return Cup::create([
        'year' => 2026, 'name' => 'ÖBSV Cup 2026', 'base_time_version_id' => $version->id,
        'rounds_count' => 1, 'best_of_count' => 3, 'top_group_points_threshold' => 450,
    ]);
}

function makeMeet_cup11(Cup $cup): Meet
{
    return Meet::create([
        'name' => 'Testmeet', 'nation_id' => makeNation_cup11()->id,
        'course' => 'LCM', 'start_date' => '2026-06-01', 'cup_id' => $cup->id,
    ]);
}

function makeStrokeType_cup11(): StrokeType
{
    return StrokeType::firstOrCreate(
        ['code' => 'FREE'],
        ['lenex_code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle', 'category' => 'standard', 'is_active' => true]
    );
}

function makeResult_cup11(Athlete $athlete, Club $club, Meet $meet): Result
{
    $event = SwimEvent::create([
        'meet_id' => $meet->id, 'stroke_type_id' => makeStrokeType_cup11()->id, 'distance' => 100, 'gender' => 'A',
    ]);

    return Result::create([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id, 'club_id' => $club->id,
        'sport_class' => 'S9', 'swim_time' => 60000, 'points' => 400,
    ]);
}

function makeDailyResult_cup11(Cup $cup, Meet $meet, Athlete $athlete, Club $club, CarbonInterface $calculatedAt): CupDailyResult
{
    $group = SportClassGroup::firstOrCreate(['code' => 'PI'], ['name_de' => 'PI', 'is_active' => true]);

    return CupDailyResult::create([
        'cup_id' => $cup->id, 'meet_id' => $meet->id, 'athlete_id' => $athlete->id, 'club_id' => $club->id,
        'result_id' => makeResult_cup11($athlete, $club, $meet)->id, 'sport_class_group_id' => $group->id,
        'gender' => $athlete->gender, 'points' => 400, 'calculated_at' => $calculatedAt,
    ]);
}

function service_cup11(): CupStalenessService
{
    return new CupStalenessService;
}

// ── topGroupClassificationStatus ─────────────────────────────────────────────

describe('topGroupClassificationStatus', function () {
    it('ist nicht veraltet, wenn noch nie klassifiziert wurde', function () {
        $cup = makeCup_cup11();

        $status = service_cup11()->topGroupClassificationStatus($cup);

        expect($status['calculatedAt'])->toBeNull()->and($status['isStale'])->toBeFalse();
    })->group('cup-wertung-p11');

    it('ist aktuell, wenn seit der Klassifizierung keine Kaderdaten geändert wurden', function () {
        $cup = makeCup_cup11();
        $athlete = makeAthlete_cup11();
        $kaderType = KaderType::create(['code' => 'WELTKLASSE', 'name_de' => 'Weltklasse']);
        $membership = AthleteKaderMembership::create(['athlete_id' => $athlete->id, 'kader_type_id' => $kaderType->id]);
        touchAt_cup11('athlete_kader_memberships', $membership->id, 'updated_at', Carbon::parse('2026-07-01 10:00'));

        CupTopGroupClassification::create([
            'cup_id' => $cup->id, 'athlete_id' => $athlete->id, 'is_top_group' => true, 'reason' => 'KADER',
            'calculated_at' => '2026-07-01 12:00',
        ]);

        $status = service_cup11()->topGroupClassificationStatus($cup);

        expect($status['isStale'])->toBeFalse();
    })->group('cup-wertung-p11');

    it('ist veraltet, wenn Kaderdaten NACH der letzten Klassifizierung geändert wurden', function () {
        $cup = makeCup_cup11();
        $athlete = makeAthlete_cup11();
        $kaderType = KaderType::create(['code' => 'WELTKLASSE', 'name_de' => 'Weltklasse']);

        CupTopGroupClassification::create([
            'cup_id' => $cup->id, 'athlete_id' => $athlete->id, 'is_top_group' => false, 'reason' => null,
            'calculated_at' => '2026-07-01 10:00',
        ]);

        $membership = AthleteKaderMembership::create(['athlete_id' => $athlete->id, 'kader_type_id' => $kaderType->id]);
        touchAt_cup11('athlete_kader_memberships', $membership->id, 'updated_at', Carbon::parse('2026-07-01 14:19'));

        $status = service_cup11()->topGroupClassificationStatus($cup);

        expect($status['isStale'])->toBeTrue()->and($status['reason'])->not->toBeNull();
    })->group('cup-wertung-p11');
});

// ── dailyRankingStatus ────────────────────────────────────────────────────────

describe('dailyRankingStatus', function () {
    it('ist nicht veraltet, wenn noch nie berechnet wurde', function () {
        $cup = makeCup_cup11();
        $meet = makeMeet_cup11($cup);

        $status = service_cup11()->dailyRankingStatus($meet);

        expect($status['calculatedAt'])->toBeNull()->and($status['isStale'])->toBeFalse();
    })->group('cup-wertung-p11');

    it('ist veraltet, wenn die Top-Gruppen-Klassifizierung NACH der Tageswertung berechnet wurde', function () {
        $cup = makeCup_cup11();
        $meet = makeMeet_cup11($cup);
        $club = makeClub_cup11();
        $athlete = makeAthlete_cup11();
        makeDailyResult_cup11($cup, $meet, $athlete, $club, Carbon::parse('2026-07-14 14:16'));

        CupTopGroupClassification::create([
            'cup_id' => $cup->id, 'athlete_id' => $athlete->id, 'is_top_group' => true, 'reason' => 'KADER',
            'calculated_at' => '2026-07-14 14:20', // NACH der Tageswertung
        ]);

        $status = service_cup11()->dailyRankingStatus($meet);

        expect($status['isStale'])->toBeTrue();
    })->group('cup-wertung-p11');

    it('ist veraltet, wenn ein Ergebnis des Meets NACH der Tageswertung geändert wurde', function () {
        $cup = makeCup_cup11();
        $meet = makeMeet_cup11($cup);
        $club = makeClub_cup11();
        $athlete = makeAthlete_cup11();
        $daily = makeDailyResult_cup11($cup, $meet, $athlete, $club, Carbon::parse('2026-07-14 10:00'));

        touchAt_cup11('results', $daily->result_id, 'updated_at', Carbon::parse('2026-07-14 11:00'));

        $status = service_cup11()->dailyRankingStatus($meet);

        expect($status['isStale'])->toBeTrue();
    })->group('cup-wertung-p11');

    it('ist aktuell, wenn nichts sich seither geändert hat', function () {
        $cup = makeCup_cup11();
        $meet = makeMeet_cup11($cup);
        $club = makeClub_cup11();
        $athlete = makeAthlete_cup11();
        makeDailyResult_cup11($cup, $meet, $athlete, $club, now());

        $status = service_cup11()->dailyRankingStatus($meet);

        expect($status['isStale'])->toBeFalse();
    })->group('cup-wertung-p11');
});

// ── overallRankingStatus ──────────────────────────────────────────────────────

describe('overallRankingStatus', function () {
    it('ist nicht veraltet, wenn noch nie berechnet wurde', function () {
        $cup = makeCup_cup11();

        $status = service_cup11()->overallRankingStatus($cup);

        expect($status['calculatedAt'])->toBeNull()->and($status['isStale'])->toBeFalse();
    })->group('cup-wertung-p11');

    it('ist veraltet, wenn die Tageswertung NACH der Gesamtwertung neu berechnet wurde', function () {
        $cup = makeCup_cup11();
        $meet = makeMeet_cup11($cup);
        $club = makeClub_cup11();
        $athlete = makeAthlete_cup11();

        CupOverallResult::create([
            'cup_id' => $cup->id, 'athlete_id' => $athlete->id, 'club_id' => $club->id,
            'sport_class_group_id' => SportClassGroup::firstOrCreate(['code' => 'PI'], ['name_de' => 'PI'])->id,
            'gender' => 'M', 'total_points' => 400, 'rounds_counted' => 1, 'counted_meet_ids' => [$meet->id],
            'calculated_at' => '2026-07-14 10:00',
        ]);

        makeDailyResult_cup11($cup, $meet, $athlete, $club, Carbon::parse('2026-07-14 12:00')); // danach

        $status = service_cup11()->overallRankingStatus($cup);

        expect($status['isStale'])->toBeTrue();
    })->group('cup-wertung-p11');
});
