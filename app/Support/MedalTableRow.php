<?php

namespace App\Support;

/**
 * Zeile der Medaillenaufstellung: eine Wertung (Titel wie "ÖJM" oder Gruppenname ohne Titel), getrennt nach Einzel und
 * Staffel.
 */
final readonly class MedalTableRow
{
    public function __construct(
        public string $label,
        public bool $isRelay,
        public MedalCount $medals,
    ) {}
}
