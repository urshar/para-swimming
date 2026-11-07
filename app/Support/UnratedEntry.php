<?php

namespace App\Support;

/**
 * UnratedEntry
 *
 * Meldung eines Prognose-Meets, für die sich in der Leistungswertungs-Prognose keine
 * Cup-Punkte ermitteln lassen (docs/specs/cup-club-ranking-forecast.md §5) — z. B. ohne
 * Meldezeit (NT), ohne Sportklasse, ohne Basiswert oder ohne aktive Sportklassengruppe.
 * Als Start zählt sie in der Startwertungs-Prognose trotzdem.
 */
final readonly class UnratedEntry
{
    public function __construct(
        public int $entryId,
        public int $athleteId,
        public int $clubId,
        public string $reason,
    ) {}
}
