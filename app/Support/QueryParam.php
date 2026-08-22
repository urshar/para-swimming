<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Liest einen Query-Parameter gegen eine feste Werteliste, fällt bei Fehlen/Unbekanntem still
 * auf einen Standard zurück — dasselbe "unbekannt fällt still zurück"-Prinzip wie
 * PublicRecordFilter::fromQuery(). Extrahiert aus PointCalculatorController und
 * WpsPointCalculatorController, wo dasselbe `in_array(...) ? ... : $default`-Muster für
 * mode/course/gender identisch dupliziert war.
 */
final readonly class QueryParam
{
    /** @param  string[]  $allowed */
    public static function pick(Request $request, string $key, array $allowed, string $default): string
    {
        $value = $request->query($key);

        return in_array($value, $allowed, true) ? $value : $default;
    }
}
