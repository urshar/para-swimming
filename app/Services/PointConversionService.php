<?php

namespace App\Services;

use App\Models\BaseTime;
use App\Models\BaseTimeCategory;
use App\Models\BaseTimeDiscipline;
use App\Models\BaseTimeSportClass;
use App\Models\BaseTimeVersion;
use App\Support\BaseTimeLookupResult;
use App\Support\BaseTimeSportClassRow;
use App\Support\BaseTimeStrokeGroup;
use App\Support\PointCalculationResult;
use Illuminate\Support\Collection;

/**
 * PointConversionService
 *
 * Rechnet zwischen Schwimmzeit und World-Aquatics-Punkten (P = 1000 × (B/T)³), losgelöst von
 * einem konkreten Result/Meet — anders als WorldAquaticsPointsService, das an ein Ergebnis
 * gebunden ist. Grundlage für den öffentlichen Punkterechner (public-frontend §5.3) und die
 * Punktetabelle. Rein lesend, nichts wird persistiert.
 */
final readonly class PointConversionService
{
    /**
     * Verbandsübliche Lagenreihenfolge — dieselbe Konvention wie PublicRecordService::STROKE_ORDER
     * und QualifyingTimeListController::groupByStroke(); im Bestand keine gemeinsame Stelle dafür.
     * Public statt private, damit PointCalculatorController dieselbe Reihenfolge fürs
     * Bewerbs-Dropdown nutzen kann, ohne sie ein drittes Mal zu duplizieren.
     */
    public const array STROKE_ORDER = [
        'FREE' => 1, 'BACK' => 2, 'BREAST' => 3, 'FLY' => 4, 'MEDLEY' => 5, 'IMRELAY' => 6,
    ];

    /** Die aktuell gültige Basiswert-Version (heute), oder null, wenn keine gepflegt ist. */
    public function currentVersion(): ?BaseTimeVersion
    {
        return BaseTimeVersion::validOn(now()->toDateString())->first();
    }

    /** Zeit → Punkte. */
    public function timeToPoints(
        BaseTimeVersion $version,
        string $course,
        string $gender,
        int $strokeTypeId,
        int $distance,
        string $sportClassCode,
        int $swimTimeCentiseconds,
    ): PointCalculationResult {
        if ($swimTimeCentiseconds <= 0) {
            return PointCalculationResult::failure('invalid_time');
        }

        $lookup = $this->resolveBaseTime($version, $course, $gender, $strokeTypeId, $distance, $sportClassCode);
        if (! $lookup->baseTime) {
            return PointCalculationResult::failure($lookup->errorCode);
        }

        $points = 1000 * ($lookup->baseTime->value_centiseconds / $swimTimeCentiseconds) ** 3;

        return PointCalculationResult::success((int) round($points));
    }

    /**
     * Punkte → Zeit: inverse Formel als erste Schätzung, dann hundertstelweise verkürzt, bis die
     * Rückrechnung die Ausgangspunktzahl wieder erreicht (Spec public-frontend §5.3) —
     * rundungsbedingt kann die Schätzung sonst um eine Hundertstelsekunde zu langsam sein.
     * Iterationsgrenze schützt nur gegen unerwartete Eingaben, im Normalfall genügen 0–2 Schritte.
     */
    public function pointsToTime(
        BaseTimeVersion $version,
        string $course,
        string $gender,
        int $strokeTypeId,
        int $distance,
        string $sportClassCode,
        int $targetPoints,
    ): PointCalculationResult {
        if ($targetPoints <= 0) {
            return PointCalculationResult::failure('invalid_points');
        }

        $lookup = $this->resolveBaseTime($version, $course, $gender, $strokeTypeId, $distance, $sportClassCode);
        if (! $lookup->baseTime) {
            return PointCalculationResult::failure($lookup->errorCode);
        }

        $baseCentiseconds = $lookup->baseTime->value_centiseconds;
        $estimate = (int) round($baseCentiseconds / (($targetPoints / 1000) ** (1 / 3)));
        $time = max($estimate, 1);

        for ($i = 0; $i < 1000 && $time > 1; $i++) {
            $recalculated = (int) round(1000 * ($baseCentiseconds / $time) ** 3);
            if ($recalculated >= $targetPoints) {
                break;
            }
            $time--;
        }

        return PointCalculationResult::success($time);
    }

    /**
     * Basiswert-Matrix einer Version/Bahn für die öffentliche Punktetabelle: je Lage eine Gruppe
     * mit den zugehörigen Bewerben (Spalten) und Sportklassen (Zeilen), Damen und Herren in
     * derselben Zelle nebeneinander. Nur Einzelbewerbe (relay_count = 1) — dieselbe Einschränkung
     * wie im bestehenden Richtzeiten-Modul (QualifyingTimeCalculationService). Sportklassen
     * aufsteigend nach Klassifizierungsnummer (Rückmeldung: Die im Bestand hinterlegte
     * `sort_order` folgt der alten Excel-Quelle und ist absteigend — für die öffentliche Tabelle
     * unübersichtlich).
     *
     * @return Collection<int, BaseTimeStrokeGroup>
     */
    public function buildTable(BaseTimeVersion $version, string $course): Collection
    {
        $categories = BaseTimeCategory::where('course', $course)->whereIn('gender', ['M', 'F'])->get()->keyBy('gender');
        $sportClasses = BaseTimeSportClass::all()->sortBy(fn (BaseTimeSportClass $sc
        ) => self::classNumber($sc->code))->values();

        $disciplines = BaseTimeDiscipline::where('relay_count', 1)
            ->whereHas('baseTimes', fn ($q) => $q->where('base_time_version_id', $version->id))
            ->with('strokeType')
            ->get()
            ->filter(fn (BaseTimeDiscipline $d) => $d->strokeType !== null)
            // Zusammengesetzter Sortierschlüssel statt sortBy() mit Closure-Array (CLAUDE.md) —
            // Letzteres verlangt Zwei-Parameter-Komparatoren, nicht einfache Schlüssel-Extraktoren,
            // und sortiert sonst fehlerhaft.
            ->sortBy(fn (BaseTimeDiscipline $d) => sprintf(
                '%02d|%010d',
                self::STROKE_ORDER[$d->strokeType->lenex_code] ?? 99,
                $d->distance
            ))
            ->values();

        // Alle relevanten base_times einer Abfrage entnehmen statt je Zelle einzeln zu laden.
        $baseTimes = BaseTime::where('base_time_version_id', $version->id)
            ->whereIn('base_time_category_id', $categories->pluck('id'))
            ->whereIn('base_time_discipline_id', $disciplines->pluck('id'))
            ->get()
            ->groupBy(fn (BaseTime $bt
            ) => "$bt->base_time_category_id|$bt->base_time_discipline_id|$bt->base_time_sport_class_id");

        return $disciplines
            ->groupBy(fn (BaseTimeDiscipline $d) => $d->stroke_type_id)
            ->sortBy(fn (Collection $group) => self::STROKE_ORDER[$group->first()->strokeType->lenex_code] ?? 99)
            ->map(function (Collection $strokeDisciplines) use ($sportClasses, $categories, $baseTimes) {
                $rows = $sportClasses->map(function (BaseTimeSportClass $sportClass) use (
                    $strokeDisciplines,
                    $categories,
                    $baseTimes
                ) {
                    $cells = [];
                    foreach (['M', 'F'] as $gender) {
                        $category = $categories->get($gender);
                        foreach ($strokeDisciplines as $discipline) {
                            $key = $category ? "$category->id|$discipline->id|$sportClass->id" : null;
                            $baseTime = $key ? $baseTimes->get($key)?->first() : null;
                            $cells[$gender][$discipline->id] = $baseTime?->formatted_value;
                        }
                    }

                    return new BaseTimeSportClassRow($sportClass, $cells);
                })->values();

                return new BaseTimeStrokeGroup(
                    $strokeDisciplines->first()->strokeType,
                    $strokeDisciplines->values(),
                    $rows,
                );
            })
            ->values();
    }

    /**
     * "S9" → 9, "S21" → 21 — für die aufsteigende Sortierung der Sportklassen (Rückmeldung: die
     * hinterlegte sort_order folgt der alten, absteigenden Excel-Quelle). Public, damit
     * PointCalculatorController dieselbe Reihenfolge fürs Sportklassen-Dropdown nutzt.
     */
    public static function classNumber(string $code): int
    {
        return (int) preg_replace('/\D/', '', $code);
    }

    private function resolveBaseTime(
        BaseTimeVersion $version,
        string $course,
        string $gender,
        int $strokeTypeId,
        int $distance,
        string $sportClassCode,
    ): BaseTimeLookupResult {
        $category = BaseTimeCategory::where('course', $course)->where('gender', $gender)->first();
        if (! $category) {
            return BaseTimeLookupResult::missing('no_category');
        }

        $discipline = BaseTimeDiscipline::where('stroke_type_id', $strokeTypeId)
            ->where('distance', $distance)
            ->where('relay_count', 1)
            ->first();
        if (! $discipline) {
            return BaseTimeLookupResult::missing('no_discipline');
        }

        $sportClass = BaseTimeSportClass::where('code', $sportClassCode)->first();
        if (! $sportClass) {
            return BaseTimeLookupResult::missing('no_sport_class');
        }

        $baseTime = BaseTime::where('base_time_version_id', $version->id)
            ->where('base_time_category_id', $category->id)
            ->where('base_time_discipline_id', $discipline->id)
            ->where('base_time_sport_class_id', $sportClass->id)
            ->first();

        if (! $baseTime || $baseTime->value_type === BaseTime::TYPE_NOT_APPLICABLE || $baseTime->value_centiseconds <= 0) {
            return BaseTimeLookupResult::missing('no_base_time');
        }

        return BaseTimeLookupResult::found($baseTime);
    }
}
