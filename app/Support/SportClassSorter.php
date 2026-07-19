<?php

namespace App\Support;

/**
 * Liefert einen Sortierschlüssel für Sportklassen-Codes (z.B. "S9", "SB12",
 * "SM3"), der numerisch statt alphabetisch sortiert — reine String-Sortierung würde "S10" fälschlich vor "S2"/"S9" einordnen (Erik,
 * 2026-07-19 bestätigt).
 */
class SportClassSorter
{
    public static function key(?string $sportClass): string
    {
        if ($sportClass === null) {
            return '';
        }

        if (preg_match('/^(S|SB|SM)(\d+)$/', strtoupper(trim($sportClass)), $matches)) {
            return sprintf('%s-%05d', $matches[1], (int) $matches[2]);
        }

        // Unerwartetes Format — unverändert zurückgeben, damit nichts verloren geht.
        return strtoupper(trim($sportClass));
    }
}
