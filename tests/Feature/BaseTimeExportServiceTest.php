<?php

use App\Models\BaseTime;
use App\Models\BaseTimeCategory;
use App\Models\BaseTimeDiscipline;
use App\Models\BaseTimeSportClass;
use App\Models\BaseTimeVersion;
use App\Models\StrokeType;
use App\Services\BaseTimeExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;

uses(RefreshDatabase::class);

function makeExportFixture_be(): array
{
    $stroke = StrokeType::create(['name_de' => 'Freistil', 'name_en' => 'Freestyle', 'lenex_code' => 'FREE', 'code' => 'FREE']);

    $version = BaseTimeVersion::create(['label' => '2021–2026', 'valid_from' => '2021-01-01', 'valid_until' => '2026-12-31']);
    $category = BaseTimeCategory::create(['code' => 'LC_MEN', 'course' => 'LCM', 'gender' => 'M', 'label' => 'LC Men']);

    $d100 = BaseTimeDiscipline::create(['code' => '100FR', 'distance' => 100, 'relay_count' => 1, 'stroke_type_id' => $stroke->id]);
    $d200 = BaseTimeDiscipline::create(['code' => '200FR', 'distance' => 200, 'relay_count' => 1, 'stroke_type_id' => $stroke->id]);

    $s1 = BaseTimeSportClass::create(['code' => 'S1', 'sort_order' => 1]);
    $s20 = BaseTimeSportClass::create(['code' => 'S20', 'sort_order' => 2]);

    BaseTime::create([
        'base_time_version_id' => $version->id, 'base_time_category_id' => $category->id,
        'base_time_discipline_id' => $d100->id, 'base_time_sport_class_id' => $s1->id,
        'value_centiseconds' => 6234, 'value_type' => BaseTime::TYPE_MANUAL,
    ]);
    BaseTime::create([
        'base_time_version_id' => $version->id, 'base_time_category_id' => $category->id,
        'base_time_discipline_id' => $d200->id, 'base_time_sport_class_id' => $s1->id,
        'value_centiseconds' => 13091, 'value_type' => BaseTime::TYPE_CALCULATED,
    ]);
    BaseTime::create([
        'base_time_version_id' => $version->id, 'base_time_category_id' => $category->id,
        'base_time_discipline_id' => $d200->id, 'base_time_sport_class_id' => $s20->id,
        'value_centiseconds' => 0, 'value_type' => BaseTime::TYPE_NOT_APPLICABLE,
    ]);

    return compact('version', 'category', 'd100', 'd200', 's1', 's20');
}

describe('BaseTimeExportService', function () {
    afterEach(function () {
        if (isset($this->exportedPath) && file_exists($this->exportedPath)) {
            unlink($this->exportedPath);
        }
    });

    it('exportiert ein Arbeitsblatt je Kategorie mit dem Kategorie-Label als Titel', function () {
        ['version' => $version] = makeExportFixture_be();

        $this->exportedPath = (new BaseTimeExportService)->export($version);
        $sheetNames = IOFactory::load($this->exportedPath)->getSheetNames();

        expect($sheetNames)->toBe(['LC Men']);
    })->group('base-time-export');

    it('übersetzt die Sportklasse S20 beim Export zurück zu R20', function () {
        ['version' => $version] = makeExportFixture_be();

        $this->exportedPath = (new BaseTimeExportService)->export($version);
        $sheet = IOFactory::load($this->exportedPath)->getSheetByName('LC Men');

        expect($sheet->getCell('B1')->getValue())->toBe('S1')
            ->and($sheet->getCell('C1')->getValue())->toBe('R20');
    })->group('base-time-export');

    it('schreibt MANUAL- und NOT_APPLICABLE-Werte als reine Zahl ohne Formel', function () {
        ['version' => $version] = makeExportFixture_be();

        $this->exportedPath = (new BaseTimeExportService)->export($version);
        $sheet = IOFactory::load($this->exportedPath)->getSheetByName('LC Men');

        expect($sheet->getCell('A2')->getValue())->toBe('100FR')
            ->and((float) $sheet->getCell('B2')->getValue())->toEqualWithDelta(62.34, 0.001)
            ->and((float) $sheet->getCell('C3')->getValue())->toBe(0.0); // NOT_APPLICABLE → 0
    })->group('base-time-export');

    it('färbt CALCULATED-Werte orange, MANUAL-Werte bleiben in der Standardfarbe', function () {
        ['version' => $version] = makeExportFixture_be();

        $this->exportedPath = (new BaseTimeExportService)->export($version);
        $sheet = IOFactory::load($this->exportedPath)->getSheetByName('LC Men');

        // 200FR/S1 ist CALCULATED (13091cs = 130.91s) → orange.
        // 100FR/S1 ist MANUAL → keine spezielle Schriftfarbe gesetzt.
        expect((float) $sheet->getCell('B3')->getValue())->toEqualWithDelta(130.91, 0.001)
            ->and($sheet->getStyle('B3')->getFont()->getColor()->getRGB())->toBe('ED7D31')
            ->and($sheet->getStyle('B2')->getFont()->getColor()->getRGB())->not->toBe('ED7D31');
    })->group('base-time-export');
});
