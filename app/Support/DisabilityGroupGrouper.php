<?php

namespace App\Support;

use App\Models\SportClassGroup;
use App\Models\SportClassGroupMember;
use App\Models\StrokeType;
use Closure;
use Illuminate\Support\Collection;

/**
 * Gliedert eine Menge von Zeilen mit sport_class/stroke_type_id/distance-Feldern zuerst nach
 * Behinderungsgruppe (PI/VI/II/T21/HI, siehe SportClassGroup), darin nach Bewerb (Lage +
 * Distanz) — für eine übersichtlichere Anzeige/PDF-Ausgabe.
 *
 * Extrahiert aus QualifyingTimeListController (dort ursprünglich private für die Richtzeiten-
 * und Qualifikationsanzeige), damit Public\QualifyingTimeController (Phase 7) dieselbe Gliederung
 * nutzen kann, statt sie erneut auszuprogrammieren.
 *
 * Funktioniert generisch für jedes Model mit sport_class/stroke_type_id/distance/strokeType
 * (Qualification stellt diese per Proxy-Accessor auf die zugehörige Richtzeit bereit, siehe dort).
 */
class DisabilityGroupGrouper
{
    /**
     * @param  Collection  $items  z. B. Qualification[] oder QualifyingTime[]
     * @param  Closure  $sortWithin  Sortierschlüssel für Zeilen innerhalb eines Bewerbs-Abschnitts
     * @return Collection<int, array{group: ?SportClassGroup, strokes: Collection}>
     */
    public static function byGroupThenStroke(Collection $items, Closure $sortWithin): Collection
    {
        $memberMap = SportClassGroupMember::pluck('sport_class_group_id', 'sport_class');
        $groups = SportClassGroup::active()->orderBy('sort_order')->get();

        $sections = collect();

        foreach ($groups as $group) {
            $groupItems = $items->filter(
                fn ($item) => $memberMap->get($item->sport_class) === $group->id
            )->values();

            if ($groupItems->isNotEmpty()) {
                $sections->push(['group' => $group, 'strokes' => self::byStroke($groupItems, $sortWithin)]);
            }
        }

        // Sportklassen ohne zugeordnete Behinderungsgruppe landen gesammelt am Ende.
        $unassigned = $items->filter(
            fn ($item) => ! $memberMap->has($item->sport_class)
        )->values();

        if ($unassigned->isNotEmpty()) {
            $sections->push(['group' => null, 'strokes' => self::byStroke($unassigned, $sortWithin)]);
        }

        return $sections;
    }

    /**
     * Gliedert eine Teilmenge nach Bewerb (Lage + Distanz), in der üblichen
     * Wettkampf-Reihenfolge (Freistil, Rücken, Brust, Schmetterling, Lagen) und aufsteigend
     * nach Distanz.
     *
     * @return Collection<int, array{stroke: ?StrokeType, distance: int, items: Collection}>
     */
    public static function byStroke(Collection $items, Closure $sortWithin): Collection
    {
        $strokeOrder = ['FREE' => 1, 'BACK' => 2, 'BREAST' => 3, 'FLY' => 4, 'MEDLEY' => 5, 'IMRELAY' => 6];

        return collect($items
            ->groupBy(fn ($item) => "$item->stroke_type_id|$item->distance")
            ->map(fn ($group) => [
                'stroke' => $group->first()->strokeType,
                'distance' => $group->first()->distance,
                'items' => $group->sortBy($sortWithin)->values(),
            ])
            // Einzelner kombinierter Sortierschlüssel statt Mehrfachkriterien-Array, da sortBy()
            // mit mehreren Closures im Array sich nicht zuverlässig wie eine echte
            // Mehrfachsortierung verhalten hat (Lage vor Distanz).
            ->sortBy(fn ($s) => sprintf('%02d-%06d', $strokeOrder[$s['stroke']?->lenex_code] ?? 99, $s['distance']))
            ->values()
            ->all());
    }
}
