<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\Club;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ImportSuggestionService — gemeinsame Abgleich- und Vorschlagslogik der Importe (Rekord-Import, LENEX-Import).
 *
 * - Namen werden normalisiert verglichen (Kleinschreibung, Leerraum um Bindestriche).
 * - Lizenzen werden ohne Leerzeichen verglichen: In der Datenbank steht oft "W - 1653", in LENEX-Dateien "W-1653".
 * - Vorschläge werden nie automatisch übernommen, sondern auf den Klärungsseiten zur Auswahl angeboten.
 */
final readonly class ImportSuggestionService
{
    /** Athlet mit dieser Lizenz (bzw. SDMS-ID), Leerzeichen werden ignoriert. Portabel: REPLACE gibt es in MySQL und SQLite. */
    public function athleteByLicense(string $column, string $license): ?Athlete
    {
        $normalized = self::normalizeLicense($license);
        if ($normalized === '' || ! in_array($column, ['license', 'license_ipc'], true)) {
            return null;
        }

        return Athlete::whereRaw("REPLACE($column, ' ', '') = ?", [$normalized])
            ->whereNull('deleted_at')
            ->first();
    }

    public static function normalizeLicense(string $license): string
    {
        return preg_replace('/\s+/u', '', $license);
    }

    /**
     * Normalisiert einen Namen für den Vergleich: Unicode-Kleinschreibung, Leerraum um
     * Bindestriche entfernt ("Weber - Treiber" → "weber-treiber"), sonstiger Leerraum kollabiert.
     */
    public function normalizeName(string $name): string
    {
        $lower = mb_strtolower(trim($name));
        $lower = preg_replace('/\s*-\s*/u', '-', $lower);

        return preg_replace('/\s+/u', ' ', $lower);
    }

    /**
     * Vereins-Kandidaten für einen nicht gefundenen Verein. Nation wird bewusst ignoriert. Zwei Stufen:
     *   - stark: normalisierter Name/Kurzname exakt, oder Code exakt (case-insensitiv)
     *   - schwach: mehrwortiges Wortgrenzen-Präfix ("Flying Flippers Schwimmteam" ↔ "Flying Flippers")
     *
     * @param  Collection<int, Club>  $clubs  alle Vereine (vom Aufrufer einmal geladen)
     * @return Collection<int, Club>
     */
    public function suggestClubs(string $code, string $name, Collection $clubs): Collection
    {
        $normName = $this->normalizeName($name);
        $normCode = mb_strtolower(trim($code));

        if ($normName === '' && $normCode === '') {
            return collect();
        }

        return $clubs->filter(function (Club $c) use ($normName, $normCode) {
            $cName = $this->normalizeName((string) $c->name);
            $cShort = $this->normalizeName((string) $c->short_name);
            $cCode = mb_strtolower(trim((string) $c->code));

            // stark: exakter Name/Kurzname oder exakter Code
            if ($normName !== '' && ($cName === $normName || ($cShort !== '' && $cShort === $normName))) {
                return true;
            }
            if ($normCode !== '' && $cCode !== '' && $cCode === $normCode) {
                return true;
            }

            // schwach: mehrwortiges Wortgrenzen-Präfix (Name oder Kurzname)
            foreach ([$cName, $cShort] as $candidate) {
                if ($candidate !== '' && $this->isWordBoundaryPrefixMatch($normName, $candidate)) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    /**
     * Athleten-Kandidaten für einen nicht exakt gefundenen Athleten (Dateien tragen bei unbekanntem Tag/Monat oft
     * "JJJJ-01-01" oder ein leeres bzw. abweichendes Geburtsdatum).
     *
     * - leeres Geburtsdatum: Name + Geschlecht (alle Jahrgänge)
     * - sonst: Name + Geschlecht + Geburtsjahr, portabel via SUBSTR(birth_date,1,4) (kein YEAR())
     *
     * @return Collection<int, Athlete>
     */
    public function suggestAthletes(string $lastName, string $firstName, string $birthDate, string $gender): Collection
    {
        if (! $lastName || ! $firstName) {
            return collect();
        }

        $normLast = $this->normalizeName($lastName);
        $normFirst = $this->normalizeName($firstName);

        // Nach Geschlecht (+ Geburtsjahr, wenn vorhanden) vorfiltern, dann den Namen normalisiert
        // in PHP vergleichen — toleriert Leerraum um Bindestriche und ist Unicode-fest.
        $query = Athlete::where('gender', $gender)->whereNull('deleted_at');

        if ($birthDate !== '') {
            $query->where(DB::raw('SUBSTR(birth_date, 1, 4)'), substr($birthDate, 0, 4));
        }

        return $query->oldest('birth_date')->get()
            ->filter(fn (Athlete $a) => $this->normalizeName($a->last_name) === $normLast
                && $this->normalizeName($a->first_name) === $normFirst)
            ->values();
    }

    /** true, wenn der kürzere der beiden Namen ein mehrwortiges Wort-Präfix des längeren ist. */
    private function isWordBoundaryPrefixMatch(string $a, string $b): bool
    {
        if ($a === '' || $b === '' || $a === $b) {
            return false;
        }
        [$shorter, $longer] = strlen($a) <= strlen($b) ? [$a, $b] : [$b, $a];

        return str_contains($shorter, ' ') && str_starts_with($longer, $shorter.' ');
    }
}
