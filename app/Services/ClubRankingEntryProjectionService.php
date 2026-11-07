<?php

namespace App\Services;

use App\Models\BaseTimeVersion;
use App\Models\Cup;
use App\Models\Entry;
use App\Models\Meet;
use App\Models\Result;
use App\Models\SportClassGroup;
use App\Support\EntryPointsProjection;
use App\Support\UnratedEntry;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * ClubRankingEntryProjectionService
 *
 * Leitet für die Prognose der Vereinswertung (docs/specs/cup-club-ranking-forecast.md) aus den
 * Meldungen eines noch nicht geschwommenen Cup-Meets ("Prognose-Meet") die Zeilen ab, die die
 * beiden Wertungsberechnungen erwarten:
 *
 *   - projectStarts()        → Starts für StartBasedClubRankingService::rankStarts() (§4)
 *   - projectDailyResults()  → Tageswertungs-Zeilen für
 *                              PerformanceBasedClubRankingService::rankDailyResults() (§5)
 *
 * Gewertet werden nur reguläre Einzel-Meldungen: Status EXH, RJC, SICK und WDR sowie
 * Staffelbewerbe (swim_events.relay_count > 1) fallen weg. Verein ist der meldende Verein
 * (entries.club_id).
 *
 * Die Punkte werden nicht eigens berechnet: Aus jeder Meldung entsteht ein nicht gespeichertes
 * Result (Schwimmzeit = Meldezeit, Sportklasse der Meldung), das dieselben Services durchläuft
 * wie die echte Tageswertung (DailyRankingService) — WorldAquaticsPointsService gegen die
 * Basiswert-Version des Cups und GroupResolverService für die Sportklassengruppe.
 */
final readonly class ClubRankingEntryProjectionService
{
    public function __construct(
        private GroupResolverService $groupResolver,
        private TopGroupClassificationService $topGroupClassificationService,
        private WorldAquaticsPointsService $pointsService,
    ) {}

    /**
     * Prognose-Meets des Cups: Cup-Meets mit Meldungen, aber ohne Ergebnisse (§3). Ein Meet mit
     * auch nur teilweisen Ergebnissen gilt als echt und wird nicht prognostiziert.
     *
     * @return EloquentCollection<int, Meet>
     */
    public function forecastMeets(Cup $cup): EloquentCollection
    {
        return Meet::where('cup_id', $cup->id)
            ->whereHas('entries')
            ->whereDoesntHave('results')
            ->oldest('start_date')
            ->get();
    }

    /**
     * Prognostizierte Starts eines Meets: jede reguläre Einzel-Meldung, zusammengefasst je
     * (Athlet, Distanz + Schwimmart) wie in der echten Startwertung. Meldungen ohne Zeit (NT)
     * zählen als Start.
     *
     * @return Collection<int, array{club_id: int, athlete_id: int, meet_id: int}>
     */
    public function projectStarts(Meet $meet): Collection
    {
        return $this->countableEntries($meet)
            ->groupBy(fn (Entry $entry): string => implode('|', [
                $entry->athlete_id,
                $entry->swimEvent->distance,
                $entry->swimEvent->stroke_type_id,
            ]))
            ->map(fn (Collection $group): array => [
                'club_id' => (int) $group->first()->club_id,
                'athlete_id' => (int) $group->first()->athlete_id,
                'meet_id' => (int) $meet->id,
            ])
            ->values();
    }

    /**
     * Prognostizierte Tageswertung eines Meets: je Athlet die punktbeste Meldung, gerechnet wie
     * die echte Tageswertung. Meldungen ohne ermittelbare Punkte werden samt Grund gesammelt.
     */
    public function projectDailyResults(Meet $meet, Cup $cup): EntryPointsProjection
    {
        $sportClassMap = $this->groupResolver->loadSportClassMap();
        $topGroupClassificationMap = $this->topGroupClassificationService->loadClassificationMap($cup);
        $cupVersion = $cup->baseTimeVersion;

        $best = [];
        $unrated = collect();

        foreach ($this->countableEntries($meet) as $entry) {
            [$points, $reason] = $this->resolveEntryPoints($entry, $meet, $cup, $sportClassMap,
                $topGroupClassificationMap, $cupVersion);

            if ($points === null) {
                $unrated->push(new UnratedEntry(
                    entryId: $entry->id,
                    athleteId: (int) $entry->athlete_id,
                    clubId: (int) $entry->club_id,
                    reason: $reason,
                ));

                continue;
            }

            $current = $best[$entry->athlete_id] ?? null;

            if (! $current || $points > $current->points) {
                $best[$entry->athlete_id] = (object) [
                    'athlete_id' => (int) $entry->athlete_id,
                    'club_id' => (int) $entry->club_id,
                    'meet_id' => (int) $meet->id,
                    'points' => $points,
                    'entry_id' => $entry->id,
                    'off_course' => $this->isOffCourse($entry, $meet),
                ];
            }
        }

        return new EntryPointsProjection(
            rows: collect(array_values($best)),
            unrated: $unrated->values(),
        );
    }

    /**
     * Reguläre Einzel-Meldungen des Meets (ohne Status, ohne Staffelbewerbe).
     *
     * @return EloquentCollection<int, Entry>
     */
    private function countableEntries(Meet $meet): EloquentCollection
    {
        return Entry::query()
            ->with(['athlete', 'club.nation', 'swimEvent.strokeType'])
            ->where('meet_id', $meet->id)
            ->whereNull('status')
            ->whereHas('swimEvent', fn ($query) => $query->where('relay_count', '<=', 1))
            ->orderBy('id')
            ->get();
    }

    /**
     * Cup-Punkte einer Meldung oder null samt Grund. Reihenfolge der Prüfungen wie in
     * DailyRankingService: erst die Punkte, dann die Sportklassengruppe.
     *
     * @param  Collection<string, SportClassGroup>  $sportClassMap
     * @param  Collection<int, bool>  $topGroupClassificationMap
     * @return array{0: ?int, 1: string}
     */
    private function resolveEntryPoints(
        Entry $entry,
        Meet $meet,
        Cup $cup,
        Collection $sportClassMap,
        Collection $topGroupClassificationMap,
        ?BaseTimeVersion $cupVersion
    ): array {
        if (! $entry->entry_time || $entry->entry_time <= 0) {
            return [null, 'keine Meldezeit (NT)'];
        }

        if (! $entry->sport_class) {
            return [null, 'keine Sportklasse in der Meldung'];
        }

        $result = $this->toResult($entry);

        [$points, $reason] = $this->pointsService->resolvePoints($result, $meet, $cupVersion);

        if ($points === null) {
            return [null, $reason];
        }

        $group = $this->groupResolver->resolveSportClassGroup($result, $cup, $sportClassMap, $topGroupClassificationMap);

        if (! $group) {
            return [null, 'Sportklasse keiner aktiven Sportklassengruppe zugeordnet'];
        }

        return [$points, ''];
    }

    /**
     * Nicht gespeichertes Result aus einer Meldung — Schwimmzeit = Meldezeit, Sportklasse der
     * Meldung, Verein = meldender Verein. Die Relationen werden direkt gesetzt, damit Punkte- und
     * Gruppen-Service ohne weitere Abfragen arbeiten.
     */
    private function toResult(Entry $entry): Result
    {
        $result = (new Result)->forceFill([
            'meet_id' => $entry->meet_id,
            'swim_event_id' => $entry->swim_event_id,
            'athlete_id' => $entry->athlete_id,
            'club_id' => $entry->club_id,
            'swim_time' => $entry->entry_time,
            'sport_class' => $entry->sport_class,
            'status' => null,
        ]);

        $result->setRelation('athlete', $entry->athlete);
        $result->setRelation('club', $entry->club);
        $result->setRelation('swimEvent', $entry->swimEvent);

        return $result;
    }

    /** Stammt die Meldezeit aus einer anderen Bahnlänge als der des Meets? */
    private function isOffCourse(Entry $entry, Meet $meet): bool
    {
        return $entry->entry_course !== null
            && $meet->course !== null
            && $entry->entry_course !== $meet->course;
    }
}
