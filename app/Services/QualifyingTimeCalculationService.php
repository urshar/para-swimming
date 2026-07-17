<?php

namespace App\Services;

use App\Models\BaseTime;
use App\Models\BaseTimeCategory;
use App\Models\BaseTimeDiscipline;
use App\Models\BaseTimeSportClass;
use App\Models\BaseTimeVersion;
use App\Models\Meet;
use App\Models\QualifyingExcludedDiscipline;
use App\Models\QualifyingTime;
use App\Models\QualifyingTimeList;

/**
 * QualifyingTimeCalculationService
 *
 * Automatische Berechnung der Richtzeiten (Phase 2 der Spec "Richtzeiten
 * ÖSTM & ÖM") als inverse Anwendung der World-Aquatics-Formel:
 *
 *   P = 1000 × (B/T)³  ⟺  T = B / (P/1000)^(1/3)
 *
 * B = Basiszeit (aus den bestehenden Basiswert-Tabellen, siehe
 * WorldAquaticsPointsService), P = Zielpunkte je Sportklasse
 * (QualifyingTargetPoint, Default 100), T = die zu ermittelnde Richtzeit.
 *
 * Es wird ausschließlich über Kombinationen (Kurs/Geschlecht/Bewerb/Sportklasse)
 * iteriert, für die tatsächlich ein gültiger Basiswert-Eintrag existiert —
 * Bewerbe ohne Basiswert (z.B. 150m Lagen für bestimmte Sportklassen) werden
 * dadurch automatisch ausgelassen, ohne eigene Sonderlogik. Zusätzlich werden
 * Bewerbe übersprungen, die als "bei ÖSTM & ÖM nicht ausgetragen" markiert
 * sind (admin-verwaltbar, siehe QualifyingExcludedDiscipline — z.B. 25m-Bewerbe, 800m/1500m Freistil). Staffeln (relay_count > 1) werden nicht
 * berücksichtigt.
 *
 * Manuell gesetzte Richtzeiten (source=MANUAL) werden bei einer Neuberechnung
 * standardmäßig nicht überschrieben — nur mit $overwriteManual=true.
 */
class QualifyingTimeCalculationService
{
    /**
     * @return array{
     *     error?: string,
     *     calculated: int,
     *     skipped: int,
     *     skipped_manual_protected: int,
     *     skipped_reasons: array<string, int>,
     *     reference_meet?: string,
     *     version?: string,
     * }
     */
    public function calculateForList(QualifyingTimeList $list, bool $overwriteManual = false): array
    {
        $empty = ['calculated' => 0, 'skipped' => 0, 'skipped_manual_protected' => 0, 'skipped_reasons' => []];

        /** @var Meet|null $referenceMeet */
        $referenceMeet = $list->meets()->orderBy('start_date')->first();
        if (! $referenceMeet) {
            return $empty + ['error' => 'Dieser Richtzeitenliste ist kein Meet zugeordnet (ÖSTM & ÖM-Veranstaltung fehlt).'];
        }
        if (! $referenceMeet->course) {
            return $empty + ['error' => "Das zugeordnete Meet \"$referenceMeet->name\" hat keinen Kurs hinterlegt."];
        }
        if (! $referenceMeet->start_date) {
            return $empty + ['error' => "Das zugeordnete Meet \"$referenceMeet->name\" hat kein Startdatum hinterlegt."];
        }

        $version = BaseTimeVersion::validOn($referenceMeet->start_date->toDateString())->first();
        if (! $version) {
            return $empty + ['error' => "Keine gültige Basiswert-Version für das Datum $referenceMeet->start_date->toDateString()."];
        }

        $calculated = 0;
        $skippedManualProtected = 0;
        $skippedReasons = [];

        $excludedDisciplineIds = QualifyingExcludedDiscipline::pluck('base_time_discipline_id');

        $disciplines = BaseTimeDiscipline::where('relay_count', 1)
            ->whereNotIn('id', $excludedDisciplineIds)
            ->with('strokeType')
            ->get();
        $sportClasses = BaseTimeSportClass::ordered()->get();

        foreach (['M', 'F'] as $gender) {
            $category = BaseTimeCategory::where('course', $referenceMeet->course)->where('gender', $gender)->first();
            if (! $category) {
                $reason = "keine Basiswert-Kategorie für $referenceMeet->course/$gender";
                $skippedReasons[$reason] = ($skippedReasons[$reason] ?? 0) + 1;

                continue;
            }

            foreach ($disciplines as $discipline) {
                if (! $discipline->strokeType) {
                    continue;
                }

                $prefix = match ($discipline->strokeType->lenex_code) {
                    'BREAST' => 'SB',
                    'MEDLEY', 'IMRELAY' => 'SM',
                    default => 'S',
                };

                foreach ($sportClasses as $sportClassRow) {
                    $baseTime = BaseTime::where('base_time_version_id', $version->id)
                        ->where('base_time_category_id', $category->id)
                        ->where('base_time_discipline_id', $discipline->id)
                        ->where('base_time_sport_class_id', $sportClassRow->id)
                        ->first();

                    if (! $baseTime
                        || $baseTime->value_type === BaseTime::TYPE_NOT_APPLICABLE
                        || $baseTime->value_centiseconds <= 0
                    ) {
                        continue; // kein Basiswert für diese Kombination -> nicht berechnen
                    }

                    $numericPart = preg_replace('/^S/', '', $sportClassRow->code);
                    $fullSportClass = $prefix.$numericPart;

                    $targetPoints = $list->targetPointsFor($fullSportClass);
                    if ($targetPoints <= 0) {
                        $reason = "ungültige Zielpunkte für $fullSportClass";
                        $skippedReasons[$reason] = ($skippedReasons[$reason] ?? 0) + 1;

                        continue;
                    }

                    $existing = QualifyingTime::where('qualifying_time_list_id', $list->id)
                        ->where('stroke_type_id', $discipline->stroke_type_id)
                        ->where('distance', $discipline->distance)
                        ->where('gender', $gender)
                        ->where('sport_class', $fullSportClass)
                        ->first();

                    if ($existing && $existing->isManual() && ! $overwriteManual) {
                        $skippedManualProtected++;

                        continue;
                    }

                    $valueCentiseconds = (int) round(
                        $baseTime->value_centiseconds / (($targetPoints / 1000) ** (1 / 3))
                    );

                    QualifyingTime::updateOrCreate(
                        [
                            'qualifying_time_list_id' => $list->id,
                            'stroke_type_id' => $discipline->stroke_type_id,
                            'distance' => $discipline->distance,
                            'gender' => $gender,
                            'sport_class' => $fullSportClass,
                        ],
                        [
                            'value_centiseconds' => $valueCentiseconds,
                            'source' => QualifyingTime::SOURCE_CALCULATED,
                        ]
                    );

                    $calculated++;
                }
            }
        }

        return [
            'calculated' => $calculated,
            'skipped' => array_sum($skippedReasons),
            'skipped_manual_protected' => $skippedManualProtected,
            'skipped_reasons' => $skippedReasons,
            'reference_meet' => $referenceMeet->name,
            'version' => $version->label,
        ];
    }
}
