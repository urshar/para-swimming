<?php

namespace App\Support;

use App\Models\Athlete;
use Illuminate\Support\Collection;

/**
 * Das WPS-Profil eines Athleten (Spec "WPS Rankings" §7.2).
 *
 * Zeigt die Leistungsentwicklung über die gesamte Historie, wahlweise auf einen Zeitraum
 * eingeschränkt.
 */
final readonly class WpsAthleteProfile
{
    /**
     * @param  Collection<string, Collection<int, WpsAthleteSeasonEntry>>  $byEvent
     *                                                                               Zeilen je Bewerb, innerhalb chronologisch
     * @param  array<string, list<string>>  $sportClassesByCategory
     *                                                               verwendete Sportklassen je Kategorie (S, SB, SM) über den Zeitraum
     */
    public function __construct(
        public Athlete $athlete,
        public Collection $byEvent,
        public array $sportClassesByCategory,
        public ?int $firstYear,
        public ?int $lastYear,
    ) {}

    public function isEmpty(): bool
    {
        return $this->byEvent->isEmpty();
    }

    /**
     * Ist der Athlet im Zeitraum in mehreren Klassen derselben Kategorie gestartet?
     *
     * Dann sind seine Punkte nur eingeschränkt vergleichbar (§7.2). Sportklassen werden im
     * Datenmodell nicht historisiert — `athlete_sport_classes` führt genau eine Klasse je
     * Kategorie. Die Historie steckt allein in `results.sport_class`, und genau deshalb muss
     * die Analyse einen Wechsel sichtbar machen: Aus dem Stammsatz wäre er nicht erkennbar.
     */
    public function hasClassChange(): bool
    {
        foreach ($this->sportClassesByCategory as $klassen) {
            if (count($klassen) > 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Die Kategorien mit mehr als einer Klasse, für den Hinweistext.
     *
     * @return array<string, list<string>>
     */
    public function changedCategories(): array
    {
        return array_filter(
            $this->sportClassesByCategory,
            static fn (array $klassen): bool => count($klassen) > 1
        );
    }

    /** Die höchste im Zeitraum erreichte Punktzahl. */
    public function bestPoints(): ?int
    {
        $punkte = $this->byEvent
            ->flatten(1)
            ->map(static fn (WpsAthleteSeasonEntry $e): int => $e->points);

        return $punkte->isEmpty() ? null : $punkte->max();
    }

    public function eventCount(): int
    {
        return $this->byEvent->count();
    }

    /** Anzahl der gewerteten Leistungen insgesamt. */
    public function entryCount(): int
    {
        return $this->byEvent->flatten(1)->count();
    }
}
