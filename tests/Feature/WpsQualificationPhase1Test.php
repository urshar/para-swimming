<?php

use App\Models\BaseTimeSportClass;
use App\Models\Championship;
use App\Models\ChampionshipStandard;
use App\Models\StrokeType;
use App\Services\ChampionshipStandardService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class)->group('wps-qual-p1');

// ── Helper (Suffix _wq1 gegen Namenskollisionen) ─────────────────────────────

function stroke_wq1(string $code): StrokeType
{
    return StrokeType::firstOrCreate(
        ['code' => $code],
        [
            'lenex_code' => $code,
            'name_de' => $code === 'FREE' ? 'Freistil' : 'Rücken',
            'name_en' => $code === 'FREE' ? 'Freestyle' : 'Backstroke',
        ]
    );
}

function sportClass_wq1(string $code, int $sortOrder): BaseTimeSportClass
{
    return BaseTimeSportClass::query()->create(['code' => $code, 'sort_order' => $sortOrder]);
}

function championship_wq1(): Championship
{
    return app(ChampionshipStandardService::class)->createChampionship([
        'name' => 'World Para Swimming European Championships 2026',
        'short_name' => 'EM 2026',
        'type' => Championship::TYPE_EC,
        'year' => 2026,
        'qualification_start' => '2025-01-01',
        'qualification_end' => '2026-07-06',
    ]);
}

/** Norm mit MQS 1:13.19 = 7319 Hundertstel (Beispiel aus Spec §7.3). */
function standard_wq1(Championship $championship, ?int $mqs, array $ueberschreibungen): ChampionshipStandard
{
    return ChampionshipStandard::query()->create(array_merge([
        'championship_id' => $championship->getKey(),
        'stroke_type_id' => stroke_wq1('FREE')->id,
        'distance' => 100,
        'gender' => 'M',
        'sport_class' => 'S7',
        'mqs_centiseconds' => $mqs,
    ], $ueberschreibungen));
}

beforeEach(function () {
    $this->service = app(ChampionshipStandardService::class);
    sportClass_wq1('S7', 7);
});

// ── Meisterschaft ─────────────────────────────────────────────────────────────

it('legt eine Meisterschaft mit Standardwerten an', function () {
    $championship = championship_wq1();

    expect($championship->course)->toBe(Championship::COURSE_LCM)
        ->and($championship->is_active)->toBeTrue()
        ->and($championship->isLongCourse())->toBeTrue()
        ->and($championship->display_name)->toBe('EM 2026');
});

it('verwendet ausschliesslich bekannte Typ- und Bahnlängenwerte', function () {
    $championship = championship_wq1();

    expect(Championship::TYPES)->toContain($championship->type)
        ->and(Championship::COURSES)->toContain($championship->course)
        ->and(Championship::TYPES)->toHaveCount(4)
        ->and(Championship::COURSES)->toHaveCount(2);
});

it('fällt beim Anzeigenamen auf den vollen Namen zurück wenn kein Kurzname gesetzt ist', function () {
    $championship = championship_wq1();
    $this->service->updateChampionship($championship, ['short_name' => null]);

    expect($championship->fresh()->display_name)
        ->toBe('World Para Swimming European Championships 2026');
});

it('aktualisiert eine Meisterschaft über den Service', function () {
    $championship = championship_wq1();

    $this->service->updateChampionship($championship, [
        'short_name' => 'EC 2026',
        'qualification_end' => '2026-07-10',
    ]);

    $frisch = Championship::query()->findOrFail($championship->getKey());

    expect($frisch->short_name)->toBe('EC 2026')
        ->and($frisch->isWithinQualificationPeriod('2026-07-08'))->toBeTrue();
});

it('erkennt Daten innerhalb des Qualifikationszeitraums einschliesslich der Grenztage', function () {
    $championship = championship_wq1();

    expect($championship->isWithinQualificationPeriod('2025-01-01'))->toBeTrue()
        ->and($championship->isWithinQualificationPeriod('2026-07-06'))->toBeTrue()
        ->and($championship->isWithinQualificationPeriod('2025-08-15'))->toBeTrue()
        ->and($championship->isWithinQualificationPeriod('2024-12-31'))->toBeFalse()
        ->and($championship->isWithinQualificationPeriod('2026-07-07'))->toBeFalse();
});

it('liefert die Zeitraumgrenzen als Datumsstrings für whereBetween', function () {
    expect(championship_wq1()->qualificationPeriodBounds())
        ->toBe(['2025-01-01', '2026-07-06']);
});

// ── Normen: Anlegen, Lesen, Integrität ──────────────────────────────────────

it('speichert und liest eine Norm mit beiden Norm-ebenen', function () {
    $championship = championship_wq1();
    $standard = standard_wq1($championship, 7319, [
        'met_centiseconds' => 7800,
        'obsv_percent' => 2.0,
        'obsv_centiseconds' => 7172,
    ]);

    $geladen = ChampionshipStandard::query()->find($standard->getKey());

    expect($geladen->mqs_centiseconds)->toBe(7319)
        ->and($geladen->met_centiseconds)->toBe(7800)
        ->and($geladen->obsv_percent)->toBe(2.0)
        ->and($geladen->obsv_centiseconds)->toBe(7172)
        ->and($geladen->obsv_is_manual)->toBeFalse()
        ->and($geladen->championship->getKey())->toBe($championship->getKey());
});

it('erlaubt eine Norm ohne MQS wenn nur eine MET veröffentlicht wurde', function () {
    $standard = standard_wq1(championship_wq1(), null, ['met_centiseconds' => 7800]);

    expect($standard->mqs_centiseconds)->toBeNull()
        ->and($standard->met_centiseconds)->toBe(7800);
});

it('verhindert doppelte Normen für dieselbe Kombination', function () {
    $championship = championship_wq1();
    $stroke = stroke_wq1('FREE');

    $daten = [
        'championship_id' => $championship->getKey(),
        'stroke_type_id' => $stroke->id,
        'distance' => 100,
        'gender' => 'M',
        'sport_class' => 'S7',
        'mqs_centiseconds' => 7319,
    ];

    ChampionshipStandard::query()->create($daten);

    expect(fn () => ChampionshipStandard::query()->create($daten))->toThrow(QueryException::class);
});

it('löscht die Normen mit der Meisterschaft', function () {
    $championship = championship_wq1();
    standard_wq1($championship, 7319, []);

    $this->service->deleteChampionship($championship);

    expect(ChampionshipStandard::query()->count())->toBe(0)
        ->and(Championship::query()->count())->toBe(0);
});

it('formatiert die Zeiten beider Norm-ebenen', function () {
    $standard = standard_wq1(championship_wq1(), 7319, [
        'met_centiseconds' => 7800,
        'obsv_centiseconds' => 7172,
    ]);

    expect($standard->formatted_mqs)->toBe('01:13.19')
        ->and($standard->formatted_met)->toBe('01:18.00')
        ->and($standard->formatted_obsv)->toBe('01:11.72');
});

it('gibt bei fehlenden Zeiten null statt einer formatierten Null zurück', function () {
    $standard = standard_wq1(championship_wq1(), null, []);

    expect($standard->formatted_mqs)->toBeNull()
        ->and($standard->formatted_met)->toBeNull()
        ->and($standard->formatted_obsv)->toBeNull();
});

// ── [Q3]: null ist nicht 0 ───────────────────────────────────────────────────

it('unterscheidet einen offenen Prozentsatz von einem bewusst auf 0 gesetzten', function () {
    $championship = championship_wq1();

    $offen = standard_wq1($championship, 7319, ['obsv_percent' => null]);
    $uebernommen = standard_wq1($championship, 7319, [
        'stroke_type_id' => stroke_wq1('BACK')->id,
        'obsv_percent' => 0,
    ]);

    expect($offen->hasObsvPercent())->toBeFalse()
        ->and($offen->isObsvOpen())->toBeTrue()
        ->and($uebernommen->hasObsvPercent())->toBeTrue()
        ->and($uebernommen->isObsvOpen())->toBeFalse()
        ->and($this->service->openStandards($championship)->count())->toBe(1);
});

it('übernimmt bei einem Prozentsatz von 0 die MQS unverändert', function () {
    $standard = standard_wq1(championship_wq1(), 7319, []);

    $this->service->applyPercent($standard, 0.0);

    expect($standard->fresh()->obsv_centiseconds)->toBe(7319)
        ->and($standard->fresh()->hasObsvPercent())->toBeTrue();
});

// ── Prozentsatz und Zeit (§5.3) ──────────────────────────────────────────────

it('errechnet die OeBSV-Zeit aus MQS und Prozentsatz mit floor', function () {
    // 7319 × 0,98 = 7172,62 → floor → 7172
    expect($this->service->calculateObsvTime(7319, 2.0))->toBe(7172)
        // 7319 × 0,95 = 6953,05 → floor → 6953
        ->and($this->service->calculateObsvTime(7319, 5.0))->toBe(6953);
});

it('setzt beim Anwenden eines Prozentsatzes das Handzeichen zurück', function () {
    $standard = standard_wq1(championship_wq1(), 7319, [
        'obsv_centiseconds' => 7000,
        'obsv_is_manual' => true,
    ]);

    $this->service->applyPercent($standard, 2.0);

    expect($standard->fresh()->obsv_centiseconds)->toBe(7172)
        ->and($standard->fresh()->obsv_is_manual)->toBeFalse();
});

it('speichert den Prozentsatz auch ohne MQS und lässt die Zeit dabei leer', function () {
    $standard = standard_wq1(championship_wq1(), null, []);

    $this->service->applyPercent($standard, 2.0);

    expect($standard->fresh()->obsv_percent)->toBe(2.0)
        ->and($standard->fresh()->obsv_centiseconds)->toBeNull();
});

it('setzt die Zeile mit einem Prozentsatz von null wieder auf offen', function () {
    $standard = standard_wq1(championship_wq1(), 7319, [
        'obsv_percent' => 2.0,
        'obsv_centiseconds' => 7172,
    ]);

    $this->service->applyPercent($standard, null);

    expect($standard->fresh()->isObsvOpen())->toBeTrue()
        ->and($standard->fresh()->obsv_centiseconds)->toBeNull();
});

it('behält den Prozentsatz zur Information wenn die Zeit von Hand gesetzt wird', function () {
    $standard = standard_wq1(championship_wq1(), 7319, [
        'obsv_percent' => 2.0,
        'obsv_centiseconds' => 7172,
    ]);

    $this->service->setObsvTimeManually($standard, 7000);

    expect($standard->fresh()->obsv_centiseconds)->toBe(7000)
        ->and($standard->fresh()->obsv_is_manual)->toBeTrue()
        ->and($standard->fresh()->obsv_percent)->toBe(2.0)
        ->and($standard->fresh()->effectiveObsvTime())->toBe(7000);
});

it('fällt bei fehlender OeBSV-Zeit nicht auf die MQS zurück', function () {
    $standard = standard_wq1(championship_wq1(), 7319, []);

    expect($standard->effectiveObsvTime())->toBeNull();
});

// ── Massenaktion ─────────────────────────────────────────────────────────────

it('wendet den Prozentsatz nur auf offene Zeilen an', function () {
    $championship = championship_wq1();
    $free = stroke_wq1('FREE');
    $back = stroke_wq1('BACK');

    $offen = ChampionshipStandard::query()->create([
        'championship_id' => $championship->getKey(),
        'stroke_type_id' => $free->id,
        'distance' => 100,
        'gender' => 'M',
        'sport_class' => 'S7',
        'mqs_centiseconds' => 7319,
    ]);

    $vonHand = ChampionshipStandard::query()->create([
        'championship_id' => $championship->getKey(),
        'stroke_type_id' => $back->id,
        'distance' => 100,
        'gender' => 'M',
        'sport_class' => 'S7',
        'mqs_centiseconds' => 8000,
        'obsv_percent' => 5.0,
        'obsv_centiseconds' => 7500,
        'obsv_is_manual' => true,
    ]);

    $uebernommen = ChampionshipStandard::query()->create([
        'championship_id' => $championship->getKey(),
        'stroke_type_id' => $free->id,
        'distance' => 50,
        'gender' => 'F',
        'sport_class' => 'S7',
        'mqs_centiseconds' => 3500,
        'obsv_percent' => 0,
        'obsv_centiseconds' => 3500,
    ]);

    $geaendert = $this->service->applyPercentToOpenRows($championship, 2.0);

    expect($geaendert)->toBe(1)
        ->and($offen->fresh()->obsv_centiseconds)->toBe(7172)
        ->and($offen->fresh()->obsv_percent)->toBe(2.0)
        // von Hand gesetzt — unangetastet
        ->and($vonHand->fresh()->obsv_centiseconds)->toBe(7500)
        ->and($vonHand->fresh()->obsv_is_manual)->toBeTrue()
        // bewusst auf 0 gesetzt — ebenfalls unangetastet
        ->and($uebernommen->fresh()->obsv_percent)->toBe(0.0)
        ->and($uebernommen->fresh()->obsv_centiseconds)->toBe(3500)
        ->and($this->service->openStandards($championship)->count())->toBe(0);
});

// ── upsertStandard ───────────────────────────────────────────────────────────

it('legt eine Norm über den Service an und aktualisiert sie beim zweiten Aufruf', function () {
    $championship = championship_wq1();
    $strokeId = stroke_wq1('FREE')->id;

    $this->service->upsertStandard($championship, $strokeId, 100, 'm', 's7', [
        'mqs_centiseconds' => 7319,
    ]);
    $this->service->upsertStandard($championship, $strokeId, 100, 'M', 'S7', [
        'mqs_centiseconds' => 7300,
    ]);

    $standard = ChampionshipStandard::query()->sole();

    expect(ChampionshipStandard::query()->count())->toBe(1)
        ->and($standard->mqs_centiseconds)->toBe(7300)
        ->and($standard->gender)->toBe('M')
        ->and($standard->sport_class)->toBe('S7');
});

it('lässt die OeBSV-Werte unberührt wenn nur MQS und MET gesetzt werden', function () {
    $championship = championship_wq1();
    $strokeId = stroke_wq1('FREE')->id;

    $this->service->upsertStandard($championship, $strokeId, 100, 'M', 'S7', [
        'mqs_centiseconds' => 7319,
        'obsv_percent' => 2.0,
        'obsv_centiseconds' => 7172,
        'obsv_is_manual' => true,
    ]);

    $this->service->upsertStandard($championship, $strokeId, 100, 'M', 'S7', [
        'mqs_centiseconds' => 7280,
        'met_centiseconds' => 7800,
    ]);

    $standard = ChampionshipStandard::query()->sole();

    expect($standard->mqs_centiseconds)->toBe(7280)
        ->and($standard->met_centiseconds)->toBe(7800)
        ->and($standard->obsv_percent)->toBe(2.0)
        ->and($standard->obsv_centiseconds)->toBe(7172)
        ->and($standard->obsv_is_manual)->toBeTrue();
});

it('weist ein ungültiges Geschlecht zurück', function () {
    $championship = championship_wq1();
    $strokeId = stroke_wq1('FREE')->id;

    expect(fn () => $this->service->upsertStandard($championship, $strokeId, 100, 'X', 'S7', []))
        ->toThrow(ValidationException::class);
});

it('weist eine unbekannte Sportklasse zurück', function () {
    $championship = championship_wq1();
    $strokeId = stroke_wq1('FREE')->id;

    expect(fn () => $this->service->upsertStandard($championship, $strokeId, 100, 'M', 'S99', []))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->service->upsertStandard($championship, $strokeId, 100, 'M', 'X7', []))
        ->toThrow(ValidationException::class);
});

it('löscht eine einzelne Norm ohne die Meisterschaft zu berühren', function () {
    $championship = championship_wq1();
    $standard = standard_wq1($championship, 7319, []);

    $this->service->deleteStandard($standard);

    expect(ChampionshipStandard::query()->count())->toBe(0)
        ->and(Championship::query()->count())->toBe(1);
});
