<?php

namespace App\Support;

/**
 * CountedAthleteBreakdown
 *
 * Nachvollziehbarer Wertungsbeitrag eines Athleten innerhalb der
 * Vereinsleistungswertung (Spec §13.4 — aufklappbare Detailansicht).
 *
 * - meetPoints:     die gewerteten Cup-Meet-Punkte (absteigend)
 * - meetIds:        die zugehörigen Cup-Meets, positionsgleich zu meetPoints (z. B. um in der
 *                   Prognose prognostizierte Meet-Punkte zu kennzeichnen)
 * - seasonValue:    Summe der gewerteten Meet-Punkte (ungewichtet)
 * - weight:         Gewicht der Position des Athleten im Verein
 * - weightedValue:  seasonValue × weight = Beitrag zur Vereinswertung
 *                   (auf 2 Nachkommastellen gerundet)
 * - isKader:        ist ein (eingeschränkter) Kaderathlet
 */
final readonly class CountedAthleteBreakdown
{
    /**
     * @param  list<int>  $meetPoints  gewertete Meet-Punkte, absteigend
     * @param  list<int>  $meetIds  Cup-Meets zu meetPoints, positionsgleich
     */
    public function __construct(
        public int $athleteId,
        public string $athleteName,
        public int $position,
        public array $meetPoints,
        public array $meetIds,
        public int $seasonValue,
        public float $weight,
        public float $weightedValue,
        public bool $isKader,
    ) {}
}
