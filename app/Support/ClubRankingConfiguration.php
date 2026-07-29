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
 * - excludedKaderTypeCodes:     Codes von Kaderarten, deren Athleten (während des
 *                               Cup-Jahres aktiv) nicht in die Wertung einfließen
 */
final readonly class ClubRankingConfiguration
{
    /**
     * @param  array<int, float>  $weights  Position (1-basiert) => Gewicht
     * @param  list<string>  $excludedKaderTypeCodes  Codes ausgeschlossener Kaderarten
     */
    public function __construct(
        public int $countedMeetsPerAthlete,
        public int $maxCountedAthletesPerClub,
        public array $weights,
        public bool $includeForeignClubs,
        public array $excludedKaderTypeCodes,
    ) {}

    /** Konfiguration aus config/cup_club_ranking.php. */
    public static function fromConfig(): self
    {
        return new self(
            countedMeetsPerAthlete: (int) config('cup_club_ranking.counted_meets_per_athlete', 3),
            maxCountedAthletesPerClub: (int) config('cup_club_ranking.max_counted_athletes_per_club', 5),
            weights: config('cup_club_ranking.athlete_weights', [1 => 1.0, 2 => 0.8, 3 => 0.6, 4 => 0.4, 5 => 0.2]),
            includeForeignClubs: (bool) config('cup_club_ranking.include_foreign_clubs', false),
            excludedKaderTypeCodes: config('cup_club_ranking.excluded_kader_type_codes', [
                'WELTKLASSE', 'INTERNATIONALE_KLASSE', 'SICHTUNGSPOOL',
            ]),
        );
    }

    /** Gewicht für eine 1-basierte Position (0.0, falls nicht konfiguriert). */
    public function weightForPosition(int $position): float
    {
        return $this->weights[$position] ?? 0.0;
    }

    /**
     * Kopie mit überschriebenem Ausland-Schalter — für die UI-Umschaltung, ohne
     * die übrigen (konfigurierten) Werte zu verlieren.
     */
    public function withIncludeForeignClubs(bool $includeForeignClubs): self
    {
        return new self(
            countedMeetsPerAthlete: $this->countedMeetsPerAthlete,
            maxCountedAthletesPerClub: $this->maxCountedAthletesPerClub,
            weights: $this->weights,
            includeForeignClubs: $includeForeignClubs,
            excludedKaderTypeCodes: $this->excludedKaderTypeCodes,
        );
    }
}
