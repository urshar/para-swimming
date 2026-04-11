<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * RelayClassValidator
 *
 * Validiert Staffel-Sportklassen-Kombinationen nach Para-Swimming Regeln
 * und ermittelt die korrekte Staffelklasse aus den Athleten-Sportklassen.
 *
 * ── Staffelklassen und Regeln ──────────────────────────────────────────────
 *
 *  S21  (Trisomie):    Alle Mitglieder müssen S21 sein.
 *  S14  (Intellectual): Mitglieder dürfen S14 oder S21 sein.
 *  S15  (Deaf):        Alle Mitglieder müssen S15 sein.
 *  S49  (Visual):      Alle Mitglieder müssen S11, S12 oder S13 sein.
 *  S20  (Physical):    Summe der Klassennummern ≤ 20.
 *  S34  (Physical):    Summe der Klassennummern > 20 und ≤ 34.
 *  > 34:               Ungültige Staffel → null.
 *
 *  Alle Athleten müssen vom selben Verein sein (wird im RecordCheckerService
 *  sichergestellt, da Entries per club_id gefiltert werden).
 *
 * ── Jugendrekord ──────────────────────────────────────────────────────────
 *  alle Mitglieder mit bekanntem Geburtsdatum müssen Jahrgangsalter ≤ 18 haben.
 */
class RelayClassValidator
{
    private const array VISUAL_CLASSES = [11, 12, 13];

    private const array INTELLECTUAL_CLASSES = [14, 21];

    /**
     * Gibt true zurück, wenn die Staffelklasse nur national gültig ist.
     *
     * S21 (Trisomie) existiert international nicht — World Para Swimming
     * kennt diese Klasse nicht. Rekorde dürfen daher nur auf nationaler
     * und regionaler Ebene (AUT, AUT.JR, AUT.WBSV etc.) gesetzt werden,
     * niemals als WR, ER oder OR.
     */
    public function isNationalOnlyClass(string $relayClass): bool
    {
        return $relayClass === 'S21';
    }

    /**
     * ergibt, oder null, wenn die Kombination ungültig ist.
     *
     * $memberClasses: Array von Sportklassen-Strings, z.B. ['S12', 'S13', 'S11']
     */
    public function resolveRelayClass(array $memberClasses): ?string
    {
        if (empty($memberClasses)) {
            return null;
        }

        $numbers = [];
        foreach ($memberClasses as $class) {
            if (preg_match('/^S(\d+)$/', $class, $m)) {
                $numbers[] = (int) $m[1];
            } else {
                // Mischung mit SB/SM → ungültig
                return null;
            }
        }

        if (empty($numbers)) {
            return null;
        }

        $unique = array_unique($numbers);

        // S21: alle müssen S21 sein
        if (count($unique) === 1 && $unique[0] === 21) {
            return 'S21';
        }

        // S14: nur S14 und/oder S21 erlaubt
        if (empty(array_diff($numbers, self::INTELLECTUAL_CLASSES))) {
            return 'S14';
        }

        // S15: alle müssen S15 sein
        if (count($unique) === 1 && $unique[0] === 15) {
            return 'S15';
        }

        // S49: nur S11, S12, S13 erlaubt
        if (empty(array_diff($numbers, self::VISUAL_CLASSES))) {
            return 'S49';
        }

        // S20 / S34: Physical — nur S1–S10, Summe der Klassennummern
        // Sonderklassen (S11-S15, S21) dürfen nicht dabei sein
        $specialClasses = array_merge(self::VISUAL_CLASSES, self::INTELLECTUAL_CLASSES, [15]);
        $physicalValid = empty(array_intersect($numbers, $specialClasses))
            && empty(array_filter($numbers, fn ($n) => $n < 1 || $n > 10));

        if ($physicalValid) {
            $sum = array_sum($numbers);

            if ($sum <= 20) {
                return 'S20';
            }

            if ($sum <= 34) {
                return 'S34';
            }

            return null; // Summe > 34 → ungültig
        }

        return null;
    }

    /**
     * Ermittelt die Sportklassen der Staffelmitglieder aus Entries.
     *
     * Priorität:
     * 1. Entry.sport_class (direkt gespeichert)
     * 2. AthleteSportClass des Athleten passend zum Stroke des Events
     */
    public function extractMemberClasses(Collection $entries, $event): array
    {
        $strokeUpper = strtoupper($event->strokeType?->lenex_code ?? '');
        $category = match (true) {
            $strokeUpper === 'BREAST' => 'SB',
            in_array($strokeUpper, ['MEDLEY', 'IMRELAY']) => 'SM',
            default => 'S',
        };

        $classes = [];
        foreach ($entries as $entry) {
            if ($entry->sport_class) {
                $classes[] = $entry->sport_class;

                continue;
            }
            if ($entry->athlete) {
                $sc = $entry->athlete->sportClasses->firstWhere('category', $category);
                if ($sc) {
                    $classes[] = $category.$sc->class_number;
                }
            }
        }

        return $classes;
    }

    /**
     * Prüft, ob alle Staffelmitglieder mit bekanntem Geburtsdatum Jugendliche sind.
     * Gibt false zurück, wenn kein Mitglied ein Geburtsdatum hat.
     *
     * Regel: Jahrgangsalter = Wettkampfjahr − Geburtsjahr ≤ 18
     */
    public function isJuniorRelay(Collection $entries, int $meetYear): bool
    {
        $datedEntries = $entries->filter(fn ($e) => $e->athlete?->birth_date !== null);

        if ($datedEntries->isEmpty()) {
            return false;
        }

        return $datedEntries->every(function ($entry) use ($meetYear) {
            return ($meetYear - (int) $entry->athlete->birth_date->format('Y')) <= 18;
        });
    }
}
