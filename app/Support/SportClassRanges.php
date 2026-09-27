<?php

namespace App\Support;

/**
 * Fasst die (leerzeichengetrennte) Sportklassen-Liste eines Bewerbs zu kompakten
 * Bereichen zusammen, damit die Disziplin-Auswahl nicht jede Klasse einzeln
 * auflistet:
 *
 *   "S1 S2 S3 S4 S5 S6 S7 S9 S10 S11 S12 S13 S14 S15 S21" → "S1-S7, S9-S15, S21"
 *
 * Lücken brechen einen Bereich. Das Präfix (S/SB/SM) wird je Gruppe erhalten;
 * reine Zahlen bleiben ohne Präfix. Kein Null-Padding (S1, nicht S01).
 */
final class SportClassRanges
{
    public static function format(?string $raw): string
    {
        $tokens = preg_split('/\s+/', trim((string) $raw), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        // Nach Präfix gruppieren, Reihenfolge des ersten Auftretens erhalten.
        $groups = [];
        foreach ($tokens as $token) {
            if (! preg_match('/^([A-Za-z]*)(\d+)$/', $token, $m)) {
                continue; // unerwartetes Format überspringen
            }
            $prefix = strtoupper($m[1]);
            $groups[$prefix][] = (int) $m[2];
        }

        $parts = [];
        foreach ($groups as $prefix => $numbers) {
            $numbers = array_values(array_unique($numbers));
            sort($numbers);
            $parts[] = self::compress($prefix, $numbers);
        }

        return implode(', ', $parts);
    }

    /**
     * Komprimiert aufsteigend sortierte, deduplizierte Zahlen einer Präfixgruppe
     * zu Bereichen ("1-7, 9-15, 21").
     *
     * @param  list<int>  $numbers
     */
    private static function compress(string $prefix, array $numbers): string
    {
        $ranges = [];
        $start = null;
        $prev = null;

        foreach ($numbers as $n) {
            if ($start === null) {
                $start = $prev = $n;

                continue;
            }

            if ($n === $prev + 1) {
                $prev = $n;

                continue;
            }

            $ranges[] = self::range($prefix, $start, $prev);
            $start = $prev = $n;
        }

        if ($start !== null) {
            $ranges[] = self::range($prefix, $start, $prev);
        }

        return implode(', ', $ranges);
    }

    private static function range(string $prefix, int $start, int $end): string
    {
        return $start === $end
            ? $prefix.$start
            : $prefix.$start.'-'.$prefix.$end;
    }
}
