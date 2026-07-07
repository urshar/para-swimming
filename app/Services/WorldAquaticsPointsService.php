<?php

namespace App\Services;

use App\Models\BaseTime;
use App\Models\BaseTimeCategory;
use App\Models\BaseTimeDiscipline;
use App\Models\BaseTimeSportClass;
use App\Models\BaseTimeVersion;
use App\Models\Meet;
use App\Models\Result;

/**
 * WorldAquaticsPointsService
 *
 * Berechnet die World-Aquatics-Punkte P = 1000 × (B/T)³ für ein einzelnes Result
 * bzw. für alle Results eines Meets — auf Basis der Basiswert-Version, deren
 * Gültigkeitszeitraum das Wettkampfdatum umfasst.
 *
 * B = Basiszeit (base_times.value_centiseconds), T = Schwimmzeit (result->swim_time).
 * Ergebnis überschreibt results.points —, auch wenn beim LENEX-Import bereits ein
 * (ggf. mit einer falschen/veralteten Basiswert-Tabelle berechneter) Wert gesetzt wurde.
 */
class WorldAquaticsPointsService
{
    /**
     * Berechnet und speichert die Punkte für alle Results eines Meets neu.
     *
     * @return array{updated: int, skipped: int, skipped_reasons: array<string, int>}
     */
    public function recalculateForMeet(Meet $meet): array
    {
        $results = Result::query()
            ->with('swimEvent.strokeType')
            ->where('meet_id', $meet->id)
            ->get();

        $updated = 0;
        $skippedReasons = [];

        foreach ($results as $result) {
            [$points, $reason] = $this->resolvePoints($result, $meet);

            if ($points === null) {
                $skippedReasons[$reason] = ($skippedReasons[$reason] ?? 0) + 1;

                continue;
            }

            if ($result->points !== $points) {
                $result->update(['points' => $points]);
            }
            $updated++;
        }

        return [
            'updated' => $updated,
            'skipped' => array_sum($skippedReasons),
            'skipped_reasons' => $skippedReasons,
        ];
    }

    /** Berechnet die Punkte für ein einzelnes Result, ohne zu speichern. */
    public function calculatePoints(Result $result, Meet $meet): ?int
    {
        [$points] = $this->resolvePoints($result, $meet);

        return $points;
    }

    /** @return array{0: ?int, 1: string} [Punkte oder null, Grund falls null] */
    private function resolvePoints(Result $result, Meet $meet): array
    {
        if (! $result->swim_time || $result->swim_time <= 0) {
            return [null, 'keine gültige Schwimmzeit'];
        }

        if (! $meet->start_date || ! $meet->course) {
            return [null, 'Meet ohne Datum/Kurs'];
        }

        $version = BaseTimeVersion::validOn($meet->start_date->toDateString())->first();
        if (! $version) {
            return [null, 'keine gültige Basiswert-Version für das Wettkampfdatum'];
        }

        $event = $result->swimEvent;
        if (! $event) {
            return [null, 'Result ohne SwimEvent'];
        }

        $gender = match (strtoupper((string) $event->gender)) {
            'M' => 'M',
            'F' => 'F',
            'X', 'A' => 'X',
            default => null,
        };
        if ($gender === null) {
            return [null, 'Geschlecht des Bewerbs nicht zuordenbar'];
        }

        $category = BaseTimeCategory::where('course', $meet->course)->where('gender', $gender)->first();
        if (! $category) {
            return [null, "keine Basiswert-Kategorie für $meet->course/$gender"];
        }

        $discipline = BaseTimeDiscipline::where('stroke_type_id', $event->stroke_type_id)
            ->where('distance', $event->distance)
            ->where('relay_count', $event->relay_count)
            ->first();
        if (! $discipline) {
            return [null, 'kein passender Basiswert-Bewerb gefunden'];
        }

        $sportClassCode = $this->normalizeSportClassCode($result->sport_class);
        if ($sportClassCode === null) {
            return [null, "Sportklasse \"$result->sport_class\" nicht erkennbar"];
        }

        $sportClass = BaseTimeSportClass::where('code', $sportClassCode)->first();
        if (! $sportClass) {
            return [null, "keine Basiswert-Sportklasse \"$sportClassCode\" gefunden"];
        }

        $baseTime = BaseTime::where('base_time_version_id', $version->id)
            ->where('base_time_category_id', $category->id)
            ->where('base_time_discipline_id', $discipline->id)
            ->where('base_time_sport_class_id', $sportClass->id)
            ->first();

        if (! $baseTime || $baseTime->value_type === BaseTime::TYPE_NOT_APPLICABLE || $baseTime->value_centiseconds <= 0) {
            return [null, 'kein gültiger Basiswert für diese Kombination'];
        }

        $points = 1000 * ($baseTime->value_centiseconds / $result->swim_time) ** 3;

        return [(int) round($points), ''];
    }

    // ── Zuordnung & Berechnung ────────────────────────────────────────────────

    /** "S9"/"SB9"/"SM9" → "S9" — die Basiswert-Tabelle führt keinen Stroke-Präfixen. */
    private function normalizeSportClassCode(?string $sportClass): ?string
    {
        if (! $sportClass) {
            return null;
        }

        if (! preg_match('/^S[BM]?(\d+)$/', strtoupper(trim($sportClass)), $m)) {
            return null;
        }

        return 'S'.$m[1];
    }
}
