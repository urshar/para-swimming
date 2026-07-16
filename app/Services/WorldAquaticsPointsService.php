<?php

namespace App\Services;

use App\Models\BaseTime;
use App\Models\BaseTimeCategory;
use App\Models\BaseTimeDiscipline;
use App\Models\BaseTimeSportClass;
use App\Models\BaseTimeVersion;
use App\Models\Meet;
use App\Models\Result;
use Illuminate\Support\Collection;

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
     * $version übersteuert die automatische Zuordnung nach Wettkampfdatum — z.B. wenn bewusst
     * eine andere als die automatisch ermittelte Basiswert-Version verwendet werden soll.
     *
     * @return array{updated: int, skipped: int, skipped_reasons: array<string, int>, skipped_results: array<int, string>}
     */
    public function recalculateForMeet(Meet $meet, ?BaseTimeVersion $version = null): array
    {
        $results = Result::query()
            ->with(['swimEvent.strokeType', 'athlete'])
            ->where('meet_id', $meet->id)
            ->get();

        $updated = 0;
        $skippedReasons = [];
        $skippedResults = [];

        foreach ($results as $result) {
            [$points, $reason] = $this->resolvePoints($result, $meet, $version);

            if ($points === null) {
                $skippedReasons[$reason] = ($skippedReasons[$reason] ?? 0) + 1;
                $skippedResults[$result->id] = $reason;

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
            'skipped_results' => $skippedResults,
        ];
    }

    /** Die Basiswert-Version, die für ein Meet automatisch anhand des Wettkampfdatums ermittelt wird. */
    public function resolveAutomaticVersion(Meet $meet): ?BaseTimeVersion
    {
        if (! $meet->start_date) {
            return null;
        }

        return BaseTimeVersion::validOn($meet->start_date->toDateString())->first();
    }

    /**
     * Ergebnisse eines Meets, deren gespeicherte Punkte vom aktuell neu
     * berechneten Wert abweichen (z.B. weil sich eine Basiszeit nachträglich
     * geändert hat, seit die Punkte zuletzt berechnet wurden — vgl. den Fall
     * bei Result #964). Rein lesend, speichert nichts.
     *
     * @return Collection<int, Result>
     */
    public function findOutdatedResults(Meet $meet, ?BaseTimeVersion $version = null): Collection
    {
        return Result::query()
            ->with(['swimEvent.strokeType', 'athlete'])
            ->where('meet_id', $meet->id)
            ->get()
            ->filter(function (Result $result) use ($meet, $version) {
                $recalculated = $this->calculatePoints($result, $meet, $version);

                return $recalculated !== null && $recalculated !== $result->points;
            })
            ->values();
    }

    /** Berechnet die Punkte für ein einzelnes Result, ohne zu speichern. */
    public function calculatePoints(Result $result, Meet $meet, ?BaseTimeVersion $version = null): ?int
    {
        [$points] = $this->resolvePoints($result, $meet, $version);

        return $points;
    }

    /** @return array{0: ?int, 1: string} [Punkte oder null, Grund falls null] */
    private function resolvePoints(Result $result, Meet $meet, ?BaseTimeVersion $version = null): array
    {
        if (! $result->swim_time || $result->swim_time <= 0) {
            return [null, 'keine gültige Schwimmzeit'];
        }

        if (! $meet->course) {
            return [null, 'Meet ohne Kurs'];
        }

        $version ??= $this->resolveAutomaticVersion($meet);
        if (! $version) {
            return [null, 'keine gültige Basiswert-Version für das Wettkampfdatum'];
        }

        $event = $result->swimEvent;
        if (! $event) {
            return [null, 'Result ohne SwimEvent'];
        }

        // Bei Einzelbewerben zählt das Geschlecht des Athleten (Mixed als Basiswert-Kategorie gibt es
        // nur für Staffeln — manche Meets listen Einzelbewerbe organisatorisch trotzdem als "Mixed").
        $genderSource = $event->relay_count > 1 ? $event->gender : $result->athlete?->gender;

        $gender = match (strtoupper((string) $genderSource)) {
            'M' => 'M',
            'F' => 'F',
            'X', 'A' => $event->relay_count > 1 ? 'X' : null,
            default => null,
        };
        if ($gender === null) {
            return [
                null, $event->relay_count > 1
                    ? 'Geschlecht des Bewerbs nicht zuordenbar'
                    : 'Geschlecht des Athleten nicht zuordenbar (M/F erforderlich)',
            ];
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
            $strokeCode = $event->strokeType?->lenex_code ?? "stroke_type_id=$event->stroke_type_id";

            return [
                null, "kein Basiswert-Bewerb für $strokeCode/{$event->distance}m".
                ($event->relay_count > 1 ? "/{$event->relay_count}x" : ''),
            ];
        }

        $sportClassCode = $this->normalizeSportClassCode($result->sport_class);
        if ($sportClassCode === null) {
            return [null, "Sportklasse \"$result->sport_class\" nicht erkennbar"];
        }

        $sportClass = BaseTimeSportClass::where('code', $sportClassCode)->first();
        if (! $sportClass) {
            return [null, "keine Basiswert-Sportklasse \"$sportClassCode\" gefunden"];
        }

        $combo = "$category->code/$discipline->code/$sportClassCode";

        $baseTime = BaseTime::where('base_time_version_id', $version->id)
            ->where('base_time_category_id', $category->id)
            ->where('base_time_discipline_id', $discipline->id)
            ->where('base_time_sport_class_id', $sportClass->id)
            ->first();

        if (! $baseTime) {
            return [null, "kein Basiswert-Eintrag für $combo in dieser Version (Kategorie evtl. nicht importiert)"];
        }
        if ($baseTime->value_type === BaseTime::TYPE_NOT_APPLICABLE) {
            return [null, "$combo ist als \"nicht anwendbar\" (0,0) hinterlegt"];
        }
        if ($baseTime->value_centiseconds <= 0) {
            return [null, "$combo hat einen ungültigen Basiswert (0 oder negativ)"];
        }

        $points = 1000 * ($baseTime->value_centiseconds / $result->swim_time) ** 3;

        return [(int) round($points), ''];
    }

    // ── Zuordnung & Berechnung ────────────────────────────────────────────────

    /** "S9"/"SB9"/"SM9" → "S9" — die Basiswert-Tabelle führt keinen Stroke-Präfix. */
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
