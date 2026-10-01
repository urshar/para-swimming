<?php

namespace App\Support;

/**
 * Eine Position der Meldegeld-Abrechnung: Bezeichnung, Anzahl, Einzelbetrag, Summe (Beträge in Cent).
 * Verwendet für Einzelstarts, Staffeln und Pauschalen (je Verein/Athlet, je Veranstaltung oder Abschnitt).
 */
final readonly class FeeLine
{
    public function __construct(
        public string $label,
        public int $quantity,
        public int $unitCents,
        public int $totalCents,
    ) {}

    public static function of(string $label, int $quantity, int $unitCents): self
    {
        return new self($label, $quantity, $unitCents, $quantity * $unitCents);
    }
}
