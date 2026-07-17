<?php

use App\Models\BaseTime;
use App\Models\BaseTimeCategory;
use App\Models\BaseTimeDiscipline;
use App\Models\BaseTimeSportClass;
use App\Models\BaseTimeVersion;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\QualifyingTargetPoint;
use App\Models\QualifyingTime;
use App\Models\QualifyingTimeList;
use App\Models\StrokeType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────────────────────

function makeAdmin_qtl2(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function makeClubUser_qtl2(): User
{
    return User::factory()->create(['is_admin' => false]);
}

function makeNation_qtl2(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], [
        'name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true,
    ]);
}

function makeStrokeType_qtl2(string $lenexCode = 'FREE'): StrokeType
{
    return StrokeType::firstOrCreate(['lenex_code' => $lenexCode], [
        'name_de' => $lenexCode, 'name_en' => $lenexCode, 'code' => strtolower($lenexCode),
    ]);
}

function makeVersion_qtl2(): BaseTimeVersion
{
    return BaseTimeVersion::create(['label' => '2021–2026', 'valid_from' => '2021-01-01', 'valid_until' => null]);
}

function makeCategory_qtl2(string $course = 'LCM', string $gender = 'M'): BaseTimeCategory
{
    return BaseTimeCategory::create([
        'code' => "{$course}_$gender",
        'course' => $course,
        'gender' => $gender,
        'label' => "$course $gender",
    ]);
}

function makeDiscipline_qtl2(StrokeType $stroke, int $distance = 100): BaseTimeDiscipline
{
    return BaseTimeDiscipline::create([
        'stroke_type_id' => $stroke->id,
        'distance' => $distance,
        'relay_count' => 1,
        'code' => "$distance$stroke->code",
    ]);
}

function makeSportClass_qtl2(string $code = 'S9'): BaseTimeSportClass
{
    return BaseTimeSportClass::firstOrCreate(['code' => $code], ['sort_order' => 0]);
}

function makeBaseTime_qtl2(
    BaseTimeVersion $version,
    BaseTimeCategory $category,
    BaseTimeDiscipline $discipline,
    BaseTimeSportClass $sportClass,
    int $valueCentiseconds = 6000,
): BaseTime {
    return BaseTime::create([
        'base_time_version_id' => $version->id,
        'base_time_category_id' => $category->id,
        'base_time_discipline_id' => $discipline->id,
        'base_time_sport_class_id' => $sportClass->id,
        'value_centiseconds' => $valueCentiseconds,
        'value_type' => BaseTime::TYPE_MANUAL,
    ]);
}

function makeMeetForList_qtl2(QualifyingTimeList $list): Meet
{
    return Meet::create([
        'name' => "ÖSTM & ÖM $list->year",
        'nation_id' => makeNation_qtl2()->id,
        'course' => 'LCM',
        'start_date' => '2026-05-12',
        'qualifying_time_list_id' => $list->id,
    ]);
}

function makeList_qtl2(int $year = 2026): QualifyingTimeList
{
    return QualifyingTimeList::create(['year' => $year, 'is_active' => true]);
}

// ── Berechtigungen ────────────────────────────────────────────────────────────

describe('QualifyingTimeListController::calculate — Berechtigungen', function () {
    it('Club-User bekommt 403', function () {
        $list = makeList_qtl2();

        $this->actingAs(makeClubUser_qtl2())
            ->post(route('qualifying-time-lists.calculate', $list))
            ->assertForbidden();
    })->group('qualifying-time-lists-p2');
});

// ── Fehlerfälle ────────────────────────────────────────────────────────────────

describe('QualifyingTimeListController::calculate — Fehlerfälle', function () {
    it('meldet Fehler, wenn der Liste kein Meet zugeordnet ist', function () {
        $list = makeList_qtl2();

        $this->actingAs(makeAdmin_qtl2())
            ->post(route('qualifying-time-lists.calculate', $list))
            ->assertSessionHas('error');

        expect(QualifyingTime::count())->toBe(0);
    })->group('qualifying-time-lists-p2');

    it('meldet Fehler, wenn keine gültige Basiswert-Version existiert', function () {
        $list = makeList_qtl2();
        makeMeetForList_qtl2($list);
        // Bewusst KEINE BaseTimeVersion angelegt

        $this->actingAs(makeAdmin_qtl2())
            ->post(route('qualifying-time-lists.calculate', $list))
            ->assertSessionHas('error');
    })->group('qualifying-time-lists-p2');
});

// ── Berechnungslogik ─────────────────────────────────────────────────────────

describe('QualifyingTimeCalculationService — Berechnung', function () {
    it('berechnet die inverse Zeit korrekt aus Basiswert und Zielpunkten (Default 100)', function () {
        $list = makeList_qtl2();
        makeMeetForList_qtl2($list);
        $version = makeVersion_qtl2();
        $category = makeCategory_qtl2();
        $stroke = makeStrokeType_qtl2();
        $discipline = makeDiscipline_qtl2($stroke);
        $sportClass = makeSportClass_qtl2();
        makeBaseTime_qtl2($version, $category, $discipline, $sportClass); // 60.00s (Default)

        $this->actingAs(makeAdmin_qtl2())
            ->post(route('qualifying-time-lists.calculate', $list))
            ->assertRedirect();

        $time = QualifyingTime::where('qualifying_time_list_id', $list->id)
            ->where('gender', 'M')->where('sport_class', 'S9')->first();

        expect($time)->not->toBeNull()
            ->and($time->value_centiseconds)->toBe(12927) // 6000 / (100/1000)^(1/3)
            ->and($time->source)->toBe(QualifyingTime::SOURCE_CALCULATED);
    })->group('qualifying-time-lists-p2');

    it('wendet unterschiedliche Zielpunkte für S2 und SB2 korrekt getrennt an', function () {
        $list = makeList_qtl2();
        makeMeetForList_qtl2($list);
        $version = makeVersion_qtl2();
        $category = makeCategory_qtl2();
        $free = makeStrokeType_qtl2();
        $breast = makeStrokeType_qtl2('BREAST');
        $disciplineFree = makeDiscipline_qtl2($free);
        $disciplineBreast = makeDiscipline_qtl2($breast);
        $sportClass = makeSportClass_qtl2('S2');
        makeBaseTime_qtl2($version, $category, $disciplineFree, $sportClass);
        makeBaseTime_qtl2($version, $category, $disciplineBreast, $sportClass);

        QualifyingTargetPoint::create(['qualifying_time_list_id' => $list->id, 'sport_class' => 'S2', 'points' => 110]);
        QualifyingTargetPoint::create([
            'qualifying_time_list_id' => $list->id, 'sport_class' => 'SB2', 'points' => 120,
        ]);

        $this->actingAs(makeAdmin_qtl2())->post(route('qualifying-time-lists.calculate', $list));

        $s2 = QualifyingTime::where('sport_class', 'S2')->first();
        $sb2 = QualifyingTime::where('sport_class', 'SB2')->first();

        expect($s2->value_centiseconds)->toBe(12522) // 110 Punkte
            ->and($sb2->value_centiseconds)->toBe(12164); // 120 Punkte
    })->group('qualifying-time-lists-p2');

    it('berechnet nur Kombinationen mit tatsächlich vorhandenem Basiswert-Eintrag', function () {
        $list = makeList_qtl2();
        makeMeetForList_qtl2($list);
        makeVersion_qtl2();
        makeCategory_qtl2();
        $stroke = makeStrokeType_qtl2('MEDLEY'); // z.B. 150m Lagen -> keine Basiswert-Disziplin angelegt
        makeDiscipline_qtl2($stroke); // 100m existiert
        // 150m MEDLEY bewusst NICHT als BaseTimeDiscipline angelegt
        makeSportClass_qtl2();
        // Kein BaseTime-Eintrag für die 100m-Disziplin angelegt -> auch diese wird übersprungen

        $this->actingAs(makeAdmin_qtl2())->post(route('qualifying-time-lists.calculate', $list));

        expect(QualifyingTime::count())->toBe(0);
    })->group('qualifying-time-lists-p2');

    it('lässt eine als NOT_APPLICABLE markierte Kombination aus', function () {
        $list = makeList_qtl2();
        makeMeetForList_qtl2($list);
        $version = makeVersion_qtl2();
        $category = makeCategory_qtl2();
        $stroke = makeStrokeType_qtl2();
        $discipline = makeDiscipline_qtl2($stroke);
        $sportClass = makeSportClass_qtl2();
        BaseTime::create([
            'base_time_version_id' => $version->id,
            'base_time_category_id' => $category->id,
            'base_time_discipline_id' => $discipline->id,
            'base_time_sport_class_id' => $sportClass->id,
            'value_centiseconds' => 0,
            'value_type' => BaseTime::TYPE_NOT_APPLICABLE,
        ]);

        $this->actingAs(makeAdmin_qtl2())->post(route('qualifying-time-lists.calculate', $list));

        expect(QualifyingTime::count())->toBe(0);
    })->group('qualifying-time-lists-p2');

    it('lässt manuell gesetzte Zeiten bei Neuberechnung standardmäßig unangetastet', function () {
        $list = makeList_qtl2();
        makeMeetForList_qtl2($list);
        $version = makeVersion_qtl2();
        $category = makeCategory_qtl2();
        $stroke = makeStrokeType_qtl2();
        $discipline = makeDiscipline_qtl2($stroke);
        $sportClass = makeSportClass_qtl2();
        makeBaseTime_qtl2($version, $category, $discipline, $sportClass);

        $manual = QualifyingTime::create([
            'qualifying_time_list_id' => $list->id,
            'stroke_type_id' => $stroke->id,
            'distance' => 100,
            'gender' => 'M',
            'sport_class' => 'S9',
            'value_centiseconds' => 11111,
            'source' => QualifyingTime::SOURCE_MANUAL,
        ]);

        $this->actingAs(makeAdmin_qtl2())->post(route('qualifying-time-lists.calculate', $list));

        expect($manual->fresh()->value_centiseconds)->toBe(11111)
            ->and($manual->fresh()->source)->toBe(QualifyingTime::SOURCE_MANUAL);
    })->group('qualifying-time-lists-p2');

    it('überschreibt manuelle Zeiten mit overwrite_manual=1', function () {
        $list = makeList_qtl2();
        makeMeetForList_qtl2($list);
        $version = makeVersion_qtl2();
        $category = makeCategory_qtl2();
        $stroke = makeStrokeType_qtl2();
        $discipline = makeDiscipline_qtl2($stroke);
        $sportClass = makeSportClass_qtl2();
        makeBaseTime_qtl2($version, $category, $discipline, $sportClass);

        $manual = QualifyingTime::create([
            'qualifying_time_list_id' => $list->id,
            'stroke_type_id' => $stroke->id,
            'distance' => 100,
            'gender' => 'M',
            'sport_class' => 'S9',
            'value_centiseconds' => 11111,
            'source' => QualifyingTime::SOURCE_MANUAL,
        ]);

        $this->actingAs(makeAdmin_qtl2())
            ->post(route('qualifying-time-lists.calculate', $list), ['overwrite_manual' => 1]);

        expect($manual->fresh()->value_centiseconds)->toBe(12927)
            ->and($manual->fresh()->source)->toBe(QualifyingTime::SOURCE_CALCULATED);
    })->group('qualifying-time-lists-p2');

    it('eine manuelle Eingabe über die Verwaltung markiert die Zeile wieder als MANUAL', function () {
        $list = makeList_qtl2();
        $stroke = makeStrokeType_qtl2();
        $calculated = QualifyingTime::create([
            'qualifying_time_list_id' => $list->id,
            'stroke_type_id' => $stroke->id,
            'distance' => 100,
            'gender' => 'M',
            'sport_class' => 'S9',
            'value_centiseconds' => 12927,
            'source' => QualifyingTime::SOURCE_CALCULATED,
        ]);
        makeSportClass_qtl2();

        $this->actingAs(makeAdmin_qtl2())
            ->post(route('qualifying-time-lists.times.store', $list), [
                'stroke_type_id' => $stroke->id,
                'distance' => 100,
                'gender' => 'M',
                'sport_class' => 'S9',
                'value' => '02:00.00',
            ]);

        expect($calculated->fresh()->source)->toBe(QualifyingTime::SOURCE_MANUAL);
    })->group('qualifying-time-lists-p2');
});
