<?php

namespace App\Services;

use App\Support\WpsRankingEntry;
use App\Support\WpsRankingFilter;
use Illuminate\Support\Collection;

/**
 * WpsMeetRankingService
 *
 * Veranstaltungsranglisten (Spec "WPS Rankings" §6.1): die besten WPS-Leistungen innerhalb
 * einer Veranstaltung, über Sportklassen und Bewerbe hinweg vergleichbar.
 *
 * Bewusst **ohne** Bestenauswahl je Athlet und Bewerb: Innerhalb einer Veranstaltung ist
 * jedes Ergebnis ein eigener Start, und Vorlauf wie Finale sollen beide sichtbar bleiben.
 * Die Bestenauswahl aus §4 gilt laut Spec ausdrücklich nur für Saison- und Jugendranglisten.
 *
 * Bahnlänge und WPS-Version ergeben sich aus der Veranstaltung.
 *
 * Rein lesend; nichts wird gespeichert (**[R4]**).
 */
final readonly class WpsMeetRankingService
{
    public function __construct(
        private WpsResultSelectionService $selection
    ) {}

    /**
     * @return Collection<int, WpsRankingEntry>
     */
    public function ranking(WpsRankingFilter $filter): Collection
    {
        return $this->selection->rank($this->selection->select($filter));
    }
}
