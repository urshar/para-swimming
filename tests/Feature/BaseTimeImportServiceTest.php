<?php

use App\Models\BaseTime;
use App\Models\BaseTimeCategory;
use App\Models\BaseTimeDerivationRule;
use App\Models\BaseTimeDiscipline;
use App\Models\BaseTimeSportClass;
use App\Models\BaseTimeVersion;
use App\Models\StrokeType;
use App\Services\BaseTimeImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

/**
 * Baut eine minimale Test-Excel-Datei mit zwei Arbeitsblättern ("LC Men", "LC Women"),
 * die die drei relevanten Zell-Muster abdeckt:
 *   - MANUAL-Wert (schwarz)
 *   - NOT_APPLICABLE (0,0)
 *   - CALCULATED mit eigenem Bewerbs-Paar
 *   - CALCULATED mit abweichendem Ratio-Bewerbs-Paar (gleiches Blatt, wie 800FR/1500FR im Original)
 *   - CALCULATED mit cross-sheet Ratio-Referenz (wie LC Mixed → LC Men im Original)
 *   - Sportklassen-Alias R20 → S20
 */
function makeBaseTimeWorkbook_bt(): string
{
    $spreadsheet = new Spreadsheet;

    $men = $spreadsheet->getActiveSheet();
    $men->setTitle('LC Men');
    $men->fromArray([null, 'S15', 'S14', 'R20']);
    // Explizite setCellValue()-Aufrufe statt fromArray(): fromArray() vergleicht Werte standardmäßig
    // per loser Gleichheit gegen $nullValue (Default: null) und würde eine literale 0 (0 == null ist
    // in PHP true!) fälschlich als "leer" behandeln und gar nicht erst schreiben.
    $men->setCellValue('A2', '100FR');
    $men->setCellValue('B2', 60.5);
    $men->setCellValue('C2', 65.0);
    $men->setCellValue('D2', 0);
    $men->setCellValue('A3', '200FR');
    $men->setCellValue('B3', '=B2*(1+$B$6)');
    $men->setCellValue('C3', 0);
    $men->setCellValue('D3', 0);
    $men->setCellValue('A4', '400FR');
    $men->setCellValue('B4', '=B3*(1+$B$6)');
    // Zeile 5 bleibt leer → Ende der Haupttabelle
    $men->setCellValue('A6', '100FR to 200FR');
    $men->setCellValue('B6', 0.05);

    $women = $spreadsheet->createSheet();
    $women->setTitle('LC Women');
    $women->fromArray([null, 'S15']);
    $women->fromArray(['100FR', 70.0], startCell: 'A2');
    $women->setCellValue('A3', '200FR');
    $women->setCellValue('B3', "=B2*(1+'LC Men'!\$B\$6)");

    $path = tempnam(sys_get_temp_dir(), 'basetime_test_').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return $path;
}

function makeFreeStrokeType_bt(): StrokeType
{
    return StrokeType::create([
        'name_de' => 'Freistil',
        'name_en' => 'Freestyle',
        'lenex_code' => 'FREE',
        'code' => 'FREE',
    ]);
}

// ── parse() ───────────────────────────────────────────────────────────────────

describe('BaseTimeImportService::parse', function () {
    beforeEach(function () {
        makeFreeStrokeType_bt();
        $this->path = makeBaseTimeWorkbook_bt();
        $this->service = new BaseTimeImportService;
    })->group('base-time-import');

    it('erkennt fehlende Arbeitsblätter als Hinweis', function () {
        $parsed = $this->service->parse($this->path);

        expect($parsed['warnings'])->toContain(
            'Arbeitsblatt "SC Men" wurde in der Datei nicht gefunden — übersprungen.'
        );
    })->group('base-time-import');

    it('erkennt Kategorien aus den Arbeitsblatt-Namen', function () {
        $parsed = $this->service->parse($this->path);

        expect($parsed['categories'])->toHaveKey('LC_MEN')
            ->and($parsed['categories']['LC_MEN'])->toMatchArray(['course' => 'LCM', 'gender' => 'M'])
            ->and($parsed['categories']['LC_WOMEN'])->toMatchArray(['course' => 'LCM', 'gender' => 'F']);
    })->group('base-time-import');

    it('parst Bewerbs-Codes zu Distanz/Staffel/Schwimmart', function () {
        $parsed = $this->service->parse($this->path);

        expect($parsed['disciplines']['100FR'])->toMatchArray([
            'distance' => 100, 'relay_count' => 1, 'stroke_lenex_code' => 'FREE',
        ]);
    })->group('base-time-import');

    it('mappt die Sportklasse R20 auf den Alias S20', function () {
        $parsed = $this->service->parse($this->path);

        expect($parsed['sportClasses'])->toHaveKey('S20')
            ->and($parsed['sportClasses'])->not->toHaveKey('R20');
    })->group('base-time-import');

    it('erkennt MANUAL- und NOT_APPLICABLE-Zellen korrekt', function () {
        $parsed = $this->service->parse($this->path);

        $manual = collect($parsed['cells'])->firstWhere(
            fn ($c) => $c['discipline_code'] === '100FR' && $c['sport_class_code'] === 'S15'
        );
        expect($manual['value_type'])->toBe(BaseTime::TYPE_MANUAL)
            ->and($manual['value_centiseconds'])->toBe(6050);

        $notApplicable = collect($parsed['cells'])->firstWhere(
            fn ($c) => $c['discipline_code'] === '200FR' && $c['sport_class_code'] === 'S14'
        );
        expect($notApplicable['value_type'])->toBe(BaseTime::TYPE_NOT_APPLICABLE)
            ->and($notApplicable['value_centiseconds'])->toBe(0);
    })->group('base-time-import');

    it('erkennt eine CALCULATED-Zelle mit eigenem Bewerbs-Paar', function () {
        $parsed = $this->service->parse($this->path);

        $cell = collect($parsed['cells'])->first(
            fn ($c) => $c['discipline_code'] === '200FR' && $c['sport_class_code'] === 'S15'
                && $c['category_code'] === 'LC_MEN'
        );

        expect($cell['value_type'])->toBe(BaseTime::TYPE_CALCULATED)
            ->and($cell['shorter_code'])->toBe('100FR')
            ->and($cell['longer_code'])->toBe('200FR')
            ->and($cell['ratio_category_code'])->toBeNull()
            ->and($cell['ratio_shorter_code'])->toBeNull()
            ->and($cell['ratio_longer_code'])->toBeNull()
            ->and($cell['value_centiseconds'])->toBe((int) round(60.5 * 1.05 * 100));
    })->group('base-time-import');

    it('erkennt eine CALCULATED-Zelle, die das Ratio-Paar einer anderen Bewerbs-Kombination wiederverwendet', function () {
        $parsed = $this->service->parse($this->path);

        $cell = collect($parsed['cells'])->first(
            fn ($c) => $c['discipline_code'] === '400FR' && $c['sport_class_code'] === 'S15'
        );

        expect($cell['value_type'])->toBe(BaseTime::TYPE_CALCULATED)
            ->and($cell['shorter_code'])->toBe('200FR')
            ->and($cell['longer_code'])->toBe('400FR')
            ->and($cell['ratio_category_code'])->toBeNull() // gleiches Blatt
            ->and($cell['ratio_shorter_code'])->toBe('100FR')
            ->and($cell['ratio_longer_code'])->toBe('200FR');
    })->group('base-time-import');

    it('erkennt eine CALCULATED-Zelle mit cross-sheet Ratio-Referenz', function () {
        $parsed = $this->service->parse($this->path);

        $cell = collect($parsed['cells'])->first(
            fn ($c) => $c['discipline_code'] === '200FR' && $c['sport_class_code'] === 'S15'
                && $c['category_code'] === 'LC_WOMEN'
        );

        expect($cell['value_type'])->toBe(BaseTime::TYPE_CALCULATED)
            ->and($cell['shorter_code'])->toBe('100FR')
            ->and($cell['longer_code'])->toBe('200FR')
            ->and($cell['ratio_category_code'])->toBe('LC_MEN')
            ->and($cell['ratio_shorter_code'])->toBeNull() // gleiches Paar wie eigenes Paar
            ->and($cell['ratio_longer_code'])->toBeNull();
    })->group('base-time-import');
});

// ── import() ──────────────────────────────────────────────────────────────────

describe('BaseTimeImportService::import', function () {
    beforeEach(function () {
        makeFreeStrokeType_bt();
        $this->path = makeBaseTimeWorkbook_bt();
        $this->service = new BaseTimeImportService;
    })->group('base-time-import');

    it('importiert Kategorien, Bewerbe, Sportklassen, Regeln und Basiswerte', function () {
        $result = $this->service->import($this->path, [
            'label' => 'Test-Version',
            'valid_from' => '2021-01-01',
            'valid_until' => null,
        ]);

        expect($result['categories'])->toBe(2)
            ->and($result['disciplines'])->toBe(3)
            ->and($result['sport_classes'])->toBe(3) // S15, S14, S20
            ->and($result['base_times'])->toBe(9)
            ->and(BaseTimeCategory::where('code', 'LC_MEN')->exists())->toBeTrue()
            ->and(BaseTimeDiscipline::where('code', '400FR')->exists())->toBeTrue()
            ->and(BaseTimeSportClass::where('code', 'S20')->exists())->toBeTrue();

        $rule = BaseTimeDerivationRule::whereHas(
            'shorterDiscipline', fn ($q) => $q->where('code', '200FR')
        )->whereHas(
            'longerDiscipline', fn ($q) => $q->where('code', '400FR')
        )->first();

        expect($rule)->not->toBeNull()
            ->and($rule->ratioShorterDiscipline->code)->toBe('100FR')
            ->and($rule->ratioLongerDiscipline->code)->toBe('200FR');
    })->group('base-time-import');

    it('verhindert überlappende Gültigkeitszeiträume', function () {
        $this->service->import($this->path, [
            'label' => 'V1', 'valid_from' => '2021-01-01', 'valid_until' => '2026-12-31',
        ]);

        expect(fn () => $this->service->import($this->path, [
            'label' => 'V2', 'valid_from' => '2025-01-01', 'valid_until' => null,
        ]))->toThrow(RuntimeException::class);
    })->group('base-time-import');
});

// ── importIntoExistingVersion() ────────────────────────────────────────────────

describe('BaseTimeImportService::importIntoExistingVersion', function () {
    beforeEach(function () {
        makeFreeStrokeType_bt();
        $this->path = makeBaseTimeWorkbook_bt();
        $this->service = new BaseTimeImportService;
    })->group('base-time-import');

    it('importiert in eine bereits bestehende Version, ohne eine neue anzulegen', function () {
        $version = BaseTimeVersion::create([
            'label' => 'Bereits angelegt', 'valid_from' => '2021-01-01', 'valid_until' => null,
        ]);

        $result = $this->service->importIntoExistingVersion($this->path, $version);

        expect($result['version_id'])->toBe($version->id)
            ->and(BaseTimeVersion::count())->toBe(1) // keine zusätzliche Version angelegt
            ->and($result['base_times'])->toBe(9);
    })->group('base-time-import');

    it('ersetzt vorhandene Basiswerte bei einem erneuten Import derselben Version', function () {
        $version = BaseTimeVersion::create([
            'label' => 'V1', 'valid_from' => '2021-01-01', 'valid_until' => null,
        ]);

        $this->service->importIntoExistingVersion($this->path, $version);
        // Zweiter Durchlauf mit derselben Datei darf nicht an der Unique-Constraint scheitern.
        $result = $this->service->importIntoExistingVersion($this->path, $version);

        expect($result['base_times'])->toBe(9)
            ->and(BaseTime::where('base_time_version_id', $version->id)->count())->toBe(9);
    })->group('base-time-import');
});
