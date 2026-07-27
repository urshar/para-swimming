<?php

namespace App\Support;

/**
 * StartClubRankingResult
 *
 * Unveränderliches Value Object für eine Zeile der klassischen Startwertung
 * der ÖBSV-Cup-Vereinswertung (Wertungssystem A, Spec §3 / §13.3).
 *
 * Bewusst als reines Value Object (kein Eloquent-Model/Tabelle): Die
 * Startwertung wird — wie die übrige Statistik- und Cup-Wertungslogik des
 * Projekts — dynamisch aus den Bestandsdaten (`results`) berechnet und nicht
 * persistiert (Spec §12.1). Passt zur bestehenden readonly-Value-Object-Architektur (analog `ReportConfiguration`).
 *
 * `rank` folgt der Sportwertung: Vereine mit identischen Wertungskriterien
 * (starts, athletes, meets) teilen sich einen Rang, der nächste Rang
 * überspringt entsprechend. Der Vereinsname ist nur ein stabiles
 * Anzeigekriterium und beeinflusst den Rang nicht.
 */
final readonly class StartClubRankingResult
{
    /**
     * @param  int  $rank  1-basierter Rang innerhalb der Vereinswertung
     * @param  int  $clubId  Verein (results.club_id, Startverein zum Zeitpunkt des Ergebnisses)
     * @param  string  $clubName  Anzeigename des Vereins
     * @param  int  $starts  Anzahl gewerteter Starts (distinct Athlet × Einzelbewerb × Cup-Meet)
     * @param  int  $athletes  Anzahl unterschiedlicher Athleten mit mindestens einem Start
     * @param  int  $meets  Anzahl unterschiedlicher Cup-Meets mit mindestens einem Start
     */
    public function __construct(
        public int $rank,
        public int $clubId,
        public string $clubName,
        public int $starts,
        public int $athletes,
        public int $meets,
    ) {}
}
