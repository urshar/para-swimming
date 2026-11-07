<?php

namespace App\Support;

/**
 * Kennzahlen der Meldungen einer Veranstaltung (App\Services\MeetEntrySummaryService): Jugendliche, Sportler in der
 * B-Meisterschaft und Medaillenbedarf, dazu Hinweise auf Meldungen, die nicht ausgewertet werden konnten.
 */
final readonly class MeetEntrySummary
{
    /**
     * @param  int|null  $bChampionshipAthletes  null = die Veranstaltung hat keine Gruppe mit Titel ÖBM
     * @param  list<MedalTableRow>  $medalRows  Einzel zuerst, danach Staffeln
     * @param  list<MedalDetailRow>  $details
     * @param  list<string>  $eventsWithoutGroups  Bewerbe mit Meldungen, aber ohne Wertungsgruppen
     * @param  list<string>  $groupsWithoutMedals  Namen der Gruppen ohne Medaillenwertung
     */
    public function __construct(
        public bool $reductionRule,
        public int $athletes,
        public int $youthAthletes,
        public int $athletesWithoutBirthDate,
        public ?int $bChampionshipAthletes,
        public array $medalRows,
        public array $details,
        public array $eventsWithoutGroups,
        public int $unassignedEntries,
        public array $groupsWithoutMedals,
        public int $unevaluableRelays,
    ) {}

    /** Summe aller Medaillen der Aufstellung. */
    public function totalMedals(): MedalCount
    {
        return array_reduce($this->medalRows, fn (MedalCount $sum, MedalTableRow $row): MedalCount => $sum->plus($row->medals), MedalCount::none());
    }
}
