<?php

/** @noinspection PhpUnhandledExceptionInspection Pest-Test-Closures fangen Exceptions selbst ab. */

use App\Enums\TopGroupReason;
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

uses(TestCase::class, RefreshDatabase::class)->group('cup-top-group-p1');

// ── Helpers ──────────────────────────────────────────────────────────────────

function makeNation_ctg1(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'AUT', 'name_en' => 'AUT', 'is_active' => true]);
}

function makeClub_ctg1(): Club
{
    return Club::firstOrCreate(['name' => 'Testclub'], ['nation_id' => makeNation_ctg1()->id]);
}

function makeAthlete_ctg1(): Athlete
{
    return Athlete::create([
        'first_name' => 'Max', 'last_name' => 'Mustermann', 'gender' => 'M',
        'nation_id' => makeNation_ctg1()->id, 'is_active' => true,
    ]);
}

function makeCup_ctg1(int $year, int $threshold): Cup
{
    $version = BaseTimeVersion::create(['label' => "V$year", 'valid_from' => '2012-01-01']);

    return Cup::create([
        'year' => $year, 'name' => "ÖBSV Cup $year", 'base_time_version_id' => $version->id,
        'rounds_count' => 1, 'best_of_count' => 3, 'top_group_points_threshold' => $threshold,
    ]);
}

/** Legt eine Tageswertungs-Zeile (ein Start) des Athleten im Cup an. */
function makeStart_ctg1(Cup $cup, Athlete $athlete, int $points): CupDailyResult
{
    $nation = makeNation_ctg1();
    $club = makeClub_ctg1();
    $meet = Meet::create([
        'name' => 'Testmeet', 'nation_id' => $nation->id, 'course' => 'LCM', 'start_date' => "$cup->year-06-01",
        'cup_id' => $cup->id,
    ]);
    $group = SportClassGroup::firstOrCreate(['code' => 'PI'], ['name_de' => 'PI', 'is_active' => true]);
    $stroke = StrokeType::firstOrCreate(['code' => 'FREE'],
        ['lenex_code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle', 'category' => 'standard', 'is_active' => true]);
    $event = SwimEvent::create(['meet_id' => $meet->id, 'stroke_type_id' => $stroke->id, 'distance' => 100, 'gender' => 'A']);
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

function makeKader_ctg1(Athlete $athlete, ?string $from, ?string $until): AthleteKaderMembership
{
    $type = KaderType::firstOrCreate(['code' => 'WELTKLASSE'], ['name_de' => 'Weltklasse']);

    return AthleteKaderMembership::create([
        'athlete_id' => $athlete->id, 'kader_type_id' => $type->id, 'valid_from' => $from, 'valid_until' => $until,
    ]);
}

/** Legt eine Einstufung direkt an, ohne Berechnung (z. B. für einen vorhandenen Vorjahresstatus). */
function makeClassification_ctg1(Cup $cup, Athlete $athlete, bool $isTopGroup, ?bool $manual): CupTopGroupClassification
{
    return CupTopGroupClassification::create([
        'cup_id' => $cup->id, 'athlete_id' => $athlete->id, 'is_top_group' => $manual ?? $isTopGroup,
        'calculated_is_top_group' => $isTopGroup, 'reason' => TopGroupReason::CarriedOver,
        'manual_is_top_group' => $manual, 'manual_note' => $manual === null ? null : 'Trainerwunsch',
        'calculated_at' => now()->subDay(),
    ]);
}

function classify_ctg1(Cup $cup): void
{
    app(TopGroupClassificationService::class)->calculateForCup($cup);
}

function classificationOf_ctg1(Cup $cup, Athlete $athlete): ?CupTopGroupClassification
{
    return CupTopGroupClassification::where('cup_id', $cup->id)->where('athlete_id', $athlete->id)->first();
}

// ── Zwei-Jahres-Regel ────────────────────────────────────────────────────────

describe('Zwei-Jahres-Regel', function () {
    it('stuft hoch, wer zwei Cup-Jahre hintereinander über der Grenze war', function () {
        $athlete = makeAthlete_ctg1();
        makeStart_ctg1($cup2024 = makeCup_ctg1(2024, 450), $athlete, 460);
        makeStart_ctg1($cup2025 = makeCup_ctg1(2025, 450), $athlete, 480);
        makeStart_ctg1($cup2025, $athlete, 300);
        classify_ctg1($cup2026 = makeCup_ctg1(2026, 450));

        $classification = classificationOf_ctg1($cup2026, $athlete);

        expect($classification->is_top_group)->toBeTrue()
            ->and($classification->calculated_is_top_group)->toBeTrue()
            ->and($classification->reason)->toBe(TopGroupReason::TwoYearsAbove)
            ->and($classification->previous_is_top_group)->toBeFalse()
            ->and($classification->last_start_year)->toBe(2025)
            ->and($classification->last_start_points)->toBe(480)
            ->and($classification->prior_start_year)->toBe(2024)
            ->and($classification->prior_start_points)->toBe(460)
            ->and(classificationOf_ctg1($cup2024, $athlete))->toBeNull();
    });

    it('stuft nicht hoch, wenn nur ein Jahr über der Grenze war', function () {
        $athlete = makeAthlete_ctg1();
        makeStart_ctg1(makeCup_ctg1(2024, 450), $athlete, 300);
        makeStart_ctg1(makeCup_ctg1(2025, 450), $athlete, 500);
        classify_ctg1($cup2026 = makeCup_ctg1(2026, 450));

        $classification = classificationOf_ctg1($cup2026, $athlete);

        expect($classification->is_top_group)->toBeFalse()
            ->and($classification->reason)->toBe(TopGroupReason::CarriedOver);
    });

    it('stuft einen neuen Athleten mit nur einem Startjahr nicht hoch', function () {
        $athlete = makeAthlete_ctg1();
        makeStart_ctg1(makeCup_ctg1(2025, 450), $athlete, 600);
        classify_ctg1($cup2026 = makeCup_ctg1(2026, 450));

        $classification = classificationOf_ctg1($cup2026, $athlete);

        expect($classification->is_top_group)->toBeFalse()
            ->and($classification->reason)->toBe(TopGroupReason::CarriedOver)
            ->and($classification->prior_start_year)->toBeNull();
    });

    it('lässt einen Top-Athleten nach einem Jahr unter der Grenze in der Top-Gruppe', function () {
        $athlete = makeAthlete_ctg1();
        makeStart_ctg1(makeCup_ctg1(2024, 450), $athlete, 500);
        makeStart_ctg1($cup2025 = makeCup_ctg1(2025, 450), $athlete, 400);
        makeClassification_ctg1($cup2025, $athlete, true, null);
        classify_ctg1($cup2026 = makeCup_ctg1(2026, 450));

        $classification = classificationOf_ctg1($cup2026, $athlete);

        expect($classification->is_top_group)->toBeTrue()
            ->and($classification->reason)->toBe(TopGroupReason::CarriedOver)
            ->and($classification->previous_is_top_group)->toBeTrue();
    });

    it('stuft ab, wer zwei Cup-Jahre hintereinander unter der Grenze war', function () {
        $athlete = makeAthlete_ctg1();
        makeStart_ctg1(makeCup_ctg1(2024, 450), $athlete, 400);
        makeStart_ctg1($cup2025 = makeCup_ctg1(2025, 450), $athlete, 420);
        makeClassification_ctg1($cup2025, $athlete, true, null);
        classify_ctg1($cup2026 = makeCup_ctg1(2026, 450));

        $classification = classificationOf_ctg1($cup2026, $athlete);

        expect($classification->is_top_group)->toBeFalse()
            ->and($classification->reason)->toBe(TopGroupReason::TwoYearsBelow);
    });

    it('wertet genau die Grenze als nicht darüber', function () {
        $athlete = makeAthlete_ctg1();
        makeStart_ctg1(makeCup_ctg1(2024, 450), $athlete, 450);
        makeStart_ctg1(makeCup_ctg1(2025, 450), $athlete, 500);
        classify_ctg1($cup2026 = makeCup_ctg1(2026, 450));

        expect(classificationOf_ctg1($cup2026, $athlete)->is_top_group)->toBeFalse();
    });

    it('misst beide Vorjahre an der Grenze des einzustufenden Cups', function () {
        $athlete = makeAthlete_ctg1();
        makeStart_ctg1(makeCup_ctg1(2024, 600), $athlete, 500);
        makeStart_ctg1(makeCup_ctg1(2025, 600), $athlete, 500);
        classify_ctg1($cup2026 = makeCup_ctg1(2026, 450));

        expect(classificationOf_ctg1($cup2026, $athlete)->reason)->toBe(TopGroupReason::TwoYearsAbove);
    });
});

// ── Jahre ohne Start ─────────────────────────────────────────────────────────

describe('Jahre ohne Start', function () {
    it('übernimmt den Status, wenn im Vorjahr kein Start war', function () {
        $athlete = makeAthlete_ctg1();
        makeStart_ctg1(makeCup_ctg1(2023, 450), $athlete, 300);
        makeStart_ctg1(makeCup_ctg1(2024, 450), $athlete, 300);
        makeClassification_ctg1(makeCup_ctg1(2025, 450), $athlete, true, null);
        classify_ctg1($cup2026 = makeCup_ctg1(2026, 450));

        $classification = classificationOf_ctg1($cup2026, $athlete);

        expect($classification->is_top_group)->toBeTrue()
            ->and($classification->reason)->toBe(TopGroupReason::CarriedOver)
            ->and($classification->last_start_year)->toBe(2024);
    });

    it('überspringt ein Jahr ohne Start beim Vergleich zweier Jahre', function () {
        $athlete = makeAthlete_ctg1();
        makeStart_ctg1(makeCup_ctg1(2023, 450), $athlete, 500);
        makeCup_ctg1(2024, 450);
        makeStart_ctg1(makeCup_ctg1(2025, 450), $athlete, 510);
        classify_ctg1($cup2026 = makeCup_ctg1(2026, 450));

        $classification = classificationOf_ctg1($cup2026, $athlete);

        expect($classification->reason)->toBe(TopGroupReason::TwoYearsAbove)
            ->and($classification->prior_start_year)->toBe(2023);
    });

    it('legt für Athleten ohne Start im Vorjahr und ohne Top-Status keine Einstufung an', function () {
        $athlete = makeAthlete_ctg1();
        makeStart_ctg1(makeCup_ctg1(2024, 450), $athlete, 300);
        makeCup_ctg1(2025, 450);
        classify_ctg1($cup2026 = makeCup_ctg1(2026, 450));

        expect(classificationOf_ctg1($cup2026, $athlete))->toBeNull();
    });
});

// ── Kettenstart ──────────────────────────────────────────────────────────────

describe('Kettenstart', function () {
    it('rechnet fehlende Einstufungen früherer Cups vom ältesten an nach', function () {
        $athlete = makeAthlete_ctg1();
        makeStart_ctg1($cup2023 = makeCup_ctg1(2023, 450), $athlete, 500);
        makeStart_ctg1($cup2024 = makeCup_ctg1(2024, 450), $athlete, 500);
        makeStart_ctg1($cup2025 = makeCup_ctg1(2025, 450), $athlete, 300);
        $cup2026 = makeCup_ctg1(2026, 450);

        expect(app(TopGroupClassificationService::class)->predecessorsWithoutClassification($cup2026)->pluck('year')->all())
            ->toBe([2023, 2024, 2025]);

        classify_ctg1($cup2026);

        // 2025: zwei Jahre drüber → Top; 2026: 2025 drunter, 2024 drüber → Status bleibt Top.
        expect(classificationOf_ctg1($cup2023, $athlete))->toBeNull()
            ->and(classificationOf_ctg1($cup2024, $athlete)->is_top_group)->toBeFalse()
            ->and(classificationOf_ctg1($cup2025, $athlete)->reason)->toBe(TopGroupReason::TwoYearsAbove)
            ->and(classificationOf_ctg1($cup2026, $athlete)->is_top_group)->toBeTrue()
            ->and(classificationOf_ctg1($cup2026, $athlete)->reason)->toBe(TopGroupReason::CarriedOver);
    });

    it('rechnet bestehende Einstufungen früherer Cups nicht neu', function () {
        $athlete = makeAthlete_ctg1();
        makeStart_ctg1($cup2025 = makeCup_ctg1(2025, 450), $athlete, 300);
        $existing = makeClassification_ctg1($cup2025, $athlete, true, null);
        classify_ctg1($cup2026 = makeCup_ctg1(2026, 450));

        expect($existing->fresh()->calculated_at->equalTo($existing->calculated_at))->toBeTrue()
            ->and(classificationOf_ctg1($cup2026, $athlete)->previous_is_top_group)->toBeTrue();
    });

    it('hat im ersten Cup keinen Vorjahresstatus', function () {
        $athlete = makeAthlete_ctg1();
        makeKader_ctg1($athlete, null, null);
        classify_ctg1($cup2026 = makeCup_ctg1(2026, 450));

        expect(classificationOf_ctg1($cup2026, $athlete)->previous_is_top_group)->toBeNull();
    });
});

// ── Nationalkader ────────────────────────────────────────────────────────────

describe('Nationalkader', function () {
    it('stuft Kader-Athleten trotz zwei Jahren unter der Grenze in die Top-Gruppe', function () {
        $athlete = makeAthlete_ctg1();
        makeKader_ctg1($athlete, null, null);
        makeStart_ctg1(makeCup_ctg1(2024, 450), $athlete, 100);
        makeStart_ctg1(makeCup_ctg1(2025, 450), $athlete, 100);
        classify_ctg1($cup2026 = makeCup_ctg1(2026, 450));

        $classification = classificationOf_ctg1($cup2026, $athlete);

        expect($classification->is_top_group)->toBeTrue()
            ->and($classification->reason)->toBe(TopGroupReason::Kader)
            ->and($classification->last_start_points)->toBe(100);
    });

    it('zählt eine Kaderzugehörigkeit, die erst im Lauf des Cup-Jahres beginnt', function () {
        $athlete = makeAthlete_ctg1();
        makeKader_ctg1($athlete, '2026-05-01', null);
        classify_ctg1($cup2026 = makeCup_ctg1(2026, 450));

        expect(classificationOf_ctg1($cup2026, $athlete)->reason)->toBe(TopGroupReason::Kader);
    });

    it('zählt eine vor dem Cup-Jahr beendete Kaderzugehörigkeit nicht', function () {
        $athlete = makeAthlete_ctg1();
        makeKader_ctg1($athlete, '2020-01-01', '2025-12-31');
        classify_ctg1($cup2026 = makeCup_ctg1(2026, 450));

        expect(classificationOf_ctg1($cup2026, $athlete))->toBeNull();
    });

    it('lässt nach dem Kaderende den Top-Status bis zu zwei Jahren unter der Grenze weiterlaufen', function () {
        $athlete = makeAthlete_ctg1();
        makeKader_ctg1($athlete, '2020-01-01', '2025-06-30');
        makeStart_ctg1($cup2025 = makeCup_ctg1(2025, 450), $athlete, 300);
        classify_ctg1($cup2026 = makeCup_ctg1(2026, 450));

        expect(classificationOf_ctg1($cup2025, $athlete)->reason)->toBe(TopGroupReason::Kader)
            ->and(classificationOf_ctg1($cup2026, $athlete)->is_top_group)->toBeTrue()
            ->and(classificationOf_ctg1($cup2026, $athlete)->reason)->toBe(TopGroupReason::CarriedOver);
    });
});

// ── Hand-Einstufung ──────────────────────────────────────────────────────────

describe('Hand-Einstufung', function () {
    it('bleibt bei Neuberechnung erhalten und geht vor dem Kader', function () {
        $athlete = makeAthlete_ctg1();
        makeKader_ctg1($athlete, null, null);
        $cup2026 = makeCup_ctg1(2026, 450);
        makeClassification_ctg1($cup2026, $athlete, true, false);

        classify_ctg1($cup2026);

        $classification = classificationOf_ctg1($cup2026, $athlete);

        expect($classification->is_top_group)->toBeFalse()
            ->and($classification->calculated_is_top_group)->toBeTrue()
            ->and($classification->reason)->toBe(TopGroupReason::Kader)
            ->and($classification->isManual())->toBeTrue()
            ->and($classification->manual_note)->toBe('Trainerwunsch');
    });

    it('wirkt im Folgejahr als Vorjahresstatus', function () {
        $athlete = makeAthlete_ctg1();
        makeStart_ctg1($cup2025 = makeCup_ctg1(2025, 450), $athlete, 300);
        makeClassification_ctg1($cup2025, $athlete, false, true);
        classify_ctg1($cup2026 = makeCup_ctg1(2026, 450));

        $classification = classificationOf_ctg1($cup2026, $athlete);

        expect($classification->is_top_group)->toBeTrue()
            ->and($classification->previous_is_top_group)->toBeTrue()
            ->and($classification->isManual())->toBeFalse();
    });
});

// ── Grundlagen ───────────────────────────────────────────────────────────────

describe('Grundlagen', function () {
    it('ersetzt bei erneuter Berechnung den bisherigen Snapshot', function () {
        $athlete = makeAthlete_ctg1();
        makeKader_ctg1($athlete, null, null);
        $cup2026 = makeCup_ctg1(2026, 450);

        classify_ctg1($cup2026);
        classify_ctg1($cup2026);

        expect(CupTopGroupClassification::where('cup_id', $cup2026->id)->count())->toBe(1);
    });

    it('loadClassificationMap liefert die wirksame Einstufung je Athlet', function () {
        $kader = makeAthlete_ctg1();
        makeKader_ctg1($kader, null, null);
        $manual = makeAthlete_ctg1();
        $cup2026 = makeCup_ctg1(2026, 450);
        makeClassification_ctg1($cup2026, $manual, false, true);
        classify_ctg1($cup2026);

        $map = app(TopGroupClassificationService::class)->loadClassificationMap($cup2026);

        expect($map->get($kader->id))->toBeTrue()
            ->and($map->get($manual->id))->toBeTrue();
    });
});
