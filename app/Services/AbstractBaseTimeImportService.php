<?php

namespace App\Services;

use App\Models\BaseTime;
use App\Models\BaseTimeCategory;
use App\Models\BaseTimeDerivationRule;
use App\Models\BaseTimeDiscipline;
use App\Models\BaseTimeSportClass;
use App\Models\BaseTimeVersion;
use App\Models\StrokeType;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * AbstractBaseTimeImportService
 *
 * Gemeinsame Grundlage aller Basiswert-Importe. Kapselt die dateiformat-unabhängige
 * Persistierungs-Logik (Version anlegen/prüfen, Kategorien/Bewerbe/Sportklassen/Regeln/
 * Basiswerte schreiben). Jede konkrete Umsetzung liefert, über parse() dieselbe geparste
 * Struktur; der Weg von dort in die Datenbank ist für alle Formate identisch.
 *
 * parse() muss ein Array mit folgenden Schlüsseln liefern:
 *   - categories:   [code ⇒ ['course', 'gender', 'label']]
 *   - disciplines:  [code ⇒ ['stroke_lenex_code', 'distance', 'relay_count']]
 *   - sportClasses: [code ⇒ ['sort_order' ⇒ int]]
 *   - cells:        Liste von [category_code, discipline_code, sport_class_code,
 *                   value_centiseconds, value_type, shorter_code, longer_code,
 *                   ratio_category_code, ratio_shorter_code, ratio_longer_code]
 *   - warnings:     string[]
 */
abstract class AbstractBaseTimeImportService
{
    /** @var array<string, StrokeType|null> */
    private array $strokeTypeCache = [];

    /**
     * Liest die Datei und liefert eine strukturierte Vorschau, ohne die Datenbank zu ändern.
     *
     * @throws Throwable wenn die Datei nicht gelesen werden kann
     */
    abstract public function parse(string $filePath): array;

    // ── Öffentliche API ───────────────────────────────────────────────────────

    /**
     * Importiert die Datei als neue Basiswert-Version.
     *
     * @param  array{label: string, valid_from: string, valid_until: ?string}  $versionData
     *
     * @throws RuntimeException|Throwable wenn sich der Gültigkeitszeitraum mit einer bestehenden Version überschneidet
     */
    public function import(string $filePath, array $versionData): array
    {
        $parsed = $this->parse($filePath);

        return DB::transaction(function () use ($parsed, $versionData) {
            $this->assertNoOverlap($versionData);

            $version = BaseTimeVersion::create($versionData);

            return $this->importParsedData($parsed, $version);
        });
    }

    /**
     * Importiert die Datei in eine bereits bestehende Basiswert-Version — z.B. wenn die Version
     * zuvor separat angelegt wurde und nun (erstmalig) mit Daten befüllt werden soll. Es wird
     * keine Überlappungsprüfung durchgeführt, da die Version bereits existiert.
     *
     * @throws Throwable wenn die Datei nicht gelesen werden kann
     */
    public function importIntoExistingVersion(string $filePath, BaseTimeVersion $version): array
    {
        $parsed = $this->parse($filePath);

        return DB::transaction(fn () => $this->importParsedData($parsed, $version));
    }

    // ── Persistierung ─────────────────────────────────────────────────────────

    private function assertNoOverlap(array $versionData): void
    {
        if (BaseTimeVersion::overlapsExisting($versionData['valid_from'], $versionData['valid_until'] ?? null)) {
            throw new RuntimeException(
                'Der Gültigkeitszeitraum überschneidet sich mit einer bestehenden Basiswert-Version.'
            );
        }
    }

    /** Gemeinsamer Kern von import() und importIntoExistingVersion() — Version existiert bereits. */
    private function importParsedData(array $parsed, BaseTimeVersion $version): array
    {
        $categoryIds = $this->importCategories($parsed['categories']);
        $disciplineIds = $this->importDisciplines($parsed['disciplines'], $parsed['warnings']);
        $sportClassIds = $this->importSportClasses($parsed['sportClasses']);
        $rulesImported = $this->importDerivationRules($parsed['cells'], $categoryIds, $disciplineIds);
        $baseTimesImported = $this->importBaseTimes(
            $parsed['cells'], $version->id, $categoryIds, $disciplineIds, $sportClassIds
        );

        return [
            'version_id' => $version->id,
            'categories' => count($categoryIds),
            'disciplines' => count($disciplineIds),
            'sport_classes' => count($sportClassIds),
            'derivation_rules' => $rulesImported,
            'base_times' => $baseTimesImported,
            'warnings' => $parsed['warnings'],
        ];
    }

    private function importCategories(array $categories): array
    {
        $ids = [];

        foreach ($categories as $code => $attrs) {
            $category = BaseTimeCategory::firstOrCreate(
                ['code' => $code],
                [
                    'course' => $attrs['course'],
                    'gender' => $attrs['gender'],
                    'label' => $attrs['label'],
                ]
            );
            $ids[$code] = $category->id;
        }

        return $ids;
    }

    private function importDisciplines(array $disciplines, array &$warnings): array
    {
        $ids = [];

        foreach ($disciplines as $code => $attrs) {
            $strokeType = $this->resolveStrokeType($attrs['stroke_lenex_code']);
            if (! $strokeType) {
                $warnings[] = "Kein StrokeType mit lenex_code \"{$attrs['stroke_lenex_code']}\" gefunden ".
                    "(Bewerb \"$code\") — übersprungen.";

                continue;
            }

            $discipline = BaseTimeDiscipline::firstOrCreate(
                ['code' => $code],
                [
                    'stroke_type_id' => $strokeType->id,
                    'distance' => $attrs['distance'],
                    'relay_count' => $attrs['relay_count'],
                ]
            );
            $ids[$code] = $discipline->id;
        }

        return $ids;
    }

    private function resolveStrokeType(string $lenexCode): ?StrokeType
    {
        if (! array_key_exists($lenexCode, $this->strokeTypeCache)) {
            $this->strokeTypeCache[$lenexCode] = StrokeType::where('lenex_code', $lenexCode)->first();
        }

        return $this->strokeTypeCache[$lenexCode];
    }

    private function importSportClasses(array $sportClasses): array
    {
        $ids = [];

        foreach ($sportClasses as $code => $attrs) {
            $sportClass = BaseTimeSportClass::firstOrCreate(
                ['code' => $code],
                ['sort_order' => $attrs['sort_order']]
            );
            $ids[$code] = $sportClass->id;
        }

        return $ids;
    }

    private function importDerivationRules(array $cells, array $categoryIds, array $disciplineIds): int
    {
        $seen = [];
        $count = 0;

        foreach ($cells as $cell) {
            if ($cell['value_type'] !== BaseTime::TYPE_CALCULATED || $cell['shorter_code'] === null) {
                continue;
            }

            $key = implode('|', [
                $cell['category_code'], $cell['shorter_code'], $cell['longer_code'],
                $cell['ratio_category_code'], $cell['ratio_shorter_code'], $cell['ratio_longer_code'],
            ]);

            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            BaseTimeDerivationRule::firstOrCreate(
                [
                    'base_time_category_id' => $categoryIds[$cell['category_code']],
                    'shorter_discipline_id' => $disciplineIds[$cell['shorter_code']],
                    'longer_discipline_id' => $disciplineIds[$cell['longer_code']],
                ],
                [
                    'ratio_reference_category_id' => $cell['ratio_category_code']
                        ? ($categoryIds[$cell['ratio_category_code']] ?? null) : null,
                    'ratio_shorter_discipline_id' => $cell['ratio_shorter_code']
                        ? ($disciplineIds[$cell['ratio_shorter_code']] ?? null) : null,
                    'ratio_longer_discipline_id' => $cell['ratio_longer_code']
                        ? ($disciplineIds[$cell['ratio_longer_code']] ?? null) : null,
                ]
            );
            $count++;
        }

        return $count;
    }

    private function importBaseTimes(
        array $cells,
        int $versionId,
        array $categoryIds,
        array $disciplineIds,
        array $sportClassIds,
    ): int {
        $rows = [];
        $now = now();

        foreach ($cells as $cell) {
            if (! isset($categoryIds[$cell['category_code']], $disciplineIds[$cell['discipline_code']], $sportClassIds[$cell['sport_class_code']])) {
                continue;
            }

            $rows[] = [
                'base_time_version_id' => $versionId,
                'base_time_category_id' => $categoryIds[$cell['category_code']],
                'base_time_discipline_id' => $disciplineIds[$cell['discipline_code']],
                'base_time_sport_class_id' => $sportClassIds[$cell['sport_class_code']],
                'value_centiseconds' => $cell['value_centiseconds'],
                'value_type' => $cell['value_type'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Ersetzt statt zu duplizieren: falls für diese Version/Kategorien bereits Basiswerte
        // existieren (z.B. bei einem versehentlichen zweiten Import derselben Datei), werden sie
        // vorher entfernt, statt an der Unique-Constraint zu scheitern.
        BaseTime::where('base_time_version_id', $versionId)
            ->whereIn('base_time_category_id', array_values($categoryIds))
            ->delete();

        foreach (array_chunk($rows, 500) as $chunk) {
            BaseTime::insert($chunk);
        }

        return count($rows);
    }
}
