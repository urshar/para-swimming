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
        public int $points,
        public string $sportClass,
        public string $date,
        public ?string $meetName,
        public bool $classChanged,
        public bool $estimated,
    ) {}

    /** Beschriftung für den Hinweistext beim Überfahren. */
    public function tooltip(): string
    {
        return sprintf(
            '%s · %d Punkte · %s%s',
            date('d.m.Y', strtotime($this->date)),
            $this->points,
            $this->sportClass,
            $this->meetName === null ? '' : ' · '.$this->meetName,
        );
    }
}
