<?php

use App\Models\BaseTime;
use App\Models\BaseTimeCategory;
use App\Models\BaseTimeDiscipline;
use App\Models\BaseTimeSportClass;
use App\Models\BaseTimeVersion;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\QualifyingExcludedDiscipline;
use App\Models\QualifyingTime;
use App\Models\QualifyingTimeList;
use App\Models\StrokeType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────────────────────

function makeAdmin_qtl4(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function makeClubUser_qtl4(): User
{
    return User::factory()->create(['is_admin' => false]);
}

function makeNation_qtl4(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], [
        'name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true,
    ]);
}

function makeStrokeType_qtl4(string $lenexCode = 'FREE'): StrokeType
{
    return StrokeType::firstOrCreate(['lenex_code' => $lenexCode], [
        'name_de' => $lenexCode, 'name_en' => $lenexCode, 'code' => strtolower($lenexCode),
    ]);
}

function makeDiscipline_qtl4(StrokeType $stroke, int $distance): BaseTimeDiscipline
{
    return BaseTimeDiscipline::create([
        'stroke_type_id' => $stroke->id,
        'distance' => $distance,
        'relay_count' => 1,
        'code' => "$distance$stroke->code",
    ]);
}

function makeMeetForList_qtl4(QualifyingTimeList $list): Meet
{
    return Meet::create([
        'name' => "ÖSTM & ÖM $list->year",
        'nation_id' => makeNation_qtl4()->id,
        'course' => 'LCM',
        'start_date' => '2026-05-12',
        'qualifying_time_list_id' => $list->id,
    ]);
}

function makeList_qtl4(int $year = 2026): QualifyingTimeList
{
    return QualifyingTimeList::create(['year' => $year, 'is_active' => true]);
}

function makeFullSetup_qtl4(BaseTimeDiscipline $discipline): void
{
    $version = BaseTimeVersion::firstOrCreate(['label' => 'V1'], ['valid_from' => '2021-01-01']);
    $category = BaseTimeCategory::firstOrCreate(
        ['code' => 'LCM_M'],
        ['course' => 'LCM', 'gender' => 'M', 'label' => 'LCM M']
    );
    $sportClass = BaseTimeSportClass::firstOrCreate(['code' => 'S9'], ['sort_order' => 0]);

    BaseTime::create([
        'base_time_version_id' => $version->id,
        'base_time_category_id' => $category->id,
        'base_time_discipline_id' => $discipline->id,
        'base_time_sport_class_id' => $sportClass->id,
        'value_centiseconds' => 6000,
        'value_type' => BaseTime::TYPE_MANUAL,
    ]);
}

// ── Verwaltung der Ausschlussliste ──────────────────────────────────────────────

describe('QualifyingExcludedDisciplineController', function () {
    it('Club-User bekommt 403', function () {
        $discipline = makeDiscipline_qtl4(makeStrokeType_qtl4(), 25);

        $this->actingAs(makeClubUser_qtl4())->get(route('qualifying-excluded-disciplines.index'))->assertForbidden();
        $this->actingAs(makeClubUser_qtl4())
            ->post(route('qualifying-excluded-disciplines.store', $discipline))
            ->assertForbidden();
    })->group('qualifying-time-lists-p2');

    it('Admin kann einen Bewerb ausschließen und wieder zulassen', function () {
        $discipline = makeDiscipline_qtl4(makeStrokeType_qtl4(), 25);

        $this->actingAs(makeAdmin_qtl4())
            ->post(route('qualifying-excluded-disciplines.store', $discipline))
            ->assertRedirect();

        expect(QualifyingExcludedDiscipline::where('base_time_discipline_id', $discipline->id)->exists())->toBeTrue();

        $this->actingAs(makeAdmin_qtl4())
            ->delete(route('qualifying-excluded-disciplines.destroy', $discipline))
            ->assertRedirect();

        expect(QualifyingExcludedDiscipline::where('base_time_discipline_id', $discipline->id)->exists())->toBeFalse();
    })->group('qualifying-time-lists-p2');
});

// ── Auswirkung auf die Berechnung ────────────────────────────────────────────────

describe('QualifyingTimeCalculationService — Ausschlussliste', function () {
    it('berechnet einen als ausgeschlossen markierten Bewerb nicht', function () {
        $list = makeList_qtl4();
        makeMeetForList_qtl4($list);
        $discipline25m = makeDiscipline_qtl4(makeStrokeType_qtl4(), 25);
        makeFullSetup_qtl4($discipline25m);

        QualifyingExcludedDiscipline::create(['base_time_discipline_id' => $discipline25m->id]);

        $this->actingAs(makeAdmin_qtl4())->post(route('qualifying-time-lists.calculate', $list));

        expect(QualifyingTime::count())->toBe(0);
    })->group('qualifying-time-lists-p2');

    it('berechnet einen NICHT ausgeschlossenen Bewerb weiterhin normal', function () {
        $list = makeList_qtl4();
        makeMeetForList_qtl4($list);
        $discipline100m = makeDiscipline_qtl4(makeStrokeType_qtl4(), 100);
        makeFullSetup_qtl4($discipline100m);
        // Bewusst NICHT als QualifyingExcludedDiscipline angelegt

        $this->actingAs(makeAdmin_qtl4())->post(route('qualifying-time-lists.calculate', $list));

        expect(QualifyingTime::count())->toBe(1);
    })->group('qualifying-time-lists-p2');

    it('schließt 800m und 1500m Freistil aus, sobald entsprechend markiert, andere Distanzen bleiben unberührt',
        function () {
            $list = makeList_qtl4();
            makeMeetForList_qtl4($list);
            $free = makeStrokeType_qtl4();
            $discipline800 = makeDiscipline_qtl4($free, 800);
            $discipline1500 = makeDiscipline_qtl4($free, 1500);
            $discipline400 = makeDiscipline_qtl4($free, 400);
            makeFullSetup_qtl4($discipline800);
            makeFullSetup_qtl4($discipline1500);
            makeFullSetup_qtl4($discipline400);

            QualifyingExcludedDiscipline::create(['base_time_discipline_id' => $discipline800->id]);
            QualifyingExcludedDiscipline::create(['base_time_discipline_id' => $discipline1500->id]);

            $this->actingAs(makeAdmin_qtl4())->post(route('qualifying-time-lists.calculate', $list));

            expect(QualifyingTime::where('distance', 800)->count())->toBe(0)
                ->and(QualifyingTime::where('distance', 1500)->count())->toBe(0)
                ->and(QualifyingTime::where('distance', 400)->count())->toBe(1);
        })->group('qualifying-time-lists-p2');
});
