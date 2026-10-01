<?php

namespace App\Support;

/**
 * Eine Zeile der Gebührenübersicht einer Veranstaltung (App\Services\EntryFeeCalculator::schedule()):
 * Ebene ("Veranstaltung", "Abschnitt 2", "Bewerbe"), Bezeichnung und Betrag in Cent.
 */
final readonly class FeeScheduleLine
{
    public function __construct(
        public string $group,
        public string $label,
        public int $amountCents,
    ) {}
}
