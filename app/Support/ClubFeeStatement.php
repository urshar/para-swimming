<?php

namespace App\Support;

use App\Models\Club;
use Illuminate\Support\Collection;

/**
 * Meldegeld-Abrechnung eines Vereins für eine Veranstaltung (App\Services\EntryFeeCalculator).
 * Summe = Einzelstarts aller Athleten + Staffeln + Pauschalen. Beträge in Cent.
 */
final readonly class ClubFeeStatement
{
    /**
     * @param  Collection<int, AthleteFees>  $athletes  alphabetisch
     * @param  Collection<int, FeeLine>  $relays  je Staffel eine Position (Staffelname · Bewerb)
     * @param  Collection<int, FeeLine>  $flatFees  Pauschalen je Verein/Athlet (Veranstaltung, dann Abschnitte)
     */
    public function __construct(
        public Club $club,
        public Collection $athletes,
        public Collection $relays,
        public Collection $flatFees,
        public int $startCount,
        public int $totalCents,
    ) {}

    public function startsCents(): int
    {
        return $this->athletes->sum(fn (AthleteFees $a): int => $a->totalCents);
    }

    public function relaysCents(): int
    {
        return $this->relays->sum(fn (FeeLine $line): int => $line->totalCents);
    }

    public function flatCents(): int
    {
        return $this->flatFees->sum(fn (FeeLine $line): int => $line->totalCents);
    }
}
