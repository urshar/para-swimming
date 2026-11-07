<?php

namespace App\Support;

use App\Models\Meet;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * ClubRankingForecast
 *
 * Prognostizierte Vereinswertung eines Cups für eines der beiden Wertungssysteme
 * (docs/specs/cup-club-ranking-forecast.md §6).
 *
 * - ranking:                prognostizierte Rangliste (StartClubRankingResult bzw.
 *                           PerformanceClubRankingResult)
 * - comparisons:            Vergleich zur aktuellen Wertung, nach club_id
 * - forecastMeets:          die prognostizierten Cup-Meets (mit Meldungen, ohne Ergebnisse)
 * - unratedEntries:         Meldungen ohne ermittelbare Punkte (nur Leistungswertung)
 * - offCourseEntryAthletes: je Prognose-Meet die Athleten, deren gewertete Meldezeit aus einer
 *                           anderen Bahnlänge stammt (nur Leistungswertung), als
 *                           [meet_id => list<athlete_id>]
 *
 * Ohne Prognose-Meet ist forecastMeets leer und die Prognose entspricht der aktuellen Wertung.
 *
 * @template TResult of StartClubRankingResult|PerformanceClubRankingResult
 */
final readonly class ClubRankingForecast
{
    /**
     * @param  Collection<int, TResult>  $ranking
     * @param  Collection<int, ClubForecastComparison>  $comparisons
     * @param  EloquentCollection<int, Meet>  $forecastMeets
     * @param  Collection<int, UnratedEntry>  $unratedEntries
     * @param  array<int, list<int>>  $offCourseEntryAthletes
     */
    public function __construct(
        public Collection $ranking,
        public Collection $comparisons,
        public EloquentCollection $forecastMeets,
        public Collection $unratedEntries,
        public array $offCourseEntryAthletes,
    ) {}

    /** Gibt es überhaupt etwas zu prognostizieren? */
    public function hasForecastMeets(): bool
    {
        return $this->forecastMeets->isNotEmpty();
    }

    /** Ist das Meet ein Prognose-Meet, d. h. stammen seine Beiträge aus Meldungen statt aus Ergebnissen? */
    public function isProjected(int $meetId): bool
    {
        return $this->forecastMeets->contains('id', $meetId);
    }

    /** Wurde die gewertete Meldezeit des Athleten in diesem Meet aus einer anderen Bahnlänge übernommen? */
    public function isOffCourse(int $meetId, int $athleteId): bool
    {
        return in_array($athleteId, $this->offCourseEntryAthletes[$meetId] ?? [], true);
    }
}
