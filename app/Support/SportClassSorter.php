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

    /**
     * Liefert nur die Sportklassen-Nummer ohne Präfix (z.B. "SB12" → 12), unabhängig davon, ob
     * es sich um S/SB/SM handelt — für eine Sportklassen-Nummer-zentrierte statt
     * Behinderungsgruppen-zentrierte Anzeige (Erik, 17.09.2026). Liefert null bei unerwartetem
     * Format oder null-Eingabe, damit der Aufrufer diese Fälle gesammelt behandeln kann.
     */
    public static function number(?string $sportClass): ?int
    {
        if ($sportClass === null) {
            return null;
        }

        if (preg_match('/^(S|SB|SM)(\d+)$/', strtoupper(trim($sportClass)), $matches)) {
            return (int) $matches[2];
        }

        return null;
    }
}
