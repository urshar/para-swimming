<?php

namespace App\Support;

/**
 * Aufbereitung der Sportklasse für die WPS-Punkteberechnung.
 *
 * Bewusst an EINER Stelle: Die Zuordnung wird sowohl beim Berechnen (WpsPointCalculator) als
 * auch beim Ermitteln der Umrechnungsfaktoren (WpsScmFactorCalibrationService) gebraucht.
 * Lagen beide Stellen auseinander, entstanden Faktoren für Klassen, die zur Rechenzeit gar
 * nicht mehr abgefragt wurden — und die zugehörigen Athleten fehlten in der Stichprobe der
 * Zielklasse.
 */
final readonly class WpsSportClass
{
    /**
     * Bringt die Sportklasse auf ein einheitliches Format und weist alles ab, wofür es keine
     * WPS-Parameter geben kann.
     *
     * Zugelassen sind die Kategorien S, SB und SM mit den Nummern 1–14 sowie 21. Abgewiesen
     * werden die reinen Staffelklassen (S20, S34, S49), die nicht-numerischen nationalen
     * Klassen (GER.AB, GER.GB) und alles Übrige.
     *
     * Die Reihenfolge der Alternation ist entscheidend: Mit /^(S|SB|SM)/ liefert "SB9" die
     * Kategorie "S", da reguläre Ausdrücke den ersten passenden Zweig nehmen. Die längeren
     * Präfixe müssen deshalb vorn stehen.
     */
    public static function normalize(?string $sportClass): ?string
    {
        if ($sportClass === null) {
            return null;
        }

        $bereinigt = strtoupper(str_replace(' ', '', trim($sportClass)));

        return preg_match('/^(SB|SM|S)([1-9]|1[0-4]|21)$/', $bereinigt) === 1
            ? $bereinigt
            : null;
    }

    /**
     * Bildet die Klasse auf jene ab, für die WPS-Parameter existieren.
     *
     * S21, SB21 und SM21 sind nationale Sportklassen, deren Athletinnen und Athleten fachlich
     * zur Gruppe 14 gehören. World Para Swimming kennt sie nicht (Spec [S3]).
     *
     * Die Abbildung wirkt ausschließlich für Parametersuche und Faktorermittlung —
     * results.sport_class bleibt unverändert, und Anzeigen zeigen weiterhin die tatsächlich
     * geschwommene Klasse.
     */
    public static function mapToWps(?string $sportClass): ?string
    {
        $normalisiert = self::normalize($sportClass);

        if ($normalisiert === null) {
            return null;
        }

        return preg_match('/^(SB|SM|S)21$/', $normalisiert, $treffer) === 1
            ? $treffer[1].'14'
            : $normalisiert;
    }

    /** Kategorie ohne Nummer: "SB8" → "SB". Setzt eine normalisierte Klasse voraus. */
    public static function category(string $sportClass): string
    {
        preg_match('/^(SB|SM|S)/', $sportClass, $treffer);

        return $treffer[1];
    }
}
