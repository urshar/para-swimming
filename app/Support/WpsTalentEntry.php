<?php

namespace App\Support;

use App\Models\Athlete;

/**
 * Eine Zeile der Förderauswertung (Spec "WPS Rankings" §6.6.4).
 *
 * **Eine Zeile je Athlet und Bewerb** — ein Athlet kann mehrfach in der Liste stehen. Das ist
 * beabsichtigt: Die Information, in welchen Bewerben jemand über der Schwelle liegt, ist für
 * die Förderentscheidung wesentlich.
 */
final readonly class WpsTalentEntry
{
    /**
     * @param  string  $group  Jugend oder Allgemein, nach dem Alter im Ergebnisjahr
     * @param  int  $age  Alter zum 31.12. des Jahres, in dem das Ergebnis erzielt wurde
     * @param  int  $swimTime  beste Zeit im Zeitraum, in Hundertstelsekunden
     * @param  int|null  $estimatedLcmTime  geschätzte Langbahnzeit; steht bewusst neben der
     *                                      Punktzahl (§6.6.4)
     * @param  int  $points  WPS-Punkte aus dem Ergebnis, nicht neu berechnet
     * @param  int  $thresholdPoints  Punktschwelle dieses Bewerbs für diese Altersgruppe
     * @param  int|null  $normTime  MQS der Referenznorm, zum unmittelbaren Vergleich
     * @param  int  $normPoints  Punktzahl der Normzeit
     */
    public function __construct(
        public Athlete $athlete,
        public ?int $birthYear,
        public int $age,
        public string $group,
        public string $sportClass,
        public string $eventLabel,
        public int $swimTime,
        public string $course,
        public ?int $estimatedLcmTime,
        public int $points,
        public int $thresholdPoints,
        public ?int $normTime,
        public int $normPoints,
        public ?string $meetName,
        public ?string $meetDate,
    ) {}

    public function reachesThreshold(): bool
    {
        return $this->points >= $this->thresholdPoints;
    }

    /** Abstand zur Schwelle in Punkten; negativ = darunter. */
    public function gapToThreshold(): int
    {
        return $this->points - $this->thresholdPoints;
    }

    /**
     * Erreichter Anteil der Normpunktzahl in Prozent.
     *
     * Die Bezugsgröße ist die Norm, nicht die Schwelle: Der Wert soll unmittelbar mit dem
     * eingestellten Prozentsatz vergleichbar sein.
     */
    public function percentOfNorm(): ?float
    {
        return $this->normPoints <= 0 ? null : round($this->points / $this->normPoints * 100, 1);
    }

    /**
     * Abstand zur Schwelle als Text, z.B. "+12" oder "−7".
     *
     * Minuszeichen als U+2212, nicht als Bindestrich: In einer Tabelle mit Zahlen ist ein
     * Bindestrich zu leicht mit einem Trennstrich zu verwechseln.
     */
    public function formattedGap(): string
    {
        $abstand = $this->gapToThreshold();

        return ($abstand < 0 ? "\u{2212}" : '+').abs($abstand);
    }
}
