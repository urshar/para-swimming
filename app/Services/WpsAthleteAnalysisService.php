<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\Result;
use App\Support\WpsAthleteProfile;
use App\Support\WpsAthleteSeasonEntry;
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
 * Eigene Ergebnisauswahl, nicht die der Ranglisten
 * ------------------------------------------------
 * Die Ranglisten verlangen `wps_points > 0`, weil sie über Bewerbe und Sportklassen hinweg
 * vergleichen und dafür Punkte brauchen. Diese Analyse vergleicht **innerhalb** eines
 * Bewerbs, und dort ist die **Zeit** das natürliche Maß: unmittelbar vergleichbar bei
 * gleicher Bahnlänge, und bei jedem Ergebnis vorhanden.
 *
 * In der Praxis trugen von 215 Ergebnissen eines Athleten nur 14 eine Punktzahl. Die
 * Ranglisten-Auswahl zu übernehmen hätte 93 Prozent der Historie verworfen — Ergebnisse, die
 * für die Verlaufsfrage vollwertig sind. Punkte werden weiterhin angezeigt, wo sie vorliegen,
 * entscheiden aber nicht mehr über die Aufnahme.
 *
 * Rein lesend; nichts wird gespeichert.
 */
final readonly class WpsAthleteAnalysisService
{
    /** Kategorien der Sportklassen — längere Präfixe zuerst, sonst passt "S" auf "SB9". */
    private const array CATEGORIES = ['SB', 'SM', 'S'];

    /**
     * Ergebnisstatus ohne wertbare Leistung.
     *
     * EXH bleibt drin: Eine außer Konkurrenz geschwommene Zeit ist für die Entwicklung eines
     * Athleten eine Auskunft wie jede andere — anders als in einer Rangliste, wo sie nicht
     * platziert werden soll.
     */
    private const array NON_SCORING_STATUSES = ['DNS', 'DNF', 'DSQ', 'SICK', 'WDR'];

    /**
     * Das Profil eines Athleten.
     *
     * @param  int|null  $fromYear  null = ab dem ersten Start
     * @param  int|null  $toYear  null = bis zum letzten Start
     * @param  string  $course  Bahnlänge; MIXED zeigt beide, mit dem Hinweis aus §11.4
     */
    /**
     * @param  bool  $allStarts  true = jeder Start, false = beste Leistung je Saison
     */
    public function profile(
        Athlete $athlete,
        ?int $fromYear,
        ?int $toYear,
        string $course = WpsRankingFilter::COURSE_MIXED,
        bool $allStarts = false,
    ): WpsAthleteProfile {
        $eintraege = $allStarts
            ? $this->allStarts($athlete, $fromYear, $toYear, $course)
            : $this->seasonBests($athlete, $fromYear, $toYear, $course);

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
     * Jeder Start, chronologisch, mit der Differenz zum vorherigen Start desselben Bewerbs.
     *
     * Die Saisonbestleistung allein zeigt nicht, wie eine Entwicklung zustande kam: Ein
     * Athlet, der sich über vier Wettkämpfe stetig steigert, sieht darin genauso aus wie
     * einer mit einem einzelnen guten Tag.
     *
     * @return Collection<int, WpsAthleteSeasonEntry>
     */
    private function allStarts(Athlete $athlete, ?int $fromYear, ?int $toYear, string $course): Collection
    {
        return $this->withDeltas($this->allEntries($athlete, $fromYear, $toYear, $course));
    }

    /**
     * Beste Leistung je Saison und Bewerb.
     *
     * Maßgeblich ist die **schnellste Zeit**, nicht die höchste Punktzahl: Punkte liegen nur
     * bei einem Bruchteil der Ergebnisse vor, und innerhalb eines Bewerbs auf derselben
     * Bahnlänge ist die Zeit ohnehin das genauere Maß.
     *
     * @return Collection<int, WpsAthleteSeasonEntry>
     */
    private function seasonBests(Athlete $athlete, ?int $fromYear, ?int $toYear, string $course): Collection
    {
        $besten = $this->allEntries($athlete, $fromYear, $toYear, $course)
            ->groupBy(fn (Result $r): string => implode('|', [
                $r->meet->start_date->format('Y'),
                $this->eventLabel($r),
                $r->getAttribute('sport_class'),
                // Bahnlänge in den Schlüssel: 1:05 auf Kurzbahn und 1:05 auf Langbahn sind
                // verschiedene Leistungen, keine konkurrierenden Zeiten.
                $r->meet->getAttribute('course'),
            ]))
            ->map(static fn (Collection $gruppe): Result => $gruppe
                ->sortBy(static fn (Result $r): int => (int) $r->getAttribute('swim_time'))
                ->first());

        return $this->withDeltas($besten->values());
    }

    /**
     * Ergänzt je Bewerb die Differenz zum vorherigen Eintrag.
     *
     * Verglichen wird nur bei **gleicher Sportklasse und gleicher Bahnlänge**. Bei einem
     * Wechsel bleibt die Differenz null und die Zeile trägt einen Hinweis: Weder sind Zeiten
     * über einen Klassenwechsel hinweg vergleichbar noch über die Bahnlänge, und eine Zahl an
     * dieser Stelle behauptete eine Entwicklung, die es so nicht gab.
     *
     * @param  Collection<int, Result>  $ergebnisse
     * @return Collection<int, WpsAthleteSeasonEntry>
     */
    private function withDeltas(Collection $ergebnisse): Collection
    {
        /** @var Collection<int, WpsAthleteSeasonEntry> $zeilen */
        $zeilen = $ergebnisse
            ->groupBy(fn (Result $r): string => $this->eventLabel($r))
            ->map(function (Collection $desBewerbs): Collection {
                $chronologisch = $desBewerbs
                    ->sortBy(static fn (Result $r): string => $r->meet->start_date->format('Y-m-d'))
                    ->values();

                $ergebnis = [];
                $vorheriges = null;

                foreach ($chronologisch as $result) {
                    $klasse = (string) $result->getAttribute('sport_class');
                    $bahn = (string) $result->meet->getAttribute('course');

                    $klassenwechsel = $vorheriges !== null
                        && $vorheriges->getAttribute('sport_class') !== $klasse;

                    $bahnwechsel = $vorheriges !== null
                        && $vorheriges->meet->getAttribute('course') !== $bahn;

                    $vergleichbar = $vorheriges !== null && ! $klassenwechsel && ! $bahnwechsel;

                    $punkte = $result->getAttribute('wps_points');
                    $vorherigePunkte = $vorheriges?->getAttribute('wps_points');

                    $ergebnis[] = new WpsAthleteSeasonEntry(
                        (int) $result->meet->start_date->format('Y'),
                        $this->eventLabel($result),
                        $klasse,
                        (int) $result->getAttribute('swim_time'),
                        $bahn,
                        $result->getAttribute('wps_estimated_lcm_time'),
                        $punkte === null ? null : (int) $punkte,
                        $result->getAttribute('wps_calculation_type'),
                        $result->meet->getAttribute('name'),
                        $result->meet->start_date->format('Y-m-d'),
                        // Punktdifferenz nur, wenn BEIDE Werte vorliegen.
                        $vergleichbar && $punkte !== null && $vorherigePunkte !== null
                            ? (int) $punkte - (int) $vorherigePunkte
                            : null,
                        $vergleichbar
                            ? (int) $result->getAttribute('swim_time') - (int) $vorheriges->getAttribute('swim_time')
                            : null,
                        $klassenwechsel,
                        $result->getKey(),
                    );

                    $vorheriges = $result;
                }

                return collect($ergebnis);
            })
            ->flatten(1)
            ->values();

        return $zeilen;
    }

    /** Bezeichnung des Bewerbs, z.B. "100 m Freistil". */
    private function eventLabel(Result $result): string
    {
        $event = $result->swimEvent;

        return sprintf(
            '%d m %s',
            $event->getAttribute('distance'),
            $event->strokeType?->name_de ?? '',
        );
    }

    /**
     * Zeilen je Bewerb, Bewerbe nach der Zahl der Starts.
     *
     * Der Hauptbewerb steht oben — das ist die Reihenfolge, in der man ein Profil liest.
     *
     * @param  Collection<int, WpsAthleteSeasonEntry>  $eintraege
     * @return Collection<string, Collection<int, WpsAthleteSeasonEntry>>
     */
    private function groupByEvent(Collection $eintraege): Collection
    {
        /** @var Collection<string, Collection<int, WpsAthleteSeasonEntry>> $gruppen */
        $gruppen = $eintraege
            ->groupBy(static fn (WpsAthleteSeasonEntry $e): string => $e->eventLabel)
            // Nach Datum, nicht nach Jahr: Bei "alle Starts" liegen mehrere Zeilen im selben
            // Jahr, und die Reihenfolge innerhalb der Saison ist genau das, was die Ansicht
            // zeigen soll.
            ->map(static fn (Collection $desBewerbs): Collection => $desBewerbs
                ->sortBy(static fn (WpsAthleteSeasonEntry $e): string => (string) $e->meetDate)
                ->values())
            // Nach der Zahl der Starts: Der Bewerb, in dem jemand am häufigsten antritt, ist
            // sein Hauptbewerb — nach Punkten zu sortieren ginge nicht, weil die meisten
            // Zeilen keine haben.
            ->sortByDesc(static fn (Collection $desBewerbs): int => $desBewerbs->count());

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
     * Alle wertbaren Ergebnisse des Athleten im Zeitraum.
     *
     * Verlangt eine Zeit, keine Punktzahl. Staffeln bleiben außen vor — eine Staffelzeit
     * sagt über die Entwicklung eines Einzelnen nichts aus.
     *
     * @return Collection<int, Result>
     */
    private function allEntries(Athlete $athlete, ?int $fromYear, ?int $toYear, string $course): Collection
    {
        $abfrage = Result::query()
            ->with(['meet', 'swimEvent.strokeType', 'wpsPointVersion'])
            ->where('athlete_id', $athlete->getKey())
            ->whereNotNull('swim_time')
            ->where('swim_time', '>', 0)
            ->whereNotNull('sport_class')
            ->where(static function ($query): void {
                $query->whereNull('status')
                    ->orWhereNotIn('status', self::NON_SCORING_STATUSES);
            })
            ->whereHas('swimEvent', static fn ($query) => $query->where('relay_count', 1));

        if ($course !== WpsRankingFilter::COURSE_MIXED) {
            $abfrage->whereHas('meet', static fn ($query) => $query->where('course', $course));
        }

        if ($fromYear !== null || $toYear !== null) {
            $von = ($fromYear ?? 1900).'-01-01';
            $bis = ($toYear ?? 2999).'-12-31 23:59:59';

            // whereBetween mit ausdrücklicher Uhrzeit an der oberen Grenze — YEAR() ist nicht
            // DB-portabel, und ohne Uhrzeit fiele der 31. Dezember je nach Treiber heraus.
            $abfrage->whereHas('meet', static fn ($query) => $query->whereBetween('start_date', [$von, $bis]));
        }

        return $abfrage->get()
            ->filter(static fn (Result $r): bool => $r->swimEvent !== null && $r->meet !== null)
            ->values();
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
            ->map(static fn (Result $result): ?int => $result->meet?->start_date?->year)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
