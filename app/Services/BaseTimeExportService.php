<?php

namespace App\Services;

use App\Models\BaseTime;
use App\Models\BaseTimeCategory;
use App\Models\BaseTimeDiscipline;
use App\Models\BaseTimeSportClass;
use App\Models\BaseTimeVersion;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Exception;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * BaseTimeExportService
 *
 * Exportiert eine Basiswert-Version zurück in eine Excel-Datei im Originalformat:
 * ein Arbeitsblatt je Kategorie, Zeile 1 = Sportklassen, Spalte A = Bewerbe.
 *
 * Es werden ausschließlich die bereits berechneten Werte geschrieben (keine Formeln).
 * MANUAL- und CALCULATED-Werte bleiben farblich unterscheidbar (schwarz/orange), analog
 * zur Originaldatei, damit beim Öffnen weiterhin erkennbar ist, was Weltrekord und was
 * automatisch hergeleitet ist.
 */
class BaseTimeExportService
{
    /** Muss zur Original-Farbe der importierten Datei nicht exakt passen — dient nur der visuellen Unterscheidung. */
    private const string CALCULATED_COLOR_RGB = 'ED7D31';

    /** Kehrt die beim Import erkannte Sportklassen-Umbenennung um (S20 → R20 usw.). */
    private const array SPORT_CLASS_EXPORT_ALIASES = [
        'S20' => 'R20',
        'S34' => 'R34',
        'S49' => 'R49',
    ];

    /**
     * Exportiert die Version als .xlsx und gibt den absoluten Dateipfad zurück.
     *
     * @throws Exception
     */
    public function export(BaseTimeVersion $version): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        $categories = BaseTimeCategory::query()
            ->whereHas('baseTimes', fn ($q) => $q->where('base_time_version_id', $version->id))
            ->orderBy('code')
            ->get();

        foreach ($categories as $category) {
            $this->writeCategorySheet($spreadsheet, $version, $category);
        }

        $filename = 'base-time-export_'.$version->id.'_'.uniqid().'.xlsx';
        $directory = storage_path('app/base-time-exports');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $path = $directory.DIRECTORY_SEPARATOR.$filename;

        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    /** Dateiname zum Download, z.B. "OeBSV-Base-Times_2021-2026.xlsx". */
    public function downloadFilename(BaseTimeVersion $version): string
    {
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', $version->label);

        return "OeBSV-Base-Times_$slug.xlsx";
    }

    private function writeCategorySheet(
        Spreadsheet $spreadsheet,
        BaseTimeVersion $version,
        BaseTimeCategory $category
    ): void {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($category->label);

        $disciplines = $this->loadDisciplines($version->id, $category->id);
        $sportClasses = $this->loadSportClasses($version->id, $category->id);
        $matrix = $this->loadMatrix($version->id, $category->id);

        foreach ($sportClasses as $index => $sportClass) {
            $col = $index + 2;
            $code = self::SPORT_CLASS_EXPORT_ALIASES[$sportClass->code] ?? $sportClass->code;
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col).'1', $code);
        }

        foreach ($disciplines as $rowIndex => $discipline) {
            $row = $rowIndex + 2;
            $sheet->setCellValue('A'.$row, $discipline->code);

            foreach ($sportClasses as $colIndex => $sportClass) {
                $col = $colIndex + 2;
                $baseTime = $matrix[$discipline->id][$sportClass->id] ?? null;
                if ($baseTime === null) {
                    continue; // Kombination existiert für diese Kategorie nicht — Zelle bleibt leer
                }

                $coordinate = Coordinate::stringFromColumnIndex($col).$row;
                $value = $baseTime['type'] === BaseTime::TYPE_NOT_APPLICABLE
                    ? 0
                    : round($baseTime['centiseconds'] / 100, 2);

                $sheet->setCellValue($coordinate, $value);
                $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode('0.00');

                if ($baseTime['type'] === BaseTime::TYPE_CALCULATED) {
                    $sheet->getStyle($coordinate)->getFont()->getColor()->setRGB(self::CALCULATED_COLOR_RGB);
                }
            }
        }

        $sheet->getColumnDimension('A')->setAutoSize(true);
    }

    private function loadDisciplines(int $versionId, int $categoryId): Collection
    {
        return BaseTimeDiscipline::query()
            ->whereHas('baseTimes', fn ($q) => $q->where('base_time_version_id', $versionId)
                ->where('base_time_category_id', $categoryId))
            ->with('strokeType')
            ->get()
            ->sortBy([
                fn (BaseTimeDiscipline $d) => $d->strokeType?->name_de,
                fn (BaseTimeDiscipline $d) => $d->relay_count,
                fn (BaseTimeDiscipline $d) => $d->distance,
            ])
            ->values();
    }

    // ── Arbeitsblatt schreiben ────────────────────────────────────────────────

    private function loadSportClasses(int $versionId, int $categoryId): Collection
    {
        return BaseTimeSportClass::query()
            ->whereHas('baseTimes', fn ($q) => $q->where('base_time_version_id', $versionId)
                ->where('base_time_category_id', $categoryId))
            ->ordered()
            ->get();
    }

    // ── Daten laden ───────────────────────────────────────────────────────────

    /** @return array<int, array<int, array{type: string, centiseconds: int}>> [disciplineId][sportClassId] */
    private function loadMatrix(int $versionId, int $categoryId): array
    {
        $matrix = [];

        BaseTime::query()
            ->where('base_time_version_id', $versionId)
            ->where('base_time_category_id', $categoryId)
            ->get(['base_time_discipline_id', 'base_time_sport_class_id', 'value_centiseconds', 'value_type'])
            ->each(function (BaseTime $row) use (&$matrix) {
                $matrix[$row->base_time_discipline_id][$row->base_time_sport_class_id] = [
                    'type' => $row->value_type,
                    'centiseconds' => $row->value_centiseconds,
                ];
            });

        return $matrix;
    }
}
