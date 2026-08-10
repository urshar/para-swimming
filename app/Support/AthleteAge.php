<?php

namespace App\Support;

use App\Models\Athlete;

/**
 * Alter und Geburtsjahr eines Athleten nach der Stichtagsregel des Schwimmsports.
 *
 * Maßgeblich ist nicht das Alter am Wettkampftag, sondern das am **31. Dezember** des
 * Bezugsjahres erreichte. Wer am 31. Dezember Geburtstag hat, zählt das ganze Jahr über
 * bereits als ein Jahr älter. Dieselbe Regel gilt im Projekt schon für die Altersgruppen der
 * Cup-Wertung (`GroupResolverService::resolveAgeGroup()`).
 *
 * Als eigene Stelle, weil drei Ansichten sie brauchen — Qualifikanten, Förderansicht und
 * Auswahl-Rangliste —, und nur die ersten beiden mit einem `QualificationAthleteSummary`
 * arbeiten. Dreimal ausprogrammiert liefe die Regel früher oder später auseinander.
 */
final class AthleteAge
{
    public static function birthYear(?Athlete $athlete): ?int
    {
        return $athlete?->birth_date?->year;
    }

    /**
     * Alter zum 31. Dezember des Bezugsjahres.
     *
     * Bezugsjahr ist das Jahr der Meisterschaft, nicht das laufende: Eine Auswertung der
     * EM 2026 soll auch 2028 dieselben Altersangaben zeigen.
     */
    public static function atEndOf(?Athlete $athlete, int $year): ?int
    {
        $geburtsjahr = self::birthYear($athlete);

        return $geburtsjahr === null ? null : $year - $geburtsjahr;
    }

    /**
     * Alter und Geburtsjahr als Text, z.B. "26 Jahre (2000)".
     *
     * Beides nebeneinander, weil das Alter jedes Jahr wechselt und das Geburtsjahr nicht: Bei
     * einem ausgedruckten Blatt ohne Datumsangabe ist nur am Geburtsjahr noch erkennbar, um
     * wen es geht.
     *
     * Liefert null ohne Geburtsdatum, statt "0 Jahre" oder einen leeren Klammerausdruck zu
     * zeigen — beides sähe nach einem Fehler aus, wo schlicht eine Angabe fehlt.
     */
    public static function label(?Athlete $athlete, int $year): ?string
    {
        $alter = self::atEndOf($athlete, $year);

        return $alter === null ? null : sprintf('%d Jahre (%d)', $alter, self::birthYear($athlete));
    }
}
