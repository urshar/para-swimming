<?php

namespace App\Services;

use App\Models\BaseTime;
use App\Models\BaseTimeCategory;
use App\Models\BaseTimeDiscipline;
use App\Models\BaseTimeSportClass;
use App\Models\BaseTimeVersion;
use App\Support\TimeParser;
use Illuminate\Support\Collection;

/**
 * BaseTimeTextExportService
 *
 * Exportiert eine Basiswert-Version in die von Splash MeetManager/Hy-Tek gelesene
 * "Points"-Textdatei — die Umkehrung von BaseTimeTextImportService (Round-Trip).
 *
 * Aufbau: Kopf-Metadaten + "<BASETIMES>" + Spaltenkopf
 * (COURSE;GENDER;RELAYCOUNT;DISTANCE;STROKE;HANDICAP;MINTIME;MAXTIME) + je Basiswert eine
 * ";"-getrennte Datenzeile mit 7 Feldern (ohne MAXTIME, wie in den OeBSV-Originaldateien).
 *
 * Abbildung (Umkehrung des Imports):
 *   - Kategorie → COURSE (SCM/LCM) + GENDER (F/M/X)
 *   - Bewerb → RELAYCOUNT/DISTANCE/STROKE (Lenex-Code der Schwimmart, MEDLEY für IM und ME)
 *   - Sportklasse → HANDICAP: bei Einzelbewerben ohne "S"-Präfix ("S1" → "1"), bei Staffeln
 *     mit "S"-Präfix ("S14"), genau wie es der Import wieder einliest
 *   - NOT_APPLICABLE → Sentinel "99:99.99", sonst die Zeit als MM:SS.cs
 */
class BaseTimeTextExportService
{
    /** MeetManager-Tabellen-Id der OeBSV-Tabelle (fix, siehe Beispieldatei 502-para-2021.txt). */
    private const int TABLE_ID = 502;

    private const string NOT_APPLICABLE_TIME = '99:99.99';

    /** Windows-Zeilenumbruch — Zielprogramm (MeetManager) läuft unter Windows. */
    private const string EOL = "\r\n";

    /**
     * Exportiert die Version als .txt und gibt den absoluten Dateipfad zurück. Ist $category
     * gesetzt, werden nur deren Basiswerte geschrieben, sonst alle Kategorien der Version.
     */
    public function export(BaseTimeVersion $version, ?BaseTimeCategory $category = null): string
    {
        $content = $this->buildContent($version, $category);

        $directory = storage_path('app/base-time-exports');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $path = $directory.DIRECTORY_SEPARATOR.'base-time-text-export_'.$version->id.'_'.uniqid().'.txt';

        file_put_contents($path, $content);

        return $path;
    }

    /** Baut den vollständigen Dateiinhalt (Kopf + Datenzeilen). */
    public function buildContent(BaseTimeVersion $version, ?BaseTimeCategory $category = null): string
    {
        $lines = $this->headerLines($version);

        foreach ($this->categoriesFor($version, $category) as $cat) {
            $disciplines = $this->loadDisciplines($version->id, $cat->id);
            $sportClasses = $this->loadSportClasses($version->id, $cat->id);
            $matrix = $this->loadMatrix($version->id, $cat->id);

            foreach ($disciplines as $discipline) {
                foreach ($sportClasses as $sportClass) {
                    $baseTime = $matrix[$discipline->id][$sportClass->id] ?? null;
                    if ($baseTime === null) {
                        continue;
                    }

                    $lines[] = $this->formatLine($cat, $discipline, $sportClass, $baseTime);
                }
            }
        }

        return implode(self::EOL, $lines).self::EOL;
    }

    /** Dateiname zum Download, z.B. "OeBSV-Base-Times_2021-2026.txt" bzw. "..._SC-Women.txt" je Kategorie. */
    public function downloadFilename(BaseTimeVersion $version, ?BaseTimeCategory $category = null): string
    {
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', $version->label);
        $suffix = $category !== null ? '_'.preg_replace('/[^A-Za-z0-9_-]+/', '-', $category->label) : '';

        return "OeBSV-Base-Times_$slug$suffix.txt";
    }

    /**
     * Die zu exportierenden Kategorien: nur die übergebene, sonst alle mit Basiswerten in der Version.
     *
     * @return Collection<int, BaseTimeCategory>
     */
    private function categoriesFor(BaseTimeVersion $version, ?BaseTimeCategory $category): Collection
    {
        if ($category !== null) {
            return collect([$category]);
        }

        return BaseTimeCategory::query()
            ->whereHas('baseTimes', fn ($q) => $q->where('base_time_version_id', $version->id))
            ->orderBy('code')
            ->get();
    }

    /** @return string[] */
    private function headerLines(BaseTimeVersion $version): array
    {
        return [
            'Formula=CUBED',
            'Id='.self::TABLE_ID,
            'Name='.$version->label,
            'Options=HANDICAP',
            'ShortNameVersion='.$version->label,
            'Version='.$version->valid_from->format('Y'),
            '<BASETIMES>',
            '',
            'COURSE;GENDER;RELAYCOUNT;DISTANCE;STROKE;HANDICAP;MINTIME;MAXTIME',
        ];
    }

    /**
     * @param  array{type: string, centiseconds: int}  $baseTime
     */
    private function formatLine(
        BaseTimeCategory $category,
        BaseTimeDiscipline $discipline,
        BaseTimeSportClass $sportClass,
        array $baseTime,
    ): string {
        // Einzelbewerbe ohne "S"-Präfix ("S1" → "1"), Staffeln mit ("S14") — exakt wie der Import liest.
        $handicap = $discipline->relay_count === 1
            ? preg_replace('/^S/', '', $sportClass->code)
            : $sportClass->code;

        $time = $baseTime['type'] === BaseTime::TYPE_NOT_APPLICABLE
            ? self::NOT_APPLICABLE_TIME
            : TimeParser::display($baseTime['centiseconds']);

        return implode(';', [
            $category->course,
            $category->gender,
            $discipline->relay_count,
            $discipline->distance,
            $discipline->strokeType->lenex_code,
            $handicap,
            $time,
        ]);
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

    private function loadSportClasses(int $versionId, int $categoryId): Collection
    {
        return BaseTimeSportClass::query()
            ->whereHas('baseTimes', fn ($q) => $q->where('base_time_version_id', $versionId)
                ->where('base_time_category_id', $categoryId))
            ->ordered()
            ->get();
    }

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
