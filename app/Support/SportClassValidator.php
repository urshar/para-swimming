<?php

namespace App\Support;

use App\Models\BaseTimeSportClass;
use Illuminate\Validation\ValidationException;

/**
 * Normalisierung und Prüfung von Sportklassen-Codes im Format
 * results.sport_class ("S9", "SB4", "SM3").
 *
 * Bis Phase 1 von wps-qualification lag diese Prüfung wortgleich in
 * QualifyingTimeService. Mit ChampionshipStandardService kam eine zweite
 * Stelle hinzu, die dieselbe Regel braucht — deshalb hier zusammengeführt.
 * Zwei Kopien derselben Prüfung würden auseinanderlaufen, sobald eine
 * Sportklassen-Konvention nachgezogen wird.
 *
 * Bewusst kein Wertobjekt: Aufrufer speichern den geprüften String direkt in
 * eine varchar-Spalte, ein Objekt brächte hier nur Auf- und Abbau.
 */
final class SportClassValidator
{
    /**
     * Prüft das Format "S9"/"SB9"/"SM9" und, dass die Klassenzahl in der
     * bestehenden, admin-verwalteten Basiswert-Sportklassen-Tabelle
     * (base_time_sport_classes) existiert — keine Hardcodierung der Zahlen,
     * keine doppelte Sportklassen-Verwaltung.
     *
     * @throws ValidationException
     */
    public static function normalize(string $sportClass): string
    {
        $upper = strtoupper(trim($sportClass));

        if (! preg_match('/^(S|SB|SM)(\d+)$/', $upper, $m)) {
            throw ValidationException::withMessages([
                'sport_class' => "Ungültige Sportklasse \"$sportClass\". Format: S, SB oder SM gefolgt von einer Zahl (z.B. S9, SB4, SM3).",
            ]);
        }

        $numericCode = 'S'.$m[2];

        if (! BaseTimeSportClass::query()->where('code', $numericCode)->exists()) {
            throw ValidationException::withMessages([
                'sport_class' => "Sportklasse \"$upper\" ist keiner bekannten Basiswert-Sportklasse ($numericCode) zugeordnet. Bitte zuerst unter Basiswerte → Sportklassen anlegen.",
            ]);
        }

        return $upper;
    }

    /**
     * Prüft ein Geschlechtskürzel im Format results.gender ("M"/"F").
     *
     * @throws ValidationException
     */
    public static function normalizeGender(string $gender): string
    {
        $upper = strtoupper(trim($gender));

        if (! in_array($upper, ['M', 'F'], true)) {
            throw ValidationException::withMessages([
                'gender' => "Ungültiges Geschlecht \"$gender\". Erlaubt sind M und F.",
            ]);
        }

        return $upper;
    }
}
