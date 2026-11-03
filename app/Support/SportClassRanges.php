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
            $parts[] = self::compress($prefix, $numbers, false);
        }

        return implode(', ', $parts);
    }

    /**
     * Wie format(), aber mit festem Präfix der Lage (S/SB/SM) für alle Klassen und
     * Bereichsgrenzen zusätzlich an den Behinderungsgruppen (1-10 körperlich,
     * 11-13 Sehbehinderung, 14 intellektuell, 15-20, ab 21):
     *
     *   "1 2 3 4 5 6 7 8 9 10 11 12 13 14" mit "S" → "S1-S10, S11-S13, S14"
     *
     * Ein vorhandenes Präfix der Einträge wird ignoriert.
     */
    public static function formatGrouped(?string $raw, string $prefix): string
    {
        $numbers = [];
        foreach (preg_split('/[\s,;]+/', trim((string) $raw), flags: PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (preg_match('/^[A-Za-z]*(\d+)$/', $token, $m)) {
                $numbers[] = (int) $m[1];
            }
        }
        $numbers = array_values(array_unique($numbers));
        sort($numbers);

        return self::compress($prefix, $numbers, true);
    }

    /**
     * Komprimiert aufsteigend sortierte, deduplizierte Zahlen einer Präfixgruppe
     * zu Bereichen ("1-7, 9-15, 21"); mit $byImpairment brechen die Bereiche auch
     * an den Grenzen der Behinderungsgruppen.
     *
     * @param  list<int>  $numbers
     */
    private static function compress(string $prefix, array $numbers, bool $byImpairment): string
    {
        $ranges = [];
        $start = null;
        $prev = null;

        foreach ($numbers as $n) {
            if ($start === null) {
                $start = $prev = $n;

                continue;
            }

            if ($n === $prev + 1 && (! $byImpairment || self::impairmentGroup($n) === self::impairmentGroup($prev))) {
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

    /** Behinderungsgruppe einer Klassennummer: 1-10, 11-13, 14, 15-20, ab 21. */
    private static function impairmentGroup(int $class): int
    {
        return match (true) {
            $class <= 10 => 1,
            $class <= 13 => 2,
            $class === 14 => 3,
            $class <= 20 => 4,
            default => 5,
        };
    }

    private static function range(string $prefix, int $start, int $end): string
    {
        return $start === $end
            ? $prefix.$start
            : $prefix.$start.'-'.$prefix.$end;
    }
}
