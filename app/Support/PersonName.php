<?php

namespace App\Support;

/**
 * Personennamen aus Importen, die ganz in Großbuchstaben kommen (Team Manager, LENEX-Dateien schreiben Familiennamen
 * oft so: "NASSWETTER", "TAUPE-LEHNER"), in Groß- und Kleinschreibung bringen.
 *
 * Umgewandelt wird nur ein Name ohne einen einzigen Kleinbuchstaben — gemischte Schreibweisen ("De los Santos",
 * "McDonald") bleiben, wie sie sind. Jeder Namensteil bekommt einen großen Anfangsbuchstaben, auch nach Bindestrich,
 * Leerzeichen und Apostroph. Namenszusätze werden dabei ebenfalls groß ("VAN DER BERG" → "Van Der Berg").
 */
final class PersonName
{
    public static function normalize(?string $name): ?string
    {
        if ($name === null || ! self::isAllUppercase($name)) {
            return $name;
        }

        return (string) preg_replace_callback(
            '/\p{L}+/u',
            fn (array $m) => mb_strtoupper(mb_substr($m[0], 0, 1)).mb_strtolower(mb_substr($m[0], 1)),
            $name,
        );
    }

    /** Mindestens zwei Großbuchstaben und kein Kleinbuchstabe (Initialen wie "A." bleiben unberührt). */
    public static function isAllUppercase(string $name): bool
    {
        return preg_match('/\p{Lu}.*\p{Lu}/u', $name) === 1 && preg_match('/\p{Ll}/u', $name) === 0;
    }
}
