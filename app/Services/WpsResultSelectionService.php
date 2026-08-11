<?php

namespace App\Services;

use App\Models\Result;
use App\Support\AthleteAge;
use App\Support\WpsRankingEntry;
use App\Support\WpsRankingFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * WpsResultSelectionService
 *
 * Die Ergebnisauswahl nach Spec "WPS Rankings" §4 — verbindlich für **alle** Ranglisten.
 *
 * Bewusst ein eigener Service und nicht Teil der Fassade: Die Regeln gelten für Saison-,
 * Veranstaltungs- und Bewerbsranglisten gleichermaßen, und sie zweimal auszuprogrammieren
 * hieße, sie zweimal falsch pflegen zu können.
 *
 * Rein lesend. Punkte werden **nicht** neu berechnet, sondern aus `results.wps_points`
 * gelesen (**[R3]**): Die verwendete Version steht am Ergebnis selbst und ist damit
 * nachvollziehbar. Eine Rangliste, die still mit einer anderen Version rechnet als am
 * Ergebnis vermerkt, wäre nicht reproduzierbar.
 */
final readonly class WpsResultSelectionService
{
    /**
     * Ergebnisstatus ohne wertbare Leistung.
     *
     * Diese haben ohnehin keine WPS-Punkte; der Filter ist eine zusätzliche Absicherung (§4).
     * EXH fehlt hier bewusst — dazu unten.
     */
    private const array NON_SCORING_STATUSES = ['DNS', 'DNF', 'DSQ', 'SICK', 'WDR'];

    /**
     * Obergrenze für die Umkehrung der Punktsortierung.
     *
     * Die WPS-Formel ist nach oben durch den Parameter a begrenzt (rund 1200); 999999 liegt
     * weit darüber und lässt auch künftige Parametersätze zu, ohne dass die Sortierung kippt.
     */
    private const int MAX_POINTS = 999999;

    public function __construct(
        private AthleteKaderResolver $kaderResolver
    ) {}

    /**
     * Alle Ergebnisse, die dem Filter entsprechen — noch ohne Bestenauswahl und Platzierung.
     *
     * @return Collection<int, WpsRankingEntry>
     */
    public function select(WpsRankingFilter $filter): Collection
    {
        $eintraege = $this->query($filter)
            ->get()
            ->filter(static fn (Result $r): bool => $r->athlete !== null
                && $r->swimEvent !== null
                && $r->meet !== null)
            ->map(fn (Result $r): WpsRankingEntry => $this->toEntry($r));

        return $this->applyKaderFilter($eintraege, $filter)->values();
    }

    /**
     * Je Athlet und Bewerb die beste Leistung (§4).
     *
     * Maßgeblich ist die höchste Punktzahl. Bei Gleichstand entscheidet die schnellere Zeit,
     * danach das frühere Wettkampfdatum — wer dieselbe Punktzahl früher erreicht hat, hat sie
     * länger bestätigt.
     *
     * Die Gruppierung schließt die Bahnlänge ein, solange nicht gemischt wird: Eine
     * Langbahn- und eine Kurzbahnleistung im selben Bewerb sind zwei verschiedene Aussagen.
     *
     * @param  Collection<int, WpsRankingEntry>  $eintraege
     * @return Collection<int, WpsRankingEntry>
     */
    public function bestPerAthleteAndEvent(Collection $eintraege): Collection
    {
        /** @var Collection<int, WpsRankingEntry> $beste */
        $beste = $eintraege
            ->groupBy(static fn (WpsRankingEntry $e): string => implode('|', [
                $e->athlete->getKey(),
                $e->eventLabel,
                $e->sportClass,
                $e->course,
            ]))
            ->map(fn (Collection $gruppe): WpsRankingEntry => $gruppe
                ->sortBy(fn (WpsRankingEntry $e): string => $this->sortKey($e))
                ->first())
            ->values();

        return $beste;
    }

    /**
     * Vergibt die Ränge nach Punkten absteigend.
     *
     * Punktgleiche teilen sich den Rang, der darauffolgende Rang springt entsprechend
     * (1, 2, 2, 4) — wie in der Cup-Wertung und der Auswahl-Rangliste. Sie unterschiedlich zu
     * platzieren hieße, eine Reihenfolge zu behaupten, die die Zahlen nicht hergeben.
     *
     * @param  Collection<int, WpsRankingEntry>  $eintraege
     * @return Collection<int, WpsRankingEntry>
     */
    public function rank(Collection $eintraege): Collection
    {
        $sortiert = $eintraege
            ->sortBy(fn (WpsRankingEntry $e): string => $this->sortKey($e))
            ->values();

        $platziert = [];
        $rang = 0;
        $letztePunkte = null;

        foreach ($sortiert as $index => $eintrag) {
            if ($eintrag->points !== $letztePunkte) {
                $rang = $index + 1;
                $letztePunkte = $eintrag->points;
            }

            $platziert[] = $eintrag->withRank($rang);
        }

        return collect($platziert);
    }

    /**
     * Die verwendeten WPS-Punkteversionen einer Ergebnismenge.
     *
     * Enthält eine Rangliste Ergebnisse aus mehreren Versionen, wird das im Kopfbereich
     * sichtbar gemacht (**[R3]**, §11.2) — sonst sähe eine Liste aus verschiedenen Jahrgängen
     * aus wie eine einheitlich gerechnete.
     *
     * @param  Collection<int, WpsRankingEntry>  $eintraege
     * @return list<string>
     */
    public function usedVersions(Collection $eintraege): array
    {
        return $eintraege
            ->map(static fn (WpsRankingEntry $e): ?string => $e->versionLabel)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Kaderfilter (§10).
     *
     * In PHP und nicht in der Abfrage: Die Kaderzugehörigkeit gilt zu einem Stichtag und
     * hängt an einem Gültigkeitszeitraum; das in einer Unterabfrage abzubilden wäre
     * schwerer zu lesen als der eine Durchgang hier, und die Zugehörigkeiten werden ohnehin
     * einmal gesammelt geladen.
     *
     * Stichtag ist das Ende des Auswertungsjahres, sofern es vergangen ist — eine Auswertung
     * der Saison 2024 soll auch später dieselbe Kadereinteilung zeigen.
     *
     * @param  Collection<int, WpsRankingEntry>  $eintraege
     * @return Collection<int, WpsRankingEntry>
     */
    private function applyKaderFilter(Collection $eintraege, WpsRankingFilter $filter): Collection
    {
        if (! $filter->hasKaderFilter()) {
            return $eintraege;
        }

        $stichtag = $this->kaderResolver->referenceDateForYear(
            $filter->year ?? (int) date('Y')
        );

        $kaderarten = $this->kaderResolver->byAthlete($stichtag);

        return $eintraege->filter(static fn (WpsRankingEntry $e): bool => $filter->allowsKader(
            $kaderarten[$e->athlete->getKey()]['id'] ?? null
        ));
    }

    /**
     * Zusammengesetzter Sortierschlüssel: Punkte absteigend, dann Zeit, dann Datum.
     *
     * Als Zeichenkette statt als Array von Vergleichsfunktionen: sortBy() mit mehreren
     * Closures verhält sich in diesem Projekt unzuverlässig (siehe CLAUDE.md).
     *
     * Die Punkte werden von MAX_POINTS abgezogen, weil aufsteigend sortiert wird — je mehr
     * Punkte, desto kleiner der Schlüssel. Ein Ergebnis ohne Wettkampfdatum kommt ans Ende
     * seiner Punktgruppe, nicht an den Anfang: Ein fehlendes Datum ist keine frühe
     * Bestätigung.
     */
    private function sortKey(WpsRankingEntry $eintrag): string
    {
        return sprintf(
            '%06d|%09d|%s',
            self::MAX_POINTS - $eintrag->points,
            $eintrag->swimTime,
            $eintrag->meetDate ?? '9999-99-99',
        );
    }

    /**
     * Baut die Abfrage nach den Regeln aus §4.
     *
     * Gefiltert wird so weit wie möglich in der Datenbank (§13.2); nur die Bestenauswahl und
     * die Mehrkriterien-Sortierung laufen in PHP.
     *
     * @return Builder<Result>
     */
    private function query(WpsRankingFilter $filter): Builder
    {
        $abfrage = Result::query()
            ->with(['athlete.club', 'meet', 'swimEvent.strokeType', 'wpsPointVersion'])
            // Gewertet wird ausschließlich, was eine WPS-Punktzahl trägt.
            ->whereNotNull('wps_points')
            ->whereNotNull('sport_class');

        $this->applyStatus($abfrage, $filter);
        $this->applyCourse($abfrage, $filter);
        $this->applyEvent($abfrage, $filter);
        $this->applyPeriod($abfrage, $filter);

        if ($filter->gender !== '') {
            $abfrage->whereHas('athlete', static fn ($q) => $q->where('gender', $filter->gender));
        }

        if ($filter->sportClass !== '') {
            $abfrage->where('sport_class', $filter->sportClass);
        }

        if ($filter->clubId !== null) {
            $abfrage->where('club_id', $filter->clubId);
        }

        if ($filter->minPoints !== null) {
            $abfrage->where('wps_points', '>=', $filter->minPoints);
        }

        if ($filter->calculationType !== '') {
            $abfrage->where('wps_calculation_type', $filter->calculationType);
        }

        return $abfrage;
    }

    /**
     * @param  Builder<Result>  $abfrage
     */
    private function applyStatus(Builder $abfrage, WpsRankingFilter $filter): void
    {
        $ausgeschlossen = self::NON_SCORING_STATUSES;

        // EXH ist standardmäßig ausgeschlossen und per Filter zuschaltbar (§4). Das weicht
        // bewusst von der Statistik-Konvention ab, wo EXH als Start zählt: Eine Rangliste ist
        // eine Wertung, und ein außer Konkurrenz erzieltes Ergebnis soll dort nicht
        // platziert werden.
        if (! $filter->includeExhibition) {
            $ausgeschlossen[] = 'EXH';
        }

        $abfrage->where(static function (Builder $q) use ($ausgeschlossen): void {
            $q->whereNull('status')->orWhereNotIn('status', $ausgeschlossen);
        });
    }

    /**
     * @param  Builder<Result>  $abfrage
     */
    private function applyCourse(Builder $abfrage, WpsRankingFilter $filter): void
    {
        // Staffeln sind ausgeschlossen — es gibt keine WPS-Staffelparameter (§4).
        $abfrage->whereHas('swimEvent', static fn ($q) => $q->where('relay_count', 1));

        if ($filter->isMixedCourse()) {
            return;
        }

        $abfrage->whereHas('meet', static fn ($q) => $q->where('course', $filter->course));
    }

    /**
     * @param  Builder<Result>  $abfrage
     */
    private function applyEvent(Builder $abfrage, WpsRankingFilter $filter): void
    {
        if ($filter->strokeTypeId === null && $filter->distance === null) {
            return;
        }

        $abfrage->whereHas('swimEvent', static function ($q) use ($filter): void {
            if ($filter->strokeTypeId !== null) {
                $q->where('stroke_type_id', $filter->strokeTypeId);
            }

            if ($filter->distance !== null) {
                $q->where('distance', $filter->distance);
            }
        });
    }

    /**
     * Zeitliche Abgrenzung (§6.2).
     *
     * Über `meets.start_date` mit whereBetween und ausdrücklicher Uhrzeit an der oberen
     * Grenze — kein YEAR(), das ist nicht DB-portabel. Die Uhrzeit ist zwingend: Eine
     * date-Spalte wird je nach Treiber als "2026-12-31" oder als "2026-12-31 00:00:00"
     * abgelegt, und ohne Uhrzeit fiele eine Veranstaltung am 31. Dezember im zweiten Fall
     * still aus der Auswertung.
     *
     * @param  Builder<Result>  $abfrage
     */
    private function applyPeriod(Builder $abfrage, WpsRankingFilter $filter): void
    {
        if ($filter->meetId !== null) {
            $abfrage->where('meet_id', $filter->meetId);

            return;
        }

        if ($filter->year === null) {
            return;
        }

        $abfrage->whereHas('meet', static fn ($q) => $q->whereBetween('start_date', [
            "$filter->year-01-01",
            "$filter->year-12-31 23:59:59",
        ]));
    }

    private function toEntry(Result $result): WpsRankingEntry
    {
        $event = $result->swimEvent;
        $jahr = $result->meet?->start_date?->year;

        return new WpsRankingEntry(
            null,
            $result->athlete,
            $result,
            (int) $result->getAttribute('wps_points'),
            (int) $result->getAttribute('swim_time'),
            (string) ($result->meet?->course ?? ''),
            $result->getAttribute('wps_estimated_lcm_time'),
            $result->getAttribute('wps_calculation_type'),
            $result->wpsPointVersion?->label,
            sprintf(
                '%d m %s',
                $event->getAttribute('distance'),
                $event->strokeType?->name_de ?? '',
            ),
            (string) $result->getAttribute('sport_class'),
            $result->meet?->name,
            $result->meet?->start_date?->format('Y-m-d'),
            // Alter zum 31.12. des WETTKAMPFJAHRES (§5) — dieselbe Regel wie im Cup-Modul
            // und im Qualifikationsmodul, deshalb dieselbe Klasse.
            $jahr === null ? null : AthleteAge::atEndOf($result->athlete, $jahr),
        );
    }
}
