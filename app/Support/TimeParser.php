<?php

namespace App\Support;

/**
 * TimeParser
 *
 * Gemeinsame Zeit-Konvertierung für Models und Services.
 * LENEX Zeitformat "HH:MM:SS.ss" ↔ Hundertstelsekunden (int)
 */
class TimeParser
{
    /**
     * LENEX Zeitformat "HH:MM:SS.ss" → Hundertstelsekunden
     * "NT" oder leer → null
     */
    public static function parse(string $time): ?int
    {
        $normalized = self::normalize($time);
        if ($normalized === null || $normalized === 'NT') {
            return null;
        }

        // Format HH:MM:SS.ss
        if (preg_match('/^(\d+):(\d{2}):(\d{2})\.(\d{2})$/', $normalized, $m)) {
            return ((int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3]) * 100 + (int) $m[4];
        }

        // Kurzformat MM:SS.ss
        if (preg_match('/^(\d+):(\d{2})\.(\d{2})$/', $normalized, $m)) {
            return ((int) $m[1] * 60 + (int) $m[2]) * 100 + (int) $m[3];
        }

        return null;
    }

    /**
     * Normalisiert einen Zeitstring: trim + uppercase.
     * Gibt null zurück, wenn leer.
     */
    public static function normalize(string $time): ?string
    {
        $trimmed = trim($time);

        return $trimmed === '' ? null : strtoupper($trimmed);
    }

    /**
     * Hundertstelsekunden → LENEX Zeitformat "HH:MM:SS.ss"
     */
    public static function format(int $centiseconds): string
    {
        $hours = intdiv($centiseconds, 360000);
        $minutes = intdiv($centiseconds % 360000, 6000);
        $seconds = intdiv($centiseconds % 6000, 100);
        $cs = $centiseconds % 100;

        return sprintf('%02d:%02d:%02d.%02d', $hours, $minutes, $seconds, $cs);
    }

    /**
     * Hundertstelsekunden → lesbares Anzeigeformat mit führenden Nullen.
     *
     * Beispiele:
     *   34,45 cs  →  "00:34.45"
     *   83,22 cs  →  "01:23.22"
     *   3789,00 cs → "01:03:09.00"
     *
     * Minuten werden immer zweistellig mit führender Null angezeigt (00:34.45),
     * Stunden, nur wenn > 0.
     */
    public static function display(int $centiseconds): string
    {
        $hours = intdiv($centiseconds, 360000);
        $minutes = intdiv($centiseconds % 360000, 6000);
        $seconds = intdiv($centiseconds % 6000, 100);
        $cs = $centiseconds % 100;

        if ($hours > 0) {
            return sprintf('%02d:%02d:%02d.%02d', $hours, $minutes, $seconds, $cs);
        }

        // Immer MM:SS.ss mit führender Null — auch "00:34.45"
        return sprintf('%02d:%02d.%02d', $minutes, $seconds, $cs);
    }

    /**
     * Bereinigt einen Datums-String für die Datenbank.
     * Gibt null zurück für leere, ungültige oder nicht existierende Daten.
     *
     * Beispiele:
     *   ''           → null
     *   '0000-00-00' → null
     *   '2024-05-05' → '2024-05-05'
     */
    public static function sanitizeDate(?string $date): ?string
    {
        if (! $date || $date === '0000-00-00') {
            return null;
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        return $date;
    }
}
