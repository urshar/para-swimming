<?php

namespace App\Services;

use App\Models\BaseTime;
use App\Models\BaseTimeCategory;
use App\Models\BaseTimeDerivationRule;
use App\Models\BaseTimeVersion;
use RuntimeException;

/**
 * BaseTimeCalculationService
 *
 * Berechnet die CALCULATED-Basiswerte einer Version aus den MANUAL-Werten und den
 * base_time_derivation_rules neu — die "Live-Neuberechnung" aus Schritt 8.
 *
 * Ablauf pro Kategorie:
 *   1. Start-Matrix aus allen MANUAL-Werten der Kategorie (Bewerb × Sportklasse).
 *   2. Iterativ über alle Regeln: Durchschnitts-Wachstumsfaktor aus den Sportklassen berechnen,
 *      die für das (ggf. per Override abweichende) Ratio-Bewerbs-Paar bereits einen bekannten
 *      Wert haben — das können auch bereits in dieser Iteration berechnete Werte sein.
 *      Damit fehlende Werte im eigentlichen Bewerbs-Paar auffüllen.
 *   3. Wiederholen, bis sich nichts mehr ändert (löst Ketten wie 400FR → 800FR → 1500FR auf).
 *
 * Kategorien mit Cross-Kategorie-Ratio-Referenz (z.B. LC Mixed → LC Men) werden erst verarbeitet,
 * nachdem die referenzierte Kategorie fertig berechnet ist (topologische Sortierung).
 *
 * Es werden ausschließlich bestehende base_times-Zeilen mit value_type = CALCULATED aktualisiert.
 * MANUAL und NOT_APPLICABLE bleiben unangetastet. Kombinationen, die sich nicht auflösen lassen,
 * werden als Warnung gemeldet statt stillschweigend übersprungen.
 */
class BaseTimeCalculationService
{
    private const int MAX_ITERATIONS = 20;

    /** Kategorie → fertig berechnete Matrix [disciplineId][sportClassId] → centiseconds. */
    private array $resolvedMatrices = [];

    // ── Öffentliche API ───────────────────────────────────────────────────────

    /**
     * Berechnet alle Kategorien einer Version neu (z.B. nach dem Import als Konsistenz-Check).
     *
     * @throws RuntimeException wenn die Ratio-Referenzen zwischen Kategorien einen Zyklus bilden
     */
    public function recalculateVersion(BaseTimeVersion $version): array
    {
        $categoryIds = BaseTime::where('base_time_version_id', $version->id)
            ->distinct()
            ->pluck('base_time_category_id')
            ->all();

        return $this->recalculateCategories($version, $categoryIds);
    }

    // ── Kategorie-Abhängigkeiten ──────────────────────────────────────────────

    /**
     * Berechnet eine Kategorie neu, nachdem sich einer ihrer MANUAL-Werte geändert hat —
     * inklusive aller Kategorien, die deren Ratio-Werte referenzieren (z.B. LC Mixed nach LC Men).
     *
     * @throws RuntimeException wenn die Ratio-Referenzen zwischen Kategorien einen Zyklus bilden
     */
    public function recalculateCategory(BaseTimeVersion $version, BaseTimeCategory $category): array
    {
        $dependents = $this->dependentCategoryIds($category->id);
        $categoryIds = array_unique([$category->id, ...$dependents]);

        return $this->recalculateCategories($version, $categoryIds);
    }

    /**
     * @param  int[]  $categoryIds
     *
     * @throws RuntimeException wenn die Ratio-Referenzen zwischen den Kategorien einen Zyklus bilden
     */
    private function recalculateCategories(BaseTimeVersion $version, array $categoryIds): array
    {
        $this->resolvedMatrices = [];

        $order = $this->topologicalOrder($categoryIds);
        $summary = [];

        foreach ($order as $categoryId) {
            $summary[$categoryId] = $this->recalculateSingleCategory($version, $categoryId);
        }

        return $summary;
    }

    // ── Kern-Algorithmus ──────────────────────────────────────────────────────

    /**
     * Sortiert die übergebenen Kategorien so, dass eine Ratio-Referenz-Kategorie stets vor den
     * Kategorien steht, die sie referenzieren (Kahn-Algorithmus).
     *
     * @param  int[]  $categoryIds
     * @return int[]
     *
     * @throws RuntimeException wenn die Ratio-Referenzen einen Zyklus bilden
     */
    private function topologicalOrder(array $categoryIds): array
    {
        $relevant = array_flip($categoryIds);

        $dependsOn = []; // categoryId ⇒ [categoryIds, von denen abhängig]
        foreach ($categoryIds as $id) {
            $dependsOn[$id] = [];
        }

        BaseTimeDerivationRule::query()
            ->whereIn('base_time_category_id', $categoryIds)
            ->whereNotNull('ratio_reference_category_id')
            ->get(['base_time_category_id', 'ratio_reference_category_id'])
            ->each(function (BaseTimeDerivationRule $rule) use (&$dependsOn, $relevant) {
                $ref = $rule->ratio_reference_category_id;
                if ($ref !== $rule->base_time_category_id && isset($relevant[$ref])) {
                    $dependsOn[$rule->base_time_category_id][$ref] = $ref;
                }
            });

        $ordered = [];
        $visited = [];
        $visiting = [];

        $visit = function (int $id) use (&$visit, &$ordered, &$visited, &$visiting, $dependsOn) {
            if (isset($visited[$id])) {
                return;
            }
            if (isset($visiting[$id])) {
                throw new RuntimeException('Zyklische Ratio-Referenz zwischen Basiswert-Kategorien erkannt.');
            }

            $visiting[$id] = true;
            foreach ($dependsOn[$id] ?? [] as $dependencyId) {
                $visit($dependencyId);
            }
            unset($visiting[$id]);

            $visited[$id] = true;
            $ordered[] = $id;
        };

        foreach ($categoryIds as $id) {
            $visit($id);
        }

        return $ordered;
    }

    private function recalculateSingleCategory(BaseTimeVersion $version, int $categoryId): array
    {
        $matrix = $this->loadManualMatrix($version->id, $categoryId);
        $sportClassIds = $this->loadRelevantSportClassIds($version->id, $categoryId);
        $rules = BaseTimeDerivationRule::where('base_time_category_id', $categoryId)->get();

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $changed = false;

            foreach ($rules as $rule) {
                if ($this->applyRule($rule, $categoryId, $matrix, $sportClassIds)) {
                    $changed = true;
                }
            }

            if (! $changed) {
                break;
            }
        }

        $this->resolvedMatrices[$categoryId] = $matrix;

        return $this->persist($version->id, $categoryId, $matrix);
    }

    /** @return array<int, array<int, int>> [disciplineId][sportClassId] => centiseconds */
    private function loadManualMatrix(int $versionId, int $categoryId): array
    {
        $matrix = [];

        BaseTime::query()
            ->where('base_time_version_id', $versionId)
            ->where('base_time_category_id', $categoryId)
            ->where('value_type', BaseTime::TYPE_MANUAL)
            ->get(['base_time_discipline_id', 'base_time_sport_class_id', 'value_centiseconds'])
            ->each(function (BaseTime $row) use (&$matrix) {
                $matrix[$row->base_time_discipline_id][$row->base_time_sport_class_id] = $row->value_centiseconds;
            });

        return $matrix;
    }

    /** Sportklassen, die für diese Kategorie/Version tatsächlich Zeilen haben (aus dem Import). */
    private function loadRelevantSportClassIds(int $versionId, int $categoryId): array
    {
        return BaseTime::query()
            ->where('base_time_version_id', $versionId)
            ->where('base_time_category_id', $categoryId)
            ->distinct()
            ->pluck('base_time_sport_class_id')
            ->all();
    }

    // ── Daten laden / persistieren ────────────────────────────────────────────

    /** Wendet eine Regel auf alle Sportklassen an. Gibt zurück, ob sich die Matrix verändert hat. */
    private function applyRule(
        BaseTimeDerivationRule $rule,
        int $categoryId,
        array &$matrix,
        array $sportClassIds
    ): bool {
        $ratioCategoryId = $rule->ratio_reference_category_id ?? $categoryId;
        $ratioMatrix = $ratioCategoryId === $categoryId ? $matrix : ($this->resolvedMatrices[$ratioCategoryId] ?? null);

        if ($ratioMatrix === null) {
            return false; // Referenzierte Kategorie noch nicht berechnet — sollte durch die Topo-Sortierung nicht vorkommen.
        }

        $ratioShorterId = $rule->ratio_shorter_discipline_id ?? $rule->shorter_discipline_id;
        $ratioLongerId = $rule->ratio_longer_discipline_id ?? $rule->longer_discipline_id;

        $ratio = $this->averageGrowthRatio($ratioMatrix, $ratioShorterId, $ratioLongerId, $sportClassIds);
        if ($ratio === null) {
            return false;
        }

        $changed = false;

        foreach ($sportClassIds as $sportClassId) {
            $shorterValue = $matrix[$rule->shorter_discipline_id][$sportClassId] ?? null;
            $longerValue = $matrix[$rule->longer_discipline_id][$sportClassId] ?? null;

            if ($shorterValue !== null && $longerValue === null) {
                $matrix[$rule->longer_discipline_id][$sportClassId] = (int) round($shorterValue * (1 + $ratio));
                $changed = true;
            } elseif ($longerValue !== null && $shorterValue === null) {
                $matrix[$rule->shorter_discipline_id][$sportClassId] = (int) round($longerValue / (1 + $ratio));
                $changed = true;
            }
        }

        return $changed;
    }

    /** Durchschnitt von (länger - kürzer) / kürzer über alle Sportklassen, die beide Werte kennen. */
    private function averageGrowthRatio(array $matrix, int $shorterId, int $longerId, array $sportClassIds): ?float
    {
        $sum = 0.0;
        $count = 0;

        foreach ($sportClassIds as $sportClassId) {
            $shorter = $matrix[$shorterId][$sportClassId] ?? null;
            $longer = $matrix[$longerId][$sportClassId] ?? null;

            if ($shorter === null || $longer === null || $shorter <= 0) {
                continue;
            }

            $sum += ($longer - $shorter) / $shorter;
            $count++;
        }

        return $count > 0 ? $sum / $count : null;
    }

    /** Aktualisiert alle CALCULATED-Zeilen aus der Matrix. Meldet nicht auflösbare Kombinationen. */
    private function persist(int $versionId, int $categoryId, array $matrix): array
    {
        $rows = BaseTime::query()
            ->where('base_time_version_id', $versionId)
            ->where('base_time_category_id', $categoryId)
            ->where('value_type', BaseTime::TYPE_CALCULATED)
            ->get();

        $updated = 0;
        $unresolved = [];

        foreach ($rows as $row) {
            $value = $matrix[$row->base_time_discipline_id][$row->base_time_sport_class_id] ?? null;

            if ($value === null) {
                $unresolved[] = [
                    'discipline_id' => $row->base_time_discipline_id,
                    'sport_class_id' => $row->base_time_sport_class_id,
                ];

                continue;
            }

            if ($row->value_centiseconds !== $value) {
                $row->update(['value_centiseconds' => $value]);
            }
            $updated++;
        }

        return ['updated' => $updated, 'unresolved' => $unresolved];
    }

    /** Kategorien, die (direkt oder transitiv) den Ratio-Wert dieser Kategorie referenzieren. */
    private function dependentCategoryIds(int $categoryId): array
    {
        $edges = $this->reverseDependencyEdges();
        $result = [];
        $queue = [$categoryId];

        while ($queue !== []) {
            $current = array_pop($queue);
            foreach ($edges[$current] ?? [] as $dependent) {
                if (! in_array($dependent, $result, true)) {
                    $result[] = $dependent;
                    $queue[] = $dependent;
                }
            }
        }

        return $result;
    }

    /** [categoryId ⇒ [Kategorien, die diese Kategorie als Ratio-Referenz nutzen]]. */
    private function reverseDependencyEdges(): array
    {
        $edges = [];

        BaseTimeDerivationRule::query()
            ->whereNotNull('ratio_reference_category_id')
            ->get(['base_time_category_id', 'ratio_reference_category_id'])
            ->each(function (BaseTimeDerivationRule $rule) use (&$edges) {
                if ($rule->ratio_reference_category_id !== $rule->base_time_category_id) {
                    $edges[$rule->ratio_reference_category_id][] = $rule->base_time_category_id;
                }
            });

        return $edges;
    }
}
