<?php

namespace App\Support;

/**
 * PerformanceClubRankingResult
 *
 * Unveränderliches Value Object für eine Zeile der leistungsorientierten
 * Vereinswertung (Wertungssystem B, Spec §6 / §13.4). Wie System A wird die
 * Wertung dynamisch berechnet und nicht persistiert (Spec §12.1).
 *
 * - totalPoints:      gewichtete Gesamtpunkte des Vereins (2 Nachkommastellen)
 * - countedAthletes:  Anzahl der gewichtet einfließenden Athleten
 * - countedMeets:     Anzahl unterschiedlicher gewerteter Cup-Meets
 * - athletes:         Detailaufstellung je gewertetem Athleten (nach Beitrag
 *                     absteigend)
 *
 * `rank` folgt der Sportwertung: Vereine mit identischen Wertungskriterien
 * teilen sich einen Rang (Tie-Breaker Spec §14); der Vereinsname ist nur ein
 * stabiles Anzeigekriterium und beeinflusst den Rang nicht.
 */
final readonly class PerformanceClubRankingResult
{
    /**
     * @param  list<CountedAthleteBreakdown>  $athletes  gewertete Athleten, nach Beitrag absteigend
     */
    public function __construct(
        public int $rank,
        public int $clubId,
        public string $clubName,
        public float $totalPoints,
        public int $countedAthletes,
        public int $countedMeets,
        public array $athletes,
    ) {}
}
