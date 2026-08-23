<?php

use App\Models\BaseTime;
use App\Models\BaseTimeCategory;
use App\Models\BaseTimeDiscipline;
use App\Models\BaseTimeSportClass;
use App\Models\BaseTimeVersion;
use App\Models\StrokeType;
use App\Services\PointConversionService;
use App\Services\WpsPointCalculator;
use App\Services\WpsScmConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('public-p6');

// version_wps2()/parameter_wps2()/stroke_wps2() kommen aus tests/helpers_wps2.php (global via
// tests/Pest.php geladen) — dieselben Helfer wie die bestehende WPS-Test-Suite, keine eigenen.

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function makeStrokeType_pfr6(string $lenexCode = 'FREE'): StrokeType
{
    $names = [
        'FREE' => ['Freistil', 'Freestyle'],
        'BACK' => ['Rücken', 'Backstroke'],
    ];

    return StrokeType::firstOrCreate(['lenex_code' => $lenexCode], [
        'code' => $lenexCode,
        'name_de' => $names[$lenexCode][0],
        'name_en' => $names[$lenexCode][1],
    ]);
}

function makeVersion_pfr6(array $overrides = []): BaseTimeVersion
{
    return BaseTimeVersion::create(array_merge([
        'label' => 'Test-Version',
        'valid_from' => now()->subYear()->toDateString(),
        'valid_until' => null,
    ], $overrides));
}

function makeCategory_pfr6(string $course = 'LCM', string $gender = 'M'): BaseTimeCategory
{
    return BaseTimeCategory::firstOrCreate(
        ['code' => $course.'_'.$gender],
        ['course' => $course, 'gender' => $gender, 'label' => "$course $gender"]
    );
}

function makeDiscipline_pfr6(int $distance = 50, string $lenexCode = 'FREE'): BaseTimeDiscipline
{
    $stroke = makeStrokeType_pfr6($lenexCode);

    return BaseTimeDiscipline::firstOrCreate(
        ['stroke_type_id' => $stroke->id, 'distance' => $distance, 'relay_count' => 1],
        ['code' => $distance.$lenexCode]
    );
}

function makeSportClass_pfr6(string $code = 'S6', int $sortOrder = 6): BaseTimeSportClass
{
    return BaseTimeSportClass::firstOrCreate(['code' => $code], ['sort_order' => $sortOrder]);
}

function putBaseTime_pfr6(
    BaseTimeVersion $version, BaseTimeCategory $category, BaseTimeDiscipline $discipline,
    BaseTimeSportClass $sportClass, int $centiseconds, string $type = BaseTime::TYPE_MANUAL
): BaseTime {
    return BaseTime::create([
        'base_time_version_id' => $version->id,
        'base_time_category_id' => $category->id,
        'base_time_discipline_id' => $discipline->id,
        'base_time_sport_class_id' => $sportClass->id,
        'value_centiseconds' => $centiseconds,
        'value_type' => $type,
    ]);
}

// ── PointConversionService: Hin-/Rückrechnung ────────────────────────────────────

it('rechnet Zeit zu Punkten und wieder zurück konsistent (Rundtrip)', function () {
    $version = makeVersion_pfr6();
    $category = makeCategory_pfr6();
    $discipline = makeDiscipline_pfr6();
    $sportClass = makeSportClass_pfr6();
    putBaseTime_pfr6($version, $category, $discipline, $sportClass, 3000); // 30.00s

    $service = new PointConversionService;

    $toPoints = $service->timeToPoints(
        $version, 'LCM', 'M', $discipline->stroke_type_id, $discipline->distance, 'S6', 3200 // 32.00s
    );
    expect($toPoints->value)->not->toBeNull();

    $toTime = $service->pointsToTime(
        $version, 'LCM', 'M', $discipline->stroke_type_id, $discipline->distance, 'S6', $toPoints->value
    );

    // Die Rückrechnung darf die Ausgangszeit um höchstens eine Hundertstelsekunde verfehlen
    // (Rundungstoleranz der hundertstelweisen Annäherung).
    expect(abs($toTime->value - 3200))->toBeLessThanOrEqual(1);

    $recalculated = $service->timeToPoints(
        $version, 'LCM', 'M', $discipline->stroke_type_id, $discipline->distance, 'S6', $toTime->value
    );
    expect($recalculated->value)->toBeGreaterThanOrEqual($toPoints->value);
});

it('liefert bei Punkte→Zeit eine Zeit, deren Rückrechnung mindestens die Zielpunktzahl erreicht', function () {
    $version = makeVersion_pfr6();
    $category = makeCategory_pfr6();
    $discipline = makeDiscipline_pfr6();
    $sportClass = makeSportClass_pfr6();
    putBaseTime_pfr6($version, $category, $discipline, $sportClass, 3000);

    $service = new PointConversionService;

    $toTime = $service->pointsToTime(
        $version, 'LCM', 'M', $discipline->stroke_type_id, $discipline->distance, 'S6', 850
    );

    expect($toTime->errorCode)->toBe('')
        ->and($toTime->value)->not->toBeNull();

    $toPoints = $service->timeToPoints(
        $version, 'LCM', 'M', $discipline->stroke_type_id, $discipline->distance, 'S6', $toTime->value
    );
    expect($toPoints->value)->toBeGreaterThanOrEqual(850);
});

// ── Grenzfälle ────────────────────────────────────────────────────────────────

it('meldet einen Fehlercode statt einer Berechnung ohne passenden Basiswert', function () {
    $version = makeVersion_pfr6();
    $discipline = makeDiscipline_pfr6();
    $service = new PointConversionService;

    $calculation = $service->timeToPoints(
        $version, 'LCM', 'M', $discipline->stroke_type_id, $discipline->distance, 'S6', 3000
    );

    expect($calculation->value)->toBeNull()->and($calculation->errorCode)->not->toBe('');
});

it('behandelt TYPE_NOT_APPLICABLE wie einen fehlenden Basiswert', function () {
    $version = makeVersion_pfr6();
    $category = makeCategory_pfr6();
    $discipline = makeDiscipline_pfr6();
    $sportClass = makeSportClass_pfr6();
    putBaseTime_pfr6($version, $category, $discipline, $sportClass, 0, BaseTime::TYPE_NOT_APPLICABLE);

    $service = new PointConversionService;
    $calculation = $service->timeToPoints(
        $version, 'LCM', 'M', $discipline->stroke_type_id, $discipline->distance, 'S6', 3000
    );

    expect($calculation->value)->toBeNull()->and($calculation->errorCode)->toBe('no_base_time');
});

it('lehnt eine Punktzahl von 0 ab, statt eine Zeit zu berechnen', function () {
    $version = makeVersion_pfr6();
    $category = makeCategory_pfr6();
    $discipline = makeDiscipline_pfr6();
    $sportClass = makeSportClass_pfr6();
    putBaseTime_pfr6($version, $category, $discipline, $sportClass, 3000);

    $service = new PointConversionService;
    $calculation = $service->pointsToTime(
        $version, 'LCM', 'M', $discipline->stroke_type_id, $discipline->distance, 'S6', 0
    );

    expect($calculation->value)->toBeNull()->and($calculation->errorCode)->toBe('invalid_points');
});

// ── Punktetabelle: nur die heute gültige Version ─────────────────────────────────

it('zeigt in der Punktetabelle nur die heute gültige Basiswert-Version', function () {
    $oldVersion = makeVersion_pfr6(['valid_from' => '2000-01-01', 'valid_until' => '2000-12-31']);
    $currentVersion = makeVersion_pfr6(['valid_from' => now()->subMonth()->toDateString(), 'valid_until' => null]);

    $category = makeCategory_pfr6();
    $discipline = makeDiscipline_pfr6();
    $sportClass = makeSportClass_pfr6();

    putBaseTime_pfr6($oldVersion, $category, $discipline, $sportClass, 9999); // 99.99s, alte Version
    putBaseTime_pfr6($currentVersion, $category, $discipline, $sportClass, 3000); // 30.00s, aktuell

    $this->get(route('public.base-times.index', ['locale' => 'de', 'course' => 'LCM']))
        ->assertOk()
        ->assertSee('00:30.00')
        ->assertDontSee('01:39.99');
});

it('zeigt eine Hinweismeldung ohne heute gültige Basiswert-Version', function () {
    makeVersion_pfr6(['valid_from' => '2000-01-01', 'valid_until' => '2000-12-31']);

    $this->get(route('public.base-times.index', ['locale' => 'de']))
        ->assertOk()
        ->assertSee(__('public.base_times.empty', [], 'de'));
});

// ── Zweistufige Kopfzeilen (accessibility.md) ────────────────────────────────────

it('verdrahtet die zweistufigen Kopfzeilen der Punktetabelle mit headers/id', function () {
    $version = makeVersion_pfr6();
    $category = makeCategory_pfr6();
    $discipline = makeDiscipline_pfr6();
    $sportClass = makeSportClass_pfr6();
    putBaseTime_pfr6($version, $category, $discipline, $sportClass, 3000);

    $content = $this->get(route('public.base-times.index', ['locale' => 'de', 'course' => 'LCM']))
        ->assertOk()
        ->getContent();

    expect($content)->toContain('id="gender-FREE-M"')
        ->and($content)->toContain('id="col-FREE-M-'.$discipline->id.'"')
        ->and($content)->toContain('headers="gender-FREE-M"')
        ->and($content)->toContain('id="row-FREE-'.$sportClass->id.'"')
        ->and($content)->toContain('headers="row-FREE-'.$sportClass->id.' col-FREE-M-'.$discipline->id.'"');
});

// ── Mobile Sportklassen-Auswahl zeigt dieselben Werte wie die Matrix ─────────────────

it('zeigt in der mobilen Sportklassen-Einzelansicht denselben Wert wie die Matrix', function () {
    $version = makeVersion_pfr6();
    $category = makeCategory_pfr6();
    $discipline = makeDiscipline_pfr6();
    $sportClass = makeSportClass_pfr6();
    putBaseTime_pfr6($version, $category, $discipline, $sportClass, 3661); // 36.61s

    $content = $this->get(route('public.base-times.index', ['locale' => 'de', 'course' => 'LCM']))
        ->assertOk()
        ->getContent();

    // Einmal in der Matrix-Zelle, einmal in der mobilen dl-Einzelansicht derselben Zahl.
    expect(substr_count($content, '00:36.61'))->toBe(2);
});

// ── Punkterechner: Controller-Endpunkt ────────────────────────────────────────────

it('berechnet über den Punkterechner-Endpunkt Zeit zu Punkten', function () {
    $version = makeVersion_pfr6();
    $category = makeCategory_pfr6();
    $discipline = makeDiscipline_pfr6();
    $sportClass = makeSportClass_pfr6();
    putBaseTime_pfr6($version, $category, $discipline, $sportClass, 3000);

    $this->get(route('public.point-calculator.index', [
        'locale' => 'de',
        'mode' => 'time_to_points',
        'course' => 'LCM',
        'gender' => 'M',
        'discipline_id' => $discipline->id,
        'sport_class' => 'S6',
        'time' => '00:32.00',
    ]))
        ->assertOk()
        ->assertSee(__('public.point_calculator.result.points_heading', [], 'de'));
});

it('berechnet über den Punkterechner-Endpunkt Punkte zu Zeit', function () {
    $version = makeVersion_pfr6();
    $category = makeCategory_pfr6();
    $discipline = makeDiscipline_pfr6();
    $sportClass = makeSportClass_pfr6();
    putBaseTime_pfr6($version, $category, $discipline, $sportClass, 3000);

    $this->get(route('public.point-calculator.index', [
        'locale' => 'de',
        'mode' => 'points_to_time',
        'course' => 'LCM',
        'gender' => 'M',
        'discipline_id' => $discipline->id,
        'sport_class' => 'S6',
        'points' => 900,
    ]))
        ->assertOk()
        ->assertSee(__('public.point_calculator.result.time_heading', [], 'de'));
});

it('zeigt eine übersetzte Fehlermeldung, wenn kein Basiswert vorliegt', function () {
    makeVersion_pfr6();
    makeCategory_pfr6();
    $discipline = makeDiscipline_pfr6();
    makeSportClass_pfr6(); // Kategorie, Bewerb und Sportklasse existieren, nur kein BaseTime-Eintrag dafür

    $this->get(route('public.point-calculator.index', [
        'locale' => 'de',
        'mode' => 'time_to_points',
        'course' => 'LCM',
        'gender' => 'M',
        'discipline_id' => $discipline->id,
        'sport_class' => 'S6',
        'time' => '00:32.00',
    ]))
        ->assertOk()
        ->assertSee(__('public.point_calculator.errors.no_base_time', [], 'de'));
});

it('zeigt ohne Filterparameter das leere Formular ohne Ergebnis oder Fehler', function () {
    makeVersion_pfr6();

    $this->get(route('public.point-calculator.index', ['locale' => 'de']))
        ->assertOk()
        ->assertDontSee(__('public.point_calculator.errors.no_base_time', [], 'de'));
});

// ── WPS-Punkterechner (Rückmeldung: zweiter Rechner mit der offiziellen WPS-Tabelle) ────────────

it('rechnet über WpsPointCalculator::timeForPoints() konsistent zur Vorwärtsrichtung (Rundtrip)', function () {
    $version = version_wps2();
    $parameter = parameter_wps2(['sport_class' => 'S2', 'parameter_c' => 433.181]);
    $calculator = new WpsPointCalculator(new WpsScmConversionService);

    // Referenzwert aus der offiziellen Datei (siehe WpsPointCalculatorTest): 57,00s → 939 Punkte.
    $points = $calculator->pointsForTime(
        5700, $parameter->course, $parameter->gender, $parameter->stroke_type_id,
        $parameter->distance, $parameter->sport_class, $version
    );
    expect($points)->toBe(939);

    $time = $calculator->timeForPoints(
        $points, $parameter->course, $parameter->gender, $parameter->stroke_type_id,
        $parameter->distance, $parameter->sport_class, $version
    );
    expect($time)->not->toBeNull();

    $recalculated = $calculator->pointsForTime(
        $time, $parameter->course, $parameter->gender, $parameter->stroke_type_id,
        $parameter->distance, $parameter->sport_class, $version
    );
    expect($recalculated)->toBeGreaterThanOrEqual(939);
});

it('berechnet über den WPS-Punkterechner-Endpunkt Zeit zu Punkten', function () {
    $parameter = parameter_wps2(['sport_class' => 'S2', 'parameter_c' => 433.181]);
    version_wps2();

    $this->get(route('public.wps-point-calculator.index', [
        'locale' => 'de',
        'mode' => 'time_to_points',
        'gender' => $parameter->gender,
        'discipline_id' => $parameter->stroke_type_id.':'.$parameter->distance,
        'sport_class' => '2',
        'time' => '00:57.00',
    ]))
        ->assertOk()
        ->assertSee('939');
});

it('berechnet über den WPS-Punkterechner-Endpunkt Punkte zu Zeit', function () {
    $parameter = parameter_wps2(['sport_class' => 'S2', 'parameter_c' => 433.181]);
    version_wps2();

    $this->get(route('public.wps-point-calculator.index', [
        'locale' => 'de',
        'mode' => 'points_to_time',
        'gender' => $parameter->gender,
        'discipline_id' => $parameter->stroke_type_id.':'.$parameter->distance,
        'sport_class' => '2',
        'points' => 939,
    ]))
        ->assertOk()
        ->assertSee(__('public.wps_point_calculator.result.time_heading', [], 'de'));
});

it('zeigt einen Fehler statt eine falsche Berechnung bei unbekanntem Bewerb im WPS-Rechner', function () {
    version_wps2();

    $this->get(route('public.wps-point-calculator.index', [
        'locale' => 'de',
        'mode' => 'time_to_points',
        'gender' => 'M',
        'discipline_id' => '999999:50',
        'sport_class' => '2',
        'time' => '00:57.00',
    ]))
        ->assertOk()
        ->assertSee(__('public.wps_point_calculator.errors.no_discipline', [], 'de'));
});

it('nennt die WPS-Punkteversion auf der Rechner-Seite', function () {
    $version = version_wps2();

    $this->get(route('public.wps-point-calculator.index', ['locale' => 'de']))
        ->assertOk()
        ->assertSee($version->display_name, false);
});

// ── Kopfzeile: "Punkte"-Untermenü (Rückmeldung: Kopfzeile wurde zu lang) ─────────────────────────

it('fasst Punktetabelle und beide Rechner in der Desktop-Kopfzeile im Untermenü "Punkte" zusammen', function () {
    $content = $this->get(route('public.records.index', ['locale' => 'de']))
        ->assertOk()
        ->getContent();

    expect($content)->toContain('navDropdown()')
        ->and(substr_count($content, __('public.nav.base_times')))->toBe(2) // Untermenü-Panel + mobiles Panel
        ->and($content)->toContain(route('public.base-times.index', ['locale' => 'de']))
        ->and($content)->toContain(route('public.point-calculator.index', ['locale' => 'de']))
        ->and($content)->toContain(route('public.wps-point-calculator.index', ['locale' => 'de']));
});
