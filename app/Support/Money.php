<?php

namespace App\Support;

/**
 * Geldbeträge für die Meldegelder: intern als ganze Cent (wie LENEX FEE@value), Eingabe und Anzeige in Euro
 * mit Komma ("10,50" ↔ 1050). Ganze Cent statt float vermeiden Rundungsfehler beim Summieren.
 */
final readonly class Money
{
    /** Gültige Euro-Eingabe: bis 99.999 Euro, optional mit "," oder "." und ein bis zwei Nachkommastellen. */
    public const string INPUT_PATTERN = '/^\d{1,5}([.,]\d{1,2})?$/';

    /** "10" / "10,5" / "10.50" → 1000 / 1050 / 1050; leer → null. Erwartet bereits validierte Eingabe. */
    public static function toCents(?string $euro): ?int
    {
        $euro = trim((string) $euro);
        if ($euro === '') {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', str_replace(',', '.', $euro), 2), 2, '0');

        return (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    /** 1050 → "10,50" (Formularwert, ohne Währungszeichen); null → "". */
    public static function toInput(?int $cents): string
    {
        return $cents === null ? '' : number_format($cents / 100, 2, ',', '');
    }

    /** 123450 → "1.234,50 €". */
    public static function format(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.').' €';
    }
}
