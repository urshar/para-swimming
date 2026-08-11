<?php

namespace App\Services;

use App\Support\WpsRankingEntry;
use App\Support\WpsRankingFilter;
use Illuminate\Support\Collection;

/**
 * WpsRankingService
 *
 * Fassade des Ranglistenmoduls (Spec "WPS Rankings" §13.1): wählt anhand der Ranglistenart
 * den passenden Teilservice.
 *
 * Enthält bewusst **keine** eigene Auswertungslogik — dasselbe Muster wie `StatisticsService`.
 * Die Ergebnisauswahl nach §4 liegt in `WpsResultSelectionService` und gilt für alle Arten.
 */
final readonly class WpsRankingService
{
    public function __construct(
        private WpsSeasonRankingService $seasonRanking,
        private WpsMeetRankingService $meetRanking,
        private WpsResultSelectionService $selection,
    ) {}

    /**
     * @return Collection<int, WpsRankingEntry>
     */
    public function ranking(WpsRankingFilter $filter): Collection
    {
        return match ($filter->type) {
            WpsRankingFilter::TYPE_MEET => $this->meetRanking->ranking($filter),
            default => $this->seasonRanking->ranking($filter),
        };
    }

    /**
     * Athleten ohne Geburtsdatum, die durch eine Altersgrenze herausgefallen sind (§5).
     *
     * @return Collection<int, WpsRankingEntry>
     */
    public function withoutBirthDate(WpsRankingFilter $filter): Collection
    {
        return $filter->type === WpsRankingFilter::TYPE_MEET
            ? collect()
            : $this->seasonRanking->withoutBirthDate($filter);
    }

    /**
     * Die verwendeten WPS-Punkteversionen — für den Kopfbereich (**[R3]**, §11.2).
     *
     * @param  Collection<int, WpsRankingEntry>  $eintraege
     * @return list<string>
     */
    public function usedVersions(Collection $eintraege): array
    {
        return $this->selection->usedVersions($eintraege);
    }
}
