<?php

namespace App\Services;

use App\Models\WpsScmConversionFactor;
use Illuminate\Support\Collection;

/**
 * Löst den Umrechnungsfaktor Kurzbahn → Langbahn auf und wendet ihn an.
 *
 *     p_LCM = p_SCM × factor
 *
 * Die Auflösung folgt der Kaskade aus Spec §9.3: Es gewinnt der spezifischste passende
 * Faktor. Ist keiner vorhanden, liefert der Service null — die Berechnung wird dann
 * übersprungen. Ein fehlender Faktor darf NICHT als 1 behandelt werden, sonst entstünden
 * stillschweigend zu niedrige Punkte.
 */
final readonly class WpsScmConversionService
{
    /**
     * Ermittelt den passenden Faktor.
     *
     * Die Kandidaten werden in einer Abfrage geladen und in PHP nach Spezifität sortiert —
     * das ist übersichtlicher als sechs gestaffelte Abfragen und vermeidet bei einer
     * Massenberechnung zusätzliche Datenbankrunden.
     */
    public function resolveFactor(
        int $strokeTypeId,
        int $distance,
        string $sportClass,
        string $gender,
    ): ?WpsScmConversionFactor {
        return $this->candidates($strokeTypeId)
            ->filter(static function (WpsScmConversionFactor $factor) use ($distance, $sportClass, $gender): bool {
                // null bedeutet "gilt für alle" — passt also immer.
                return ($factor->distance === null || $factor->distance === $distance)
                    && ($factor->sport_class === null || $factor->sport_class === $sportClass)
                    && ($factor->gender === null || $factor->gender === $gender);
            })
            ->sortByDesc(static fn (WpsScmConversionFactor $factor): int => $factor->specificity())
            ->first();
    }

    /**
     * Rechnet eine Kurzbahnzeit in ein Langbahn-Äquivalent um.
     *
     * Ein- und Ausgabe in Hundertstelsekunden. Aufgerundet wird nicht: Die Umrechnung ist
     * ohnehin eine Schätzung, und ein konsistentes Abschneiden vermeidet, dass durch Rundung
     * ein Punkt mehr entsteht als bei der direkten Rechnung.
     */
    public function convert(int $scmCentiseconds, WpsScmConversionFactor $factor): int
    {
        return (int) floor($scmCentiseconds * $factor->factor);
    }

    /**
     * Aktive Faktoren eines Schwimmstils.
     *
     * once() memoisiert je Service-Instanz und Aufrufstelle. Damit wird die (kleine) Tabelle
     * bei einer Massenberechnung nicht für jedes Ergebnis erneut geladen, ohne dass der
     * Service seinen readonly-Charakter verliert.
     *
     * @return Collection<int, WpsScmConversionFactor>
     */
    private function candidates(int $strokeTypeId): Collection
    {
        $alle = once(static fn (): Collection => WpsScmConversionFactor::query()
            ->active()
            ->get()
            ->groupBy('stroke_type_id'));

        return $alle->get($strokeTypeId) ?? collect();
    }
}
