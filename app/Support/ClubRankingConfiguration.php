<?php

namespace App\Support;

/**
 * ClubRankingConfiguration
 *
 * Unveränderliche Konfiguration der leistungsorientierten Vereinswertung
 * (Wertungssystem B, Spec §8). Die Standardwerte liegen in
 * config/cup_club_ranking.php und werden über self::fromConfig() geladen.
 *
 * - countedMeetsPerAthlete:     beste N Cup-Meets je Athlet und Verein (§5.3)
 * - maxCountedAthletesPerClub:  beste N Athleten je Verein (§6)
 * - weights:                    Gewicht je 1-basierter Position; Positionen ohne
 *                               Eintrag zählen mit Gewicht 0 (§6)
 * - includeForeignClubs:        ausländische Vereine mitwerten (§8/§15)
 */
final readonly class ClubRankingConfiguration
{
    /**
     * @param  array<int, float>  $weights  Position (1-basiert) => Gewicht
     */
    public function __construct(
        public int $countedMeetsPerAthlete,
        public int $maxCountedAthletesPerClub,
        public array $weights,
        public bool $includeForeignClubs,
    ) {}

    /** Konfiguration aus config/cup_club_ranking.php. */
    public static function fromConfig(): self
    {
        return new self(
            countedMeetsPerAthlete: (int) config('cup_club_ranking.counted_meets_per_athlete', 3),
            maxCountedAthletesPerClub: (int) config('cup_club_ranking.max_counted_athletes_per_club', 5),
            weights: config('cup_club_ranking.athlete_weights', [1 => 1.0, 2 => 0.8, 3 => 0.6, 4 => 0.4, 5 => 0.2]),
            includeForeignClubs: (bool) config('cup_club_ranking.include_foreign_clubs', false),
        );
    }

    /** Gewicht für eine 1-basierte Position (0.0, falls nicht, konfiguriert). */
    public function weightForPosition(int $position): float
    {
        return $this->weights[$position] ?? 0.0;
    }
}
