<?php

namespace App\Support;

use App\Models\Athlete;

/**
 * Ein Platz in einer Auswahl-Rangliste (Spec "WPS Qualification" §8).
 *
 * Der Rang wird bei Punktgleichheit geteilt; der darauffolgende Rang springt entsprechend —
 * dieselbe Handhabung wie in der Cup-Wertung. Zwei Athleten mit derselben Punktzahl
 * unterschiedlich zu platzieren hieße, eine Reihenfolge zu behaupten, die die Zahlen nicht
 * hergeben.
 */
final readonly class QualificationRankingEntry
{
    /**
     * @param  int|null  $rank  null für Athleten ohne Punktbewertung (§8)
     * @param  QualificationRow  $row  die zugrunde liegende Bewerbszeile
     * @param  int|null  $points  WPS-Punkte der nachgewiesenen Zeit
     * @param  int  $fulfilledCount  Anzahl erfüllter Normen des Athleten, nur in der
     *                               Athletenrangliste von Belang
     */
    public function __construct(
        public ?int $rank,
        public Athlete $athlete,
        public QualificationRow $row,
        public ?int $points,
        public int $fulfilledCount,
    ) {}

    /**
     * Ohne Punktbewertung lässt sich der Athlet nicht einsortieren.
     *
     * Solche Zeilen werden in einem eigenen Abschnitt unterhalb der Rangliste geführt — nicht
     * stillschweigend weggelassen und nicht mit null Punkten ans Ende gesetzt. Beides
     * behauptete etwas: einmal, dass es die Leistung nicht gibt, einmal, dass sie die
     * schlechteste ist.
     */
    public function isUnranked(): bool
    {
        return $this->rank === null;
    }
}
