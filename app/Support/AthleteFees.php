<?php

namespace App\Support;

use App\Models\Athlete;
use Illuminate\Support\Collection;

/**
 * Einzelstarts eines Athleten in der Meldegeld-Abrechnung seines Vereins. Athleten, die nur in Staffeln starten,
 * erscheinen mit leerer Startliste (sie zählen für die Gebühr je Athlet).
 */
final readonly class AthleteFees
{
    /**
     * @param  Collection<int, FeeLine>  $starts  je Einzelstart eine Position (Bewerb, 1 ×, Bewerbsgebühr)
     */
    public function __construct(
        public Athlete $athlete,
        public Collection $starts,
        public int $totalCents,
    ) {}
}
