<?php

namespace App\Support;

use App\Models\Club;
use Illuminate\Support\Collection;

/**
 * Ein Verein in der WPS-Vereinsauswertung (Spec "WPS Rankings" §9).
 *
 * **Kein offizieller Charakter.** die Vereinswertung des Cup-Moduls ist die offizielle
 * ÖBSV-Wertung; diese hier ist ein Analysewerkzeug. Wer die beiden verwechselt, zieht falsche
 * Schlüsse — deshalb wird der Unterschied in Ansicht und PDF ausdrücklich benannt.
 */
final readonly class WpsClubRankingEntry
{
    /**
     * @param  int|null  $rank  null für Vereine unterhalb der Mindestzahl
     * @param  float  $value  Punktsumme, Durchschnitt oder Anzahl — je nach Methode
     * @param  Collection<int, WpsClubRankingDetail>  $details  Beitrag je Athlet
     */
    public function __construct(
        public ?int $rank,
        public Club $club,
        public float $value,
        public int $athleteCount,
        public int $entryCount,
        public Collection $details,
    ) {}

    /**
     * Der Wert als Text.
     *
     * Anzahlen und Punktsummen sind ganze Zahlen, der Durchschnitt bekommt eine
     * Nachkommastelle: Ohne sie lägen Vereine gleichauf, die es nicht sind.
     */
    public function formattedValue(bool $isAverage): string
    {
        return $isAverage
            ? number_format($this->value, 1, ',', '.')
            : number_format($this->value, 0, ',', '.');
    }

    /** Unterhalb der Mindestzahl gewerteter Leistungen. */
    public function isBelowMinimum(): bool
    {
        return $this->rank === null;
    }

    public function withRank(?int $rank): self
    {
        return new self(
            $rank,
            $this->club,
            $this->value,
            $this->athleteCount,
            $this->entryCount,
            $this->details,
        );
    }
}
