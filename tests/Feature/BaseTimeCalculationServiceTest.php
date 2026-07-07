<?php

use App\Models\BaseTime;
use App\Models\BaseTimeCategory;
use App\Models\BaseTimeDerivationRule;
use App\Models\BaseTimeDiscipline;
use App\Models\BaseTimeSportClass;
use App\Models\BaseTimeVersion;
use App\Models\StrokeType;
use App\Services\BaseTimeCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function makeVersion_bc(): BaseTimeVersion
{
    return BaseTimeVersion::create(['label' => 'Test', 'valid_from' => '2021-01-01', 'valid_until' => null]);
}

function makeCategory_bc(string $code): BaseTimeCategory
{
    return BaseTimeCategory::create(['code' => $code, 'course' => 'LCM', 'gender' => 'M', 'label' => $code]);
}

function makeDiscipline_bc(string $code, int $distance, StrokeType $stroke): BaseTimeDiscipline
{
    return BaseTimeDiscipline::create([
        'code' => $code, 'distance' => $distance, 'relay_count' => 1, 'stroke_type_id' => $stroke->id,
    ]);
}

function makeSportClass_bc(string $code, int $sortOrder = 0): BaseTimeSportClass
{
    return BaseTimeSportClass::create(['code' => $code, 'sort_order' => $sortOrder]);
}

function putBaseTime_bc(
    BaseTimeVersion $version, BaseTimeCategory $category, BaseTimeDiscipline $discipline,
    BaseTimeSportClass $sportClass, int $centiseconds, string $type
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

// ── Ketten-Herleitung innerhalb einer Kategorie ────────────────────────────────

describe('BaseTimeCalculationService: Kette innerhalb einer Kategorie', function () {
    beforeEach(function () {
        $stroke = StrokeType::create(['name_de' => 'Freistil', 'name_en' => 'Freestyle', 'lenex_code' => 'FREE', 'code' => 'FREE']);

        $this->version = makeVersion_bc();
        $this->category = makeCategory_bc('LC_MEN');

        $this->d100 = makeDiscipline_bc('100FR', 100, $stroke);
        $this->d200 = makeDiscipline_bc('200FR', 200, $stroke);
        $this->d400 = makeDiscipline_bc('400FR', 400, $stroke);
        $this->d800 = makeDiscipline_bc('800FR', 800, $stroke);

        $this->s1 = makeSportClass_bc('S1', 1);
        $this->s2 = makeSportClass_bc('S2', 2);

        // Wachstum 100→200 ist für beide Klassen exakt 1.1 (bewusst rund gewählt).
        putBaseTime_bc($this->version, $this->category, $this->d100, $this->s1, 1000, BaseTime::TYPE_MANUAL);
        putBaseTime_bc($this->version, $this->category, $this->d200, $this->s1, 2100, BaseTime::TYPE_MANUAL);
        putBaseTime_bc($this->version, $this->category, $this->d100, $this->s2, 2000, BaseTime::TYPE_MANUAL);
        putBaseTime_bc($this->version, $this->category, $this->d200, $this->s2, 4200, BaseTime::TYPE_MANUAL);

        // 400FR fehlt komplett → wird aus 200FR mit dem 100→200-Ratio hergeleitet (Override).
        putBaseTime_bc($this->version, $this->category, $this->d400, $this->s1, 0, BaseTime::TYPE_CALCULATED);
        putBaseTime_bc($this->version, $this->category, $this->d400, $this->s2, 0, BaseTime::TYPE_CALCULATED);
        BaseTimeDerivationRule::create([
            'base_time_category_id' => $this->category->id,
            'shorter_discipline_id' => $this->d200->id,
            'longer_discipline_id' => $this->d400->id,
            'ratio_shorter_discipline_id' => $this->d100->id,
            'ratio_longer_discipline_id' => $this->d200->id,
        ]);

        // 800FR fehlt komplett → wird aus 400FR mit dem 200→400-Ratio hergeleitet.
        // 200→400 ist zum Startzeitpunkt unbekannt (400FR ist ja selbst erst berechnet) —
        // das testet genau die Ketten-Auflösung über mehrere Iterationen.
        putBaseTime_bc($this->version, $this->category, $this->d800, $this->s1, 0, BaseTime::TYPE_CALCULATED);
        putBaseTime_bc($this->version, $this->category, $this->d800, $this->s2, 0, BaseTime::TYPE_CALCULATED);
        BaseTimeDerivationRule::create([
            'base_time_category_id' => $this->category->id,
            'shorter_discipline_id' => $this->d400->id,
            'longer_discipline_id' => $this->d800->id,
            'ratio_shorter_discipline_id' => $this->d200->id,
            'ratio_longer_discipline_id' => $this->d400->id,
        ]);

        $this->service = new BaseTimeCalculationService;
    })->group('base-time-calc');

    it('löst die Kette 200→400→800 über mehrere Iterationen korrekt auf', function () {
        $this->service->recalculateVersion($this->version);

        // Wachstum 100→200 = 1.1 für beide Klassen → 400FR = 200FR * 2.1
        expect(BaseTime::where('base_time_discipline_id', $this->d400->id)->where('base_time_sport_class_id', $this->s1->id)->value('value_centiseconds'))
            ->toBe((int) round(2100 * 2.1))
            ->and(BaseTime::where('base_time_discipline_id', $this->d400->id)->where('base_time_sport_class_id', $this->s2->id)->value('value_centiseconds'))
            ->toBe((int) round(4200 * 2.1));

        // Nach dem Auffüllen von 400FR ist das 200→400-Wachstum für beide Klassen ebenfalls 1.1
        // (proportionale Skalierung) → 800FR = 400FR * 2.1
        $expected400S1 = (int) round(2100 * 2.1);
        $expected400S2 = (int) round(4200 * 2.1);

        expect(BaseTime::where('base_time_discipline_id', $this->d800->id)->where('base_time_sport_class_id', $this->s1->id)->value('value_centiseconds'))
            ->toBe((int) round($expected400S1 * 2.1))
            ->and(BaseTime::where('base_time_discipline_id', $this->d800->id)->where('base_time_sport_class_id', $this->s2->id)->value('value_centiseconds'))
            ->toBe((int) round($expected400S2 * 2.1));
    })->group('base-time-calc');

    it('lässt MANUAL-Werte unangetastet', function () {
        $this->service->recalculateVersion($this->version);

        expect(BaseTime::where('base_time_discipline_id', $this->d100->id)->where('base_time_sport_class_id', $this->s1->id)->value('value_centiseconds'))
            ->toBe(1000);
    })->group('base-time-calc');

    it('kaskadiert eine Änderung des MANUAL-Werts über die gesamte Kette', function () {
        $this->service->recalculateVersion($this->version);

        // S1s 100FR-Wert ändert sich → das gemittelte 100→200-Wachstum ändert sich für BEIDE Klassen,
        // da der Ratio-Durchschnitt über alle Klassen der Kategorie gebildet wird.
        BaseTime::where('base_time_discipline_id', $this->d100->id)
            ->where('base_time_sport_class_id', $this->s1->id)
            ->update(['value_centiseconds' => 1100]);

        $this->service->recalculateCategory($this->version, $this->category);

        $ratioS1 = (2100 - 1100) / 1100;
        $ratioS2 = (4200 - 2000) / 2000;
        $avgRatio = ($ratioS1 + $ratioS2) / 2;

        $expected400S2 = (int) round(4200 * (1 + $avgRatio));

        expect(BaseTime::where('base_time_discipline_id', $this->d400->id)->where('base_time_sport_class_id', $this->s2->id)->value('value_centiseconds'))
            ->toBe($expected400S2);
    })->group('base-time-calc');

    it('meldet nicht auflösbare CALCULATED-Zeilen statt sie stillschweigend zu ignorieren', function () {
        $strokeBack = StrokeType::create(['name_de' => 'Rücken', 'name_en' => 'Backstroke', 'lenex_code' => 'BACK', 'code' => 'BACK']);
        $orphanDiscipline = makeDiscipline_bc('200BK', 200, $strokeBack);
        putBaseTime_bc($this->version, $this->category, $orphanDiscipline, $this->s1, 0, BaseTime::TYPE_CALCULATED);
        // Bewusst keine Regel für 200BK definiert → kann nicht hergeleitet werden.

        $summary = $this->service->recalculateVersion($this->version);

        expect($summary[$this->category->id]['unresolved'])->toContainEqual([
            'discipline_id' => $orphanDiscipline->id,
            'sport_class_id' => $this->s1->id,
        ]);
    })->group('base-time-calc');
});

// ── Cross-Kategorie-Referenz ────────────────────────────────────────────────────

describe('BaseTimeCalculationService: Cross-Kategorie-Referenz', function () {
    beforeEach(function () {
        $stroke = StrokeType::create(['name_de' => 'Freistil', 'name_en' => 'Freestyle', 'lenex_code' => 'FREE', 'code' => 'FREE']);

        $this->version = makeVersion_bc();
        $this->men = makeCategory_bc('LC_MEN');
        $this->mixed = makeCategory_bc('LC_MIXED');

        $this->d100 = makeDiscipline_bc('100FR', 100, $stroke);
        $this->d200 = makeDiscipline_bc('200FR', 200, $stroke);
        $this->s1 = makeSportClass_bc('S1', 1);

        // LC Men: vollständige MANUAL-Daten, liefert das Ratio für LC Mixed.
        putBaseTime_bc($this->version, $this->men, $this->d100, $this->s1, 1000, BaseTime::TYPE_MANUAL);
        putBaseTime_bc($this->version, $this->men, $this->d200, $this->s1, 2100, BaseTime::TYPE_MANUAL);

        // LC Mixed: kennt nur 100FR, 200FR muss über das LC-Men-Ratio hergeleitet werden
        // (analog zu LC Mixed → LC Men im echten Datensatz).
        putBaseTime_bc($this->version, $this->mixed, $this->d100, $this->s1, 1200, BaseTime::TYPE_MANUAL);
        putBaseTime_bc($this->version, $this->mixed, $this->d200, $this->s1, 0, BaseTime::TYPE_CALCULATED);
        BaseTimeDerivationRule::create([
            'base_time_category_id' => $this->mixed->id,
            'shorter_discipline_id' => $this->d100->id,
            'longer_discipline_id' => $this->d200->id,
            'ratio_reference_category_id' => $this->men->id,
        ]);

        $this->service = new BaseTimeCalculationService;
    })->group('base-time-calc');

    it('berechnet die abhängige Kategorie unabhängig von der übergebenen Reihenfolge korrekt', function () {
        // Absichtlich "falsche" Reihenfolge über recalculateVersion (DB-Reihenfolge ist nicht garantiert) —
        // die interne topologische Sortierung muss das selbst korrigieren.
        $this->service->recalculateVersion($this->version);

        // Ratio bei LC Men: (2100-1000)/1000 = 1.1 → LC Mixed 200FR = 1200 * 2.1
        expect(BaseTime::where('base_time_category_id', $this->mixed->id)->where('base_time_discipline_id', $this->d200->id)->value('value_centiseconds'))
            ->toBe((int) round(1200 * 2.1));
    })->group('base-time-calc');

    it('recalculateCategory auf LC Men kaskadiert automatisch zu LC Mixed', function () {
        BaseTime::where('base_time_category_id', $this->men->id)
            ->where('base_time_discipline_id', $this->d100->id)
            ->update(['value_centiseconds' => 1050]);

        $summary = $this->service->recalculateCategory($this->version, $this->men);

        expect($summary)->toHaveKey($this->mixed->id);

        $newRatio = (2100 - 1050) / 1050;
        expect(BaseTime::where('base_time_category_id', $this->mixed->id)->where('base_time_discipline_id', $this->d200->id)->value('value_centiseconds'))
            ->toBe((int) round(1200 * (1 + $newRatio)));
    })->group('base-time-calc');
});
