<?php

namespace App\Services;

use App\Models\Cup;
use App\Models\CupOverallResult;
use App\Support\ReportConfiguration;
use Illuminate\Support\Collection;

/**
 * CupStatisticsService
 *
 * Bindet die bestehende ÖBSV-Cup-Wertung in den Statistik- und Jahresbericht
 * ein (Spec Phase 10).
 *
 * Der Service rechnet NICHTS nach: Die Gesamtwertung wird ausschließlich aus
 * dem bereits berechneten Snapshot (cup_overall_results) über den vorhandenen
 * OverallRankingService gelesen. Auch die Wertungskategorien werden nicht neu
 * definiert, sondern über OverallRankingService::brackets() dynamisch aus der
 * Cup-Konfiguration abgeleitet — inklusive der Regel, dass eine Gruppe mit
 * gemeinsamer Damen-/Herren-Wertung nur ein Bracket ergibt.
 *
 * Es gibt hier also bewusst keine eigene Cup-Logik; dieser Service ist reine
 * Aufbereitung für den Bericht.
 */
final readonly class CupStatisticsService
{
    public function __construct(
        private OverallRankingService $overallRankingService,
    ) {}

    /**
     * Der Cup des Berichtsjahres, sofern angelegt. Der Cup wird über sein
     * Wettkampfjahr (cups.year) an das Berichtsjahr gebunden.
     */
    public function cupForYear(ReportConfiguration $config): ?Cup
    {
        return Cup::query()->where('year', $config->year)->first();
    }

    /**
     * Gesamtwertung des Berichtsjahres. Existiert für das Jahr kein Cup,
     * wird eine leere Collection geliefert — der Berichtsabschnitt bleibt
     * dann einfach leer, statt einen Fehler zu erzeugen.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function overallRankingForConfiguration(ReportConfiguration $config): Collection
    {
        $cup = $this->cupForYear($config);

        return $cup === null ? collect() : $this->overallRanking($cup);
    }

    /**
     * Die Gesamtwertung eines Cups, aufgeschlüsselt nach Wertungskategorie
     * (Sportklassengruppe × Altersgruppe × Geschlecht).
     *
     * Geliefert werden nur Kategorien, für die tatsächlich Wertungen
     * vorliegen. 'gender' ist null, wenn für die Gruppe die gemeinsame
     * Damen-/Herren-Wertung aktiv ist; 'age_group_*' ist null, wenn ohne
     * Alterskategorie gewertet wird. Die Beschriftung der Kategorie überlassen
     * wir bewusst der Ansicht — hier werden nur die Bestandteile geliefert.
     *
     * 'results' enthält die bereits gereihten CupOverallResult-Zeilen des
     * bestehenden Services (inkl. Rang und geladenem Athlet/Verein).
     *
     * @return Collection<int, array{
     *     gender: ?string,
     *     group_id: int,
     *     group_code: string,
     *     group_name: string,
     *     age_group_id: ?int,
     *     age_group_code: ?string,
     *     age_group_name: ?string,
     *     athletes: int,
     *     results: Collection<int, CupOverallResult>
     * }>
     */
    public function overallRanking(Cup $cup): Collection
    {
        return $this->overallRankingService->brackets($cup)
            ->map(function (array $bracket) use ($cup): array {
                $results = $this->overallRankingService->rankedBracket(
                    $cup->id,
                    $bracket['gender'],
                    $bracket['group']->id,
                    $bracket['ageGroup']?->id,
                );

                return [
                    'gender' => $bracket['gender'],
                    'group_id' => $bracket['group']->id,
                    'group_code' => $bracket['group']->code,
                    'group_name' => $bracket['group']->name_de,
                    'age_group_id' => $bracket['ageGroup']?->id,
                    'age_group_code' => $bracket['ageGroup']?->code,
                    'age_group_name' => $bracket['ageGroup']?->name_de,
                    'athletes' => $results->count(),
                    'results' => $results,
                ];
            })
            ->values();
    }
}
