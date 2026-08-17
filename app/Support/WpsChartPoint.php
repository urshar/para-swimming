<?php

namespace App\Support;

/**
 * Ein Datenpunkt der Verlaufsgrafik (Spec "WPS Rankings" §7.6).
 */
final readonly class WpsChartPoint
{
    /**
     * @param  float  $x  Bildkoordinate auf der Zeitachse
     * @param  float  $y  Bildkoordinate; kleinere Werte liegen oben, mehr Punkte also höher
     * @param  bool  $classChanged  Sportklasse gegenüber dem vorherigen Punkt gewechselt
     */
    public function __construct(
        public float $x,
        public float $y,
        public ?int $points,
        public int $swimTime,
        public string $sportClass,
        public string $date,
        public ?string $meetName,
        public bool $classChanged,
        public bool $estimated,
    ) {}

    /**
     * Beschriftung für den Hinweistext beim Überfahren.
     *
     * Nennt immer die Zeit; die Punktzahl nur, wo sie vorliegt. Bei einem Athleten, dessen
     * Wettkämpfe nie durch die Punkteberechnung gelaufen sind, stünde sonst überall
     * "0 Punkte".
     */
    public function tooltip(): string
    {
        return sprintf(
            '%s · %s%s · %s%s',
            date('d.m.Y', strtotime($this->date)),
            TimeParser::display($this->swimTime),
            $this->points === null ? '' : ' · '.$this->points.' Punkte',
            $this->sportClass,
            $this->meetName === null ? '' : ' · '.$this->meetName,
        );
    }
}
