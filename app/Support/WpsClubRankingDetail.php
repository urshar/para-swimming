<?php

namespace App\Support;

use App\Models\Athlete;

/**
 * Der Beitrag eines Athleten zum Vereinswert (Spec "WPS Rankings" §9).
 *
 * Aufklappbar unter dem Verein: Eine Vereinssumme ohne Aufschlüsselung ist nicht prüfbar, und
 * gerade bei einer Auswertung, deren Rechenweise wählbar ist, muss nachvollziehbar bleiben,
 * woraus sich der Wert ergibt.
 */
final readonly class WpsClubRankingDetail
{
    /**
     * @param  float  $contribution  was dieser Athlet zum Vereinswert beiträgt
     * @param  int  $entryCount  wie viele seiner Leistungen eingegangen sind
     * @param  int|null  $bestPoints  seine beste eingegangene Punktzahl
     */
    public function __construct(
        public Athlete $athlete,
        public float $contribution,
        public int $entryCount,
        public ?int $bestPoints,
    ) {}
}
