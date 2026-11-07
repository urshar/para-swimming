<?php

namespace App\Support;

/**
 * ClubForecastComparison
 *
 * Vergleich eines Vereins zwischen aktueller Vereinswertung und Prognose
 * (docs/specs/cup-club-ranking-forecast.md §6).
 *
 * - currentRank:   Rang in der aktuellen Wertung; null = bisher nicht gewertet
 * - currentValue:  Wert in der aktuellen Wertung (Starts bzw. Gesamtpunkte); 0 = bisher nicht gewertet
 * - forecastRank:  Rang in der Prognose
 * - forecastValue: Wert in der Prognose
 * - rankChange:    currentRank − forecastRank (positiv = Verbesserung); null = bisher nicht gewertet
 * - valueDelta:    forecastValue − currentValue
 */
final readonly class ClubForecastComparison
{
    public function __construct(
        public int $clubId,
        public ?int $currentRank,
        public float $currentValue,
        public int $forecastRank,
        public float $forecastValue,
        public ?int $rankChange,
        public float $valueDelta,
    ) {}
}
