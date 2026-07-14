<?php

/** @noinspection PhpUnhandledExceptionInspection Pest-Test-Closures fangen Exceptions selbst ab. */

use App\Models\Athlete;
use App\Models\AthleteKaderMembership;
use App\Models\BaseTimeVersion;
use App\Models\Club;
use App\Models\Cup;
use App\Models\CupDailyResult;
use App\Models\CupTopGroupClassification;
use App\Models\KaderType;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Models\SportClassGroup;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Services\TopGroupClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────────────────────

function makeNation_cup8(string $code = 'AUT'): Nation
{
    return Nation::firstOrCreate(
        ['code' => $code],
        ['name_de' => $code, 'name_en' => $code, 'is_active' => true]
    );
}

function makeClub_cup8(): Club
{
    return Club::create(['name' => 'Testclub', 'nation_id' => makeNation_cup8()->id]);
}

function makeAthlete_cup8(array $attrs = []): Athlete
{
    return Athlete::create(array_merge([
        'first_name' => 'Max', 'last_name' => 'Mustermann', 'gender' => 'M',
        'nation_id' => makeNation_cup8()->id, 'is_active' => true,
    ], $attrs));
}

function makeCup_cup8(int $year, int $threshold = 450): Cup
{
    $version = BaseTimeVersion::create(['label' => "V$year", 'valid_from' => '2021-01-01']);

    return Cup::create([
        'year' => $year, 'name' => "ÖBSV Cup $year", 'base_time_version_id' => $version->id,
        'rounds_count' => 1, 'best_of_count' => 3, 'top_group_points_threshold' => $threshold,
    ]);
}

function makeMeet_cup8(Cup $cup): Meet
{
    return Meet::create([
        'name' => 'Testmeet', 'nation_id' => makeNation_cup8()->id,
        'course' => 'LCM', 'start_date' => "$cup->year-06-01", 'cup_id' => $cup->id,
    ]);
}

function makeStrokeType_cup8(): StrokeType
{
    return StrokeType::firstOrCreate(
        ['code' => 'FREE'],
        ['lenex_code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle', 'category' => 'standard', 'is_active' => true]
    );
}

/** Legt direkt eine Tageswertungs-Zeile in einem bestimmten Cup-Jahr an. */
function makeDailyResult_cup8(Cup $cup, Athlete $athlete, Club $club, int $points): CupDailyResult
{
    $meet = makeMeet_cup8($cup);
    $group = SportClassGroup::firstOrCreate(['code' => 'PI'], ['name_de' => 'PI', 'is_active' => true]);
    $event = SwimEvent::create([
        'meet_id' => $meet->id, 'stroke_type_id' => makeStrokeType_cup8()->id, 'distance' => 100, 'gender' => 'A',
    ]);
    $result = Result::create([
        'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id, 'club_id' => $club->id,
        'sport_class' => 'S9', 'swim_time' => 60000, 'points' => 1,
    ]);

    return CupDailyResult::create([
        'cup_id' => $cup->id, 'meet_id' => $meet->id, 'athlete_id' => $athlete->id, 'club_id' => $club->id,
        'result_id' => $result->id, 'sport_class_group_id' => $group->id, 'gender' => $athlete->gender,
        'points' => $points, 'calculated_at' => now(),
    ]);
}

// ── calculateForCup: Punkte-Historie ─────────────────────────────────────────

describe('calculateForCup — Punkte-Historie', function () {
    it('stuft einen Athleten hoch, der im Vorjahr über der Punktgrenze war', function () {
        $club = makeClub_cup8();
        $athlete = makeAthlete_cup8();
        $cup2025 = makeCup_cup8(2025);
        makeDailyResult_cup8($cup2025, $athlete, $club, 460);

        $cup2026 = makeCup_cup8(2026);
        app(TopGroupClassificationService::class)->calculateForCup($cup2026);

        $classification = CupTopGroupClassification::where('cup_id', $cup2026->id)->firstOrFail();

        expect($classification->is_top_group)->toBeTrue()
            ->and($classification->reason)->toBe('POINTS_HISTORY')
            ->and($classification->reference_points)->toBe(460);
    })->group('cup-wertung-p8');

    it('stuft NICHT hoch, wenn die Punktgrenze in beiden Vorjahren nicht überschritten wurde', function () {
        $club = makeClub_cup8();
        $athlete = makeAthlete_cup8();
        $cup2025 = makeCup_cup8(2025);
        makeDailyResult_cup8($cup2025, $athlete, $club, 400);

        $cup2026 = makeCup_cup8(2026);
        app(TopGroupClassificationService::class)->calculateForCup($cup2026);

        $classification = CupTopGroupClassification::where('cup_id', $cup2026->id)->firstOrFail();

        expect($classification->is_top_group)->toBeFalse()->and($classification->reason)->toBeNull();
    })->group('cup-wertung-p8');

    it('berücksichtigt nur die beiden Kalenderjahre unmittelbar vor dem Cup-Jahr, nicht davor', function () {
        $club = makeClub_cup8();
        $athlete = makeAthlete_cup8();
        $cup2023 = makeCup_cup8(2023); // 3 Jahre vor 2026 → darf NICHT zählen
        makeDailyResult_cup8($cup2023, $athlete, $club, 500);

        $cup2026 = makeCup_cup8(2026);
        app(TopGroupClassificationService::class)->calculateForCup($cup2026);

        $classification = CupTopGroupClassification::where('cup_id', $cup2026->id)->first();

        // Athlet taucht gar nicht in der Klassifizierung auf, da weder Punkte-Historie noch Kader zutreffen.
        expect($classification)->toBeNull();
    })->group('cup-wertung-p8');

    it('verwendet den höheren Wert, wenn beide Vorjahre Ergebnisse liefern', function () {
        $club = makeClub_cup8();
        $athlete = makeAthlete_cup8();
        $cup2024 = makeCup_cup8(2024);
        $cup2025 = makeCup_cup8(2025);
        makeDailyResult_cup8($cup2024, $athlete, $club, 300);
        makeDailyResult_cup8($cup2025, $athlete, $club, 470);

        $cup2026 = makeCup_cup8(2026);
        app(TopGroupClassificationService::class)->calculateForCup($cup2026);

        $classification = CupTopGroupClassification::where('cup_id', $cup2026->id)->firstOrFail();

        expect($classification->reference_points)->toBe(470)->and($classification->is_top_group)->toBeTrue();
    })->group('cup-wertung-p8');
});

// ── calculateForCup: Nationalkader ───────────────────────────────────────────

describe('calculateForCup — Nationalkader', function () {
    it('Kader-Athlet bleibt Top-Gruppe, auch ohne Punkte-Historie', function () {
        $athlete = makeAthlete_cup8();
        $kaderType = KaderType::create(['code' => 'WELTKLASSE', 'name_de' => 'Weltklasse']);
        AthleteKaderMembership::create(['athlete_id' => $athlete->id, 'kader_type_id' => $kaderType->id]);

        $cup2026 = makeCup_cup8(2026);
        app(TopGroupClassificationService::class)->calculateForCup($cup2026);

        $classification = CupTopGroupClassification::where('cup_id', $cup2026->id)->firstOrFail();

        expect($classification->is_top_group)->toBeTrue()
            ->and($classification->reason)->toBe('KADER')
            ->and($classification->reference_points)->toBeNull();
    })->group('cup-wertung-p8');

    it('Kader-Status übersteuert eine niedrige Punkte-Historie (kein Abstieg trotz Punkten unter der Grenze)', function () {
        $club = makeClub_cup8();
        $athlete = makeAthlete_cup8();
        $kaderType = KaderType::create(['code' => 'WELTKLASSE', 'name_de' => 'Weltklasse']);
        AthleteKaderMembership::create(['athlete_id' => $athlete->id, 'kader_type_id' => $kaderType->id]);
        $cup2025 = makeCup_cup8(2025);
        makeDailyResult_cup8($cup2025, $athlete, $club, 100); // weit unter der Grenze

        $cup2026 = makeCup_cup8(2026);
        app(TopGroupClassificationService::class)->calculateForCup($cup2026);

        $classification = CupTopGroupClassification::where('cup_id', $cup2026->id)->firstOrFail();

        expect($classification->is_top_group)->toBeTrue()->and($classification->reason)->toBe('KADER');
    })->group('cup-wertung-p8');
});

// ── calculateForCup: Grundlagen ──────────────────────────────────────────────

describe('calculateForCup — Grundlagen', function () {
    it('ersetzt bei erneuter Berechnung den bisherigen Snapshot vollständig', function () {
        $athlete = makeAthlete_cup8();
        $kaderType = KaderType::create(['code' => 'WELTKLASSE', 'name_de' => 'Weltklasse']);
        AthleteKaderMembership::create(['athlete_id' => $athlete->id, 'kader_type_id' => $kaderType->id]);
        $cup2026 = makeCup_cup8(2026);

        app(TopGroupClassificationService::class)->calculateForCup($cup2026);
        expect(CupTopGroupClassification::where('cup_id', $cup2026->id)->count())->toBe(1);

        app(TopGroupClassificationService::class)->calculateForCup($cup2026);
        expect(CupTopGroupClassification::where('cup_id', $cup2026->id)->count())->toBe(1);
    })->group('cup-wertung-p8');

    it('loadClassificationMap liefert eine athlete_id => is_top_group Map', function () {
        $athlete = makeAthlete_cup8();
        $kaderType = KaderType::create(['code' => 'WELTKLASSE', 'name_de' => 'Weltklasse']);
        AthleteKaderMembership::create(['athlete_id' => $athlete->id, 'kader_type_id' => $kaderType->id]);
        $cup2026 = makeCup_cup8(2026);
        $service = app(TopGroupClassificationService::class);
        $service->calculateForCup($cup2026);

        $map = $service->loadClassificationMap($cup2026);

        expect($map->get($athlete->id))->toBeTrue();
    })->group('cup-wertung-p8');
});
