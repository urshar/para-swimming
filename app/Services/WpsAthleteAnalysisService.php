<?php

namespace App\Services;

use App\Models\Athlete;
use App\Support\WpsAthleteProfile;
use App\Support\WpsAthleteSeasonEntry;
use App\Support\WpsRankingEntry;
use App\Support\WpsRankingFilter;
use Illuminate\Support\Collection;

/**
 * WpsAthleteAnalysisService
 *
 * Athletenanalyse (Spec "WPS Rankings" §7): Leistungsentwicklung sichtbar machen,
 * Fortschritte erkennen, Abstand zur Spitze darstellen.
 *
 * Zeigt standardmäßig die **gesamte Historie**, wahlweise auf einen Zeitraum eingeschränkt:
 * Bei einer Entwicklungsfrage ist das Weglassen früher Jahre selten gewollt, und man sieht
 * sofort, wie lange jemand schon dabei ist.
 *
 * Rein lesend; nichts wird gespeichert.
 */
final readonly class WpsAthleteAnalysisService
{
    /** Kategorien der Sportklassen — längere Präfixe zuerst, sonst passt "S" auf "SB9". */
    private const array CATEGORIES = ['SB', 'SM', 'S'];

    public function __construct(
        private WpsResultSelectionService $selection
    ) {}

    /**
     * Das Profil eines Athleten.
     *
     * @param  int|null  $fromYear  null = ab dem ersten Start
     * @param  int|null  $toYear  null = bis zum letzten Start
     * @param  string  $course  Bahnlänge; MIXED zeigt beide, mit dem Hinweis aus §11.4
     */
    public function profile(
        Athlete $athlete,
        ?int $fromYear,
        ?int $toYear,
        string $course = WpsRankingFilter::COURSE_MIXED,
    ): WpsAthleteProfile {
        $eintraege = $this->seasonBests($athlete, $fromYear, $toYear, $course);

        return new WpsAthleteProfile(
            $athlete,
            $this->groupByEvent($eintraege),
            $this->sportClassesByCategory($eintraege),
            $eintraege->min(static fn (WpsAthleteSeasonEntry $e): int => $e->year),
            $eintraege->max(static fn (WpsAthleteSeasonEntry $e): int => $e->year),
        );
    }

    /**
     * Die Jahre, in denen der Athlet überhaupt gewertete Leistungen hat.
     *
     * Grundlage der Zeitraumauswahl: Jahre ohne Starts anzubieten führt zu leeren Listen ohne
     * erkennbaren Grund.
     *
     * @return list<int>
     */
    public function yearsWithResults(Athlete $athlete): array
    {
        return collect($this->yearsWithAnyResult($athlete))->sortDesc()->values()->all();
    }

    /**
     * Beste Leistung je Saison und Bewerb, mit der Differenz zur Vorsaison.
     *
     * @return Collection<int, WpsAthleteSeasonEntry>
     */
    private function seasonBests(Athlete $athlete, ?int $fromYear, ?int $toYear, string $course): Collection
    {
        $roh = $this->allEntries($athlete, $fromYear, $toYear, $course);

        // Je Saison, Bewerb und Sportklasse die beste Leistung. Die Klasse gehört in den
        // Schlüssel: Startet jemand nach einer Umklassifizierung im selben Jahr in zwei
        // Klassen, sind das zwei verschiedene Aussagen und keine konkurrierenden Zeiten.
        $besten = $roh
            ->groupBy(static fn (WpsRankingEntry $e): string => implode('|', [
                substr((string) $e->meetDate, 0, 4),
                $e->eventLabel,
                $e->sportClass,
            ]))
            ->map(static fn (Collection $gruppe): WpsRankingEntry => $gruppe
                ->sortByDesc(static fn (WpsRankingEntry $e): int => $e->points)
                ->first());

        return $this->withDeltas($besten->values());
    }

    /**
     * Ergänzt je Bewerb die Differenz zur Vorsaison.
     *
     * Verglichen wird nur bei **gleicher Sportklasse**. Nach einem Klassenwechsel bleibt die
     * Differenz null und die Zeile trägt einen Hinweis: Die Punkte sind über einen Wechsel
     * hinweg nicht vergleichbar, und eine Zahl an dieser Stelle behauptete eine Entwicklung,
     * die es so nicht gab.
     *
     * @param  Collection<int, WpsRankingEntry>  $eintraege
     * @return Collection<int, WpsAthleteSeasonEntry>
     */
    private function withDeltas(Collection $eintraege): Collection
    {
        /** @var Collection<int, WpsAthleteSeasonEntry> $ergebnis */
        $ergebnis = $eintraege
            ->groupBy(static fn (WpsRankingEntry $e): string => $e->eventLabel)
            ->map(static function (Collection $desBewerbs): Collection {
                $chronologisch = $desBewerbs
                    ->sortBy(static fn (WpsRankingEntry $e): string => (string) $e->meetDate)
                    ->values();

                $zeilen = [];
                $vorherige = null;

                foreach ($chronologisch as $eintrag) {
                    $klassenwechsel = $vorherige !== null && $vorherige->sportClass !== $eintrag->sportClass;
                    $vergleichbar = $vorherige !== null && ! $klassenwechsel;

                    $zeilen[] = new WpsAthleteSeasonEntry(
                        (int) substr((string) $eintrag->meetDate, 0, 4),
                        $eintrag->eventLabel,
                        $eintrag->sportClass,
                        $eintrag->swimTime,
                        $eintrag->course,
                        $eintrag->estimatedLcmTime,
                        $eintrag->points,
                        $eintrag->calculationType,
                        $eintrag->meetName,
                        $eintrag->meetDate,
                        $vergleichbar ? $eintrag->points - $vorherige->points : null,
                        $vergleichbar ? $eintrag->swimTime - $vorherige->swimTime : null,
                        $klassenwechsel,
                    );

                    $vorherige = $eintrag;
                }

                return collect($zeilen);
            })
            ->flatten(1)
            ->values();

        return $ergebnis;
    }

    /**
     * Zeilen je Bewerb, Bewerbe nach der besten erreichten Punktzahl.
     *
     * Der stärkste Bewerb steht oben — das ist die Reihenfolge, in der man ein Profil liest.
     *
     * @param  Collection<int, WpsAthleteSeasonEntry>  $eintraege
     * @return Collection<string, Collection<int, WpsAthleteSeasonEntry>>
     */
    private function groupByEvent(Collection $eintraege): Collection
    {
        /** @var Collection<string, Collection<int, WpsAthleteSeasonEntry>> $gruppen */
        $gruppen = $eintraege
            ->groupBy(static fn (WpsAthleteSeasonEntry $e): string => $e->eventLabel)
            ->map(static fn (Collection $desBewerbs): Collection => $desBewerbs
                ->sortBy(static fn (WpsAthleteSeasonEntry $e): int => $e->year)
                ->values())
            ->sortByDesc(static fn (Collection $desBewerbs): int => $desBewerbs
                ->max(static fn (WpsAthleteSeasonEntry $e): int => $e->points));

        return $gruppen;
    }

    /**
     * Die im Zeitraum verwendeten Sportklassen je Kategorie.
     *
     * @param  Collection<int, WpsAthleteSeasonEntry>  $eintraege
     * @return array<string, list<string>>
     */
    private function sportClassesByCategory(Collection $eintraege): array
    {
        $nachKategorie = [];

        foreach ($eintraege->pluck('sportClass')->unique() as $klasse) {
            $kategorie = $this->categoryOf((string) $klasse);

            if ($kategorie === null) {
                continue;
            }

            $nachKategorie[$kategorie][] = (string) $klasse;
        }

        foreach ($nachKategorie as $kategorie => $klassen) {
            $nachKategorie[$kategorie] = collect($klassen)->unique()->sort()->values()->all();
        }

        return $nachKategorie;
    }

    /**
     * Kategorie einer Sportklasse: S, SB oder SM.
     *
     * Längere Präfixe werden zuerst geprüft — sonst passte "S" auf "SB9" und die Kategorien
     * liefen zusammen. Dieselbe Reihenfolge wie im Regex von WpsSportClass.
     */
    private function categoryOf(string $sportClass): ?string
    {
        foreach (self::CATEGORIES as $kategorie) {
            if (str_starts_with($sportClass, $kategorie)) {
                return $kategorie;
            }
        }

        return null;
    }

    /**
     * Alle gewerteten Ergebnisse des Athleten im Zeitraum.
     *
     * Der gemeinsame Filter kennt ein Jahr, keinen Zeitraum; über die Jahre wird deshalb
     * einzeln abgefragt. Ohne Angabe liefert `yearsWithResults()` die Jahre, in denen es
     * überhaupt etwas gibt — eine Schleife über alle denkbaren Jahre wäre Verschwendung.
     *
     * @return Collection<int, WpsRankingEntry>
     */
    private function allEntries(Athlete $athlete, ?int $fromYear, ?int $toYear, string $course): Collection
    {
        $jahre = $fromYear === null || $toYear === null
            ? $this->yearsWithAnyResult($athlete)
            : range($fromYear, $toYear);

        $alle = collect();

        foreach ($jahre as $jahr) {
            if ($fromYear !== null && $jahr < $fromYear) {
                continue;
            }

            if ($toYear !== null && $jahr > $toYear) {
                continue;
            }

            $alle = $alle->concat(
                $this->selection->select(new WpsRankingFilter(year: $jahr, course: $course))
                    ->filter(static fn (WpsRankingEntry $e): bool => $e->athlete->getKey() === $athlete->getKey())
                    ->all()
            );
        }

        return $alle->values();
    }

    /**
     * Jahre mit mindestens einem Wettkampfstart — Grundlage für die Jahresschleife.
     *
     * Direkt über die Ergebnisse des Athleten, damit nicht über Jahrzehnte iteriert wird.
     *
     * @return list<int>
     */
    private function yearsWithAnyResult(Athlete $athlete): array
    {
        return $athlete->results()
            ->with('meet')
            ->get()
            ->map(static fn ($result): ?int => $result->meet?->start_date?->year)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
