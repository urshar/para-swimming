<?php

namespace App\Services;

use App\Models\Result;
use App\Models\SportClassGroup;
use App\Models\SportClassGroupMember;
use App\Support\SportRankAssigner;
use Illuminate\Support\Collection;

/**
 * AnnualBestService
 *
 * Jahresbestleistungen (Spec public-frontend §5.4, Phase 7): pro Person das punktbeste
 * Einzelergebnis (höchste ÖBSV-Punkte, über alle Bewerbe hinweg) eines Kalenderjahrs, getrennt
 * nach Geschlecht und Behinderungsgruppe (PI/VI/MI/HI/T21). Rein lesend, nichts persistiert — wie
 * im Statistik- und Qualifikationsmodul.
 *
 * Keine Staffeln (relay_count = 1). EXH-Ergebnisse ausgeschlossen — projektweite Regel: EXH zählt
 * für Richtzeiten und Rekorde, nicht für Punkteranglisten. Dieselbe "nur reguläre Ergebnisse"-
 * Filterung (whereNull('status')) wie in QualificationDeterminationService.
 *
 * Die Gruppenzuordnung (S01–S10, S11–S13, S14, S15, S21 → PI/VI/MI/HI/T21) darf laut Spec nicht
 * neu ausprogrammiert werden — sie kommt aus SportClassGroupMember, derselben Tabelle, die das
 * Cup-Modul (GroupResolverService) und das Richtzeiten-Modul (DisabilityGroupGrouper) nutzen.
 */
final readonly class AnnualBestService
{
    /**
     * @return Collection<int, array{gender: string, group: ?SportClassGroup, results: Collection<int, Result>}>
     */
    public function forYear(int $year): Collection
    {
        $bestByAthlete = $this->bestResultPerAthlete($year);

        $memberMap = SportClassGroupMember::pluck('sport_class_group_id', 'sport_class');
        $groups = SportClassGroup::active()->orderBy('sort_order')->get();

        $buckets = collect();

        foreach (['M', 'F'] as $gender) {
            $genderResults = $bestByAthlete->filter(
                fn (Result $result) => strtoupper((string) $result->athlete?->gender) === $gender
            );

            foreach ($groups as $group) {
                $groupResults = $genderResults->filter(
                    fn (Result $result) => $memberMap->get(strtoupper((string) $result->sport_class)) === $group->id
                )->sortByDesc('points')->values();

                if ($groupResults->isNotEmpty()) {
                    $buckets->push([
                        'gender' => $gender,
                        'group' => $group,
                        'results' => SportRankAssigner::assign($groupResults, fn (Result $r) => $r->points),
                    ]);
                }
            }
        }

        return $buckets;
    }

    /**
     * "Damen & Herren" je Behinderungsgruppe (Rückmeldung: "ich meinte das alle gemeinsam über
     * die Punkte gewertet werden") — nachträgliches Zusammenlegen der bereits berechneten
     * ÖBSV-Punkte beider Geschlechter und Neuvergeben des Rangs (SportRankAssigner, dieselbe
     * Tie-Break-Regel wie forYear()), gleichwertig zu einer von vornherein geschlechtsübergreifend
     * gewerteten Berechnung — die ÖBSV-Punkte selbst hängen nicht vom Geschlecht der
     * Wertungskategorie ab, nur die Rang-Konkurrenz.
     *
     * @return Collection<int, array{gender: null, group: SportClassGroup, results: Collection<int, Result>}>
     */
    public function mergedGenderBuckets(int $year): Collection
    {
        $bestByAthlete = $this->bestResultPerAthlete($year);
        $memberMap = SportClassGroupMember::pluck('sport_class_group_id', 'sport_class');
        $groups = SportClassGroup::active()->orderBy('sort_order')->get();

        return $groups
            ->map(function (SportClassGroup $group) use ($bestByAthlete, $memberMap) {
                $results = $bestByAthlete
                    ->filter(fn (Result $result) => $memberMap->get(strtoupper((string) $result->sport_class)) === $group->id)
                    ->sortByDesc('points')
                    ->values();

                return $results->isEmpty() ? null : [
                    'gender' => null,
                    'group' => $group,
                    'results' => SportRankAssigner::assign($results, fn (Result $r) => $r->points),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * "Alle Klassen" je Geschlecht, inklusive null ("Damen & Herren") als geschlechts- UND
     * klassenübergreifende Gesamtwertung — Gegenstück zu mergedGenderBuckets() für die andere
     * Sammel-Option der Rückmeldung.
     *
     * @return Collection<int, array{gender: ?string, group: null, results: Collection<int, Result>}>
     */
    public function mergedGroupBuckets(int $year): Collection
    {
        $bestByAthlete = $this->bestResultPerAthlete($year);

        return collect(['M', 'F', null])
            ->map(function (?string $gender) use ($bestByAthlete) {
                $results = $bestByAthlete
                    ->when(
                        $gender !== null,
                        fn (Collection $c) => $c->filter(fn (Result $r) => strtoupper((string) $r->athlete?->gender) === $gender)
                    )
                    ->sortByDesc('points')
                    ->values();

                return $results->isEmpty() ? null : [
                    'gender' => $gender,
                    'group' => null,
                    'results' => SportRankAssigner::assign($results, fn (Result $r) => $r->points),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Pro Athlet genau ein Ergebnis: das mit den meisten ÖBSV-Punkten im Kalenderjahr. Bei
     * Punktegleichstand entscheidet die Reihenfolge der Datenbank (stabil, aber willkürlich) —
     * die Spec verlangt hier keine weitere Tie-Break-Regel.
     *
     * @return Collection<int, Result>
     */
    private function bestResultPerAthlete(int $year): Collection
    {
        return Result::query()
            ->whereNull('status')
            ->whereNotNull('points')
            ->whereHas('swimEvent', fn ($q) => $q->where('relay_count', 1))
            ->whereHas('meet', fn ($q) => $q->whereBetween('start_date', ["$year-01-01", "$year-12-31"]))
            ->with(['athlete', 'club', 'swimEvent.strokeType'])
            ->get()
            ->filter(fn (Result $result) => $result->athlete !== null
                && in_array(strtoupper((string) $result->athlete->gender), ['M', 'F'], true))
            ->groupBy('athlete_id')
            ->map(fn (Collection $results) => $results->sortByDesc('points')->first())
            ->values();
    }
}
