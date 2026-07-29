<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Cup;
use App\Models\CupDailyResult;
use App\Support\ClubRankingConfiguration;
use App\Support\CountedAthleteBreakdown;
use App\Support\PerformanceClubRankingResult;
use Illuminate\Support\Collection;
use stdClass;

/**
 * PerformanceBasedClubRankingService
 *
 * Berechnet die leistungsorientierte Vereinswertung des ÖBSV Cups
 * (Wertungssystem B, Spec §4–§6). Sie belohnt sportliche Leistung und begrenzt
 * den Vorteil sehr großer Vereine über ein gewichtetes Top-Athleten-Modell.
 *
 * Datenbasis (Spec §5.1): der bestehende Snapshot `cup_daily_results`. Dieser
 * enthält je Athlet und Cup-Meet bereits das punktbeste gültige Einzelergebnis,
 * dessen Cup-Punkte über den WorldAquaticsPointsService gegen die Cup-Basiswert-
 * Version berechnet wurden, inklusive Startverein (`club_id`). Es wird keine
 * eigene, parallele Punkteberechnung implementiert (Spec §19).
 *
 * Berechnungskette:
 *   1. cup_daily_results des Cups laden (ohne EXH, siehe unten),
 *   2. je (Athlet, Verein) die besten counted_meets_per_athlete Meets summieren
 *      → Athleten-Saisonwert je Verein (Vereinswechsel per-Meet/per-Verein, §9),
 *   3. je Verein die besten max_counted_athletes_per_club Athleten gewichten,
 *   4. Vereinsgesamtwert, Reihung und Tie-Break (§14).
 *
 * EXH-Ausschluss: EXH-Ergebnisse (außer Konkurrenz) zählen in keiner
 * Punktewertung. Der Ausschluss erfolgt an der Quelle — DailyRankingService
 * überspringt EXH bei der Auswahl des Tagesbesten, sodass cup_daily_results je
 * Athlet/Meet bereits das beste Nicht-EXH-Ergebnis enthält (ein paralleles
 * reguläres Ergebnis desselben Athleten zählt also weiterhin). Zusätzlich liest
 * diese Wertung nur reguläre Ergebnisse (results.status = null) aus dem Snapshot
 * — als Absicherung gegen veraltete Snapshots, die vor der EXH-Korrektur
 * berechnet wurden.
 *
 * Snapshot-Abhängigkeit: Es fließen nur Cup-Meets ein, für die eine Tageswertung
 * berechnet wurde. Fehlende oder veraltete Tageswertungen sind in der UI über
 * CupStalenessService kenntlich zu machen (Spec §13.4).
 */
final readonly class PerformanceBasedClubRankingService
{
    /** Vereinsnation, die als "inländisch" gilt. */
    private const string DOMESTIC_NATION_CODE = 'AUT';

    /**
     * Leistungsorientierte Vereinswertung für einen Cup.
     *
     * @param  ClubRankingConfiguration|null  $config  null = Konfiguration aus config()
     * @return Collection<int, PerformanceClubRankingResult> gereiht, mit dynamischem Rang;
     *                                                       leer, wenn keine gewerteten
     *                                                       Tageswertungen vorliegen
     */
    public function getRanking(Cup $cup, ?ClubRankingConfiguration $config = null): Collection
    {
        $config ??= ClubRankingConfiguration::fromConfig();

        $dailyResults = $this->loadDailyResults($cup);

        if ($dailyResults->isEmpty()) {
            return collect();
        }

        $perAthleteClub = $this->resolveAthleteSeasonValues($dailyResults, $config);
        $byClub = $perAthleteClub->groupBy('club_id');

        $clubs = Club::withTrashed()
            ->with('nation:id,code')
            ->whereIn('id', $byClub->keys())
            ->get(['id', 'name', 'nation_id'])
            ->keyBy('id');

        $athletes = Athlete::withTrashed()
            ->whereIn('id', $perAthleteClub->pluck('athlete_id')->unique())
            ->get(['id', 'name_prefix', 'first_name', 'last_name'])
            ->keyBy('id');

        $rows = $byClub
            ->reject(fn (Collection $clubAthletes, int $clubId): bool => ! $config->includeForeignClubs
                && ! $this->isDomestic($clubs->get($clubId)))
            ->map(fn (Collection $clubAthletes, int $clubId): array => $this->buildClubRow(
                $clubId,
                $clubAthletes,
                $config,
                $clubs,
                $athletes
            ))
            ->values()
            ->sort(fn (array $a, array $b): int => [
                $b['total_points'], $b['unweighted_sum'], $b['best_value'],
                $b['counted_athletes'], $b['counted_meets'],
            ] <=> [
                $a['total_points'], $a['unweighted_sum'], $a['best_value'],
                $a['counted_athletes'], $a['counted_meets'],
            ] ?: strcmp($a['club_name'], $b['club_name']))
            ->values();

        return $this->assignRanks($rows);
    }

    /**
     * Lädt die gewerteten Tageswertungs-Zeilen des Cups.
     *
     * Es fließen nur reguläre Ergebnisse ein: der Join auf `results` filtert auf
     * status = null. DailyRankingService schließt EXH bereits beim Aufbau von
     * cup_daily_results aus; dieser Filter ist daher eine Absicherung gegen
     * veraltete Snapshots. DSQ/DNS/DNF/WDR sind in cup_daily_results ohnehin
     * nicht enthalten (Result::isValid()).
     *
     * @return Collection<int, stdClass> je Zeile: athlete_id, club_id, meet_id, points
     */
    private function loadDailyResults(Cup $cup): Collection
    {
        return CupDailyResult::query()
            ->join('results', 'results.id', '=', 'cup_daily_results.result_id')
            ->where('cup_daily_results.cup_id', $cup->id)
            ->whereNull('results.status')
            ->toBase()
            ->selectRaw(
                'cup_daily_results.athlete_id as athlete_id, '
                .'cup_daily_results.club_id as club_id, '
                .'cup_daily_results.meet_id as meet_id, '
                .'cup_daily_results.points as points'
            )
            ->get();
    }

    /**
     * Saisonwert je (Athlet, Verein): die besten counted_meets_per_athlete
     * Meet-Punkte. Da cup_daily_results je Athlet und Meet genau eine Zeile
     * führt, sind die Meets innerhalb einer Gruppe bereits eindeutig. Die
     * per-Verein-Gruppierung setzt §9 um: Ein Athlet mit Vereinswechsel trägt
     * bei jedem Verein nur mit den dort erzielten Meets bei.
     *
     * @param  Collection<int, stdClass>  $dailyResults
     * @return Collection<int, array{athlete_id: int, club_id: int, meet_points: list<int>, meet_ids: list<int>, season_value: int}>
     */
    private function resolveAthleteSeasonValues(Collection $dailyResults, ClubRankingConfiguration $config): Collection
    {
        /** @var Collection<int, array{athlete_id: int, club_id: int, meet_points: list<int>, meet_ids: list<int>, season_value: int}> $seasonValues */
        $seasonValues = $dailyResults
            ->groupBy(fn (stdClass $row): string => $row->athlete_id.'|'.$row->club_id)
            ->map(function (Collection $group) use ($config): array {
                $counted = $group
                    ->sortByDesc(fn (stdClass $row): int => (int) $row->points)
                    ->take($config->countedMeetsPerAthlete)
                    ->values();

                return [
                    'athlete_id' => (int) $group->first()->athlete_id,
                    'club_id' => (int) $group->first()->club_id,
                    'meet_points' => $counted->map(fn (stdClass $row): int => (int) $row->points)->all(),
                    'meet_ids' => $counted->map(fn (stdClass $row): int => (int) $row->meet_id)->all(),
                    'season_value' => (int) $counted->sum(fn (stdClass $row): int => (int) $row->points),
                ];
            })
            ->values();

        return $seasonValues;
    }

    /**
     * Baut die Wertungszeile eines Vereins: beste N Athleten gewichten,
     * Gesamtpunkte, gewertete Athleten/Meets und die Detailaufstellung.
     *
     * @param  Collection<int, array{athlete_id: int, club_id: int, meet_points: list<int>, meet_ids: list<int>, season_value: int}>  $clubAthletes
     * @param  Collection<int, Club>  $clubs
     * @param  Collection<int, Athlete>  $athletes
     * @return array{club_id: int, club_name: string, total_points: float, counted_athletes: int, counted_meets: int, unweighted_sum: int, best_value: int, breakdown: list<CountedAthleteBreakdown>}
     */
    private function buildClubRow(
        int $clubId,
        Collection $clubAthletes,
        ClubRankingConfiguration $config,
        Collection $clubs,
        Collection $athletes
    ): array {
        $counted = $clubAthletes
            ->sortByDesc('season_value')
            ->take($config->maxCountedAthletesPerClub)
            ->values();

        $breakdown = $counted->map(function (array $athlete, int $index) use (
            $config,
            $athletes
        ): CountedAthleteBreakdown {
            $position = $index + 1;
            $weight = $config->weightForPosition($position);

            return new CountedAthleteBreakdown(
                athleteId: $athlete['athlete_id'],
                athleteName: (string) ($athletes->get($athlete['athlete_id'])?->display_name ?? '—'),
                position: $position,
                meetPoints: $athlete['meet_points'],
                seasonValue: $athlete['season_value'],
                weight: $weight,
                weightedValue: round($athlete['season_value'] * $weight, 2),
            );
        });

        $countedMeetIds = $counted->flatMap(fn (array $athlete): array => $athlete['meet_ids'])->unique()->values();

        return [
            'club_id' => $clubId,
            'club_name' => (string) ($clubs->get($clubId)?->name ?? '—'),
            'total_points' => round($breakdown->sum(fn (CountedAthleteBreakdown $b): float => $b->weightedValue), 2),
            'counted_athletes' => $counted->count(),
            'counted_meets' => $countedMeetIds->count(),
            'unweighted_sum' => (int) $counted->sum('season_value'),
            'best_value' => (int) ($counted->first()['season_value'] ?? 0),
            'breakdown' => $breakdown->all(),
        ];
    }

    /** Ist der Verein einem inländischen (österreichischen) Verband zugeordnet? */
    private function isDomestic(?Club $club): bool
    {
        return $club?->nation?->code === self::DOMESTIC_NATION_CODE;
    }

    /**
     * Weist der bereits gereihten Vereinsliste den Rang zu. Gleiche
     * Wertungskriterien (Gesamtpunkte, ungewichtete Summe, beste Leistung,
     * gewertete Athleten, gewertete Meets) ergeben denselben Rang; der nächste
     * Rang überspringt entsprechend. Der Vereinsname ist nur Anzeigekriterium.
     *
     * @param  Collection<int, array{club_id: int, club_name: string, total_points: float, counted_athletes: int, counted_meets: int, unweighted_sum: int, best_value: int, breakdown: list<CountedAthleteBreakdown>}>  $rowsSorted
     * @return Collection<int, PerformanceClubRankingResult>
     */
    private function assignRanks(Collection $rowsSorted): Collection
    {
        $rank = 0;
        $position = 0;
        $previousKey = null;

        return $rowsSorted->map(function (array $row) use (
            &$rank,
            &$position,
            &$previousKey
        ): PerformanceClubRankingResult {
            $position++;
            $key = [
                $row['total_points'], $row['unweighted_sum'], $row['best_value'],
                $row['counted_athletes'], $row['counted_meets'],
            ];

            if ($previousKey === null || $key !== $previousKey) {
                $rank = $position;
            }

            $previousKey = $key;

            return new PerformanceClubRankingResult(
                rank: $rank,
                clubId: $row['club_id'],
                clubName: $row['club_name'],
                totalPoints: $row['total_points'],
                countedAthletes: $row['counted_athletes'],
                countedMeets: $row['counted_meets'],
                athletes: $row['breakdown'],
            );
        });
    }
}
