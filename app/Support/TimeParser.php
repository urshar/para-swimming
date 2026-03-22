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
     * Hundertstelsekunden → lesbares Anzeigeformat "1:02.45" oder "58.32"
     */
    public static function display(int $centiseconds): string
    {
        $hours = intdiv($centiseconds, 360000);
        $minutes = intdiv($centiseconds % 360000, 6000);
        $seconds = intdiv($centiseconds % 6000, 100);
        $cs = $centiseconds % 100;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d.%02d', $hours, $minutes, $seconds, $cs);
        }
        if ($minutes > 0) {
            return sprintf('%d:%02d.%02d', $minutes, $seconds, $cs);
        }

        return sprintf('%d.%02d', $seconds, $cs);
    }
}
