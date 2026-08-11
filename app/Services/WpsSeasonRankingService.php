<?php

namespace App\Services;

use App\Models\AgeGroup;
use App\Support\WpsRankingEntry;
use App\Support\WpsRankingFilter;
use Illuminate\Support\Collection;

/**
 * WpsSeasonRankingService
 *
 * Saison-, Jugend- und Bewerbsranglisten (Spec "WPS Rankings" §6.2, §6.3, §6.4).
 *
 * Je Athlet und Bewerb zählt die beste Leistung des Zeitraums (§4) — anders als in der
 * Veranstaltungsrangliste, wo jedes Ergebnis für sich steht.
 *
 * Rein lesend; nichts wird gespeichert (**[R4]**).
 */
final readonly class WpsSeasonRankingService
{
    public function __construct(
        private WpsResultSelectionService $selection,
        private GroupResolverService $groupResolver,
    ) {}

    /**
     * @return Collection<int, WpsRankingEntry>
     */
    public function ranking(WpsRankingFilter $filter): Collection
    {
        $eintraege = $this->selection->bestPerAthleteAndEvent(
            $this->selection->select($filter)
        );

        return $this->selection->rank(
            $this->applyAgeGroup($this->applyAgeLimit($eintraege, $filter), $filter)
        );
    }

    /**
     * Athleten ohne Geburtsdatum — für den Sammelposten unterhalb der Liste (§5).
     *
     * Sie werden aus Altersranglisten ausgeschlossen, aber sichtbar ausgewiesen, statt still
     * zu verschwinden. Ohne Altersgrenze gibt es keinen Sammelposten: Dann ist niemand
     * ausgeschlossen worden.
     *
     * @return Collection<int, WpsRankingEntry>
     */
    public function withoutBirthDate(WpsRankingFilter $filter): Collection
    {
        // Ohne Alterseinschränkung ist niemand ausgeschlossen worden — dann gibt es auch
        // keinen Sammelposten.
        if ($filter->maxAge === null && $filter->ageGroupId === null) {
            return collect();
        }

        return $this->selection
            ->bestPerAthleteAndEvent($this->selection->select($filter))
            ->filter(static fn (WpsRankingEntry $e): bool => ! $e->hasAge())
            ->values();
    }

    /**
     * Schränkt auf eine Altersgruppe ein (§5).
     *
     * Verwendet die bestehende `AgeGroup`-Struktur des Cup-Moduls, und zwar deren **statische**
     * Grenzen — nicht die cupabhängige Übersteuerung aus `GroupResolverService`, die an
     * Sportklassengruppen und Cup-Einstellungen hängt. Eine zweite Altersgruppentabelle
     * daneben hieße, dieselbe Gruppe an zwei Stellen zu pflegen.
     *
     * Athleten ohne Geburtsdatum fallen heraus und erscheinen im Sammelposten.
     *
     * @param  Collection<int, WpsRankingEntry>  $eintraege
     * @return Collection<int, WpsRankingEntry>
     */
    private function applyAgeGroup(Collection $eintraege, WpsRankingFilter $filter): Collection
    {
        if ($filter->ageGroupId === null) {
            return $eintraege;
        }

        $gruppe = $this->groupResolver->loadAgeGroups()
            ->first(static fn (AgeGroup $g): bool => $g->getKey() === $filter->ageGroupId);

        if ($gruppe === null) {
            return $eintraege;
        }

        return $eintraege
            ->filter(static fn (WpsRankingEntry $e): bool => $e->hasAge() && $gruppe->matchesAge($e->age))
            ->values();
    }

    /**
     * Wendet die Altersobergrenze der Jugendrangliste an (§6.3).
     *
     * Das Alter ist das zum 31.12. des Wettkampfjahres erreichte (§5); "U18" heißt damit,
     * dass der Athlet im Wettkampfjahr höchstens 18 wird — eine Jahrgangsgrenze.
     *
     * @param  Collection<int, WpsRankingEntry>  $eintraege
     * @return Collection<int, WpsRankingEntry>
     */
    private function applyAgeLimit(Collection $eintraege, WpsRankingFilter $filter): Collection
    {
        if ($filter->maxAge === null) {
            return $eintraege;
        }

        return $eintraege
            ->filter(static fn (WpsRankingEntry $e): bool => $e->hasAge() && $e->age <= $filter->maxAge)
            ->values();
    }
}
