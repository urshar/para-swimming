<?php

namespace App\Support;

/**
 * CountedAthleteBreakdown
 *
 * Nachvollziehbarer Wertungsbeitrag eines Athleten innerhalb der
 * Vereinsleistungswertung (Spec §13.4 — aufklappbare Detailansicht).
 *
 * - meetPoints:     die gewerteten Cup-Meet-Punkte (absteigend)
 * - seasonValue:    Summe der gewerteten Meet-Punkte (ungewichtet)
 * - weight:         Gewicht der Position des Athleten im Verein
 * - weightedValue:  seasonValue × weight = Beitrag zur Vereinswertung
 *                   (auf 2 Nachkommastellen gerundet)
 */
final readonly class CountedAthleteBreakdown
{
    /**
     * @param  list<int>  $meetPoints  gewertete Meet-Punkte, absteigend
     */
    public function __construct(
        public int $athleteId,
        public string $athleteName,
        public int $position,
        public array $meetPoints,
        public int $seasonValue,
        public float $weight,
        public float $weightedValue,
    ) {}
}
