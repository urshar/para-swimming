<?php

namespace App\Services;

use App\Models\Championship;
use App\Support\QualificationAthleteSummary;
use App\Support\QualificationRankingEntry;
use App\Support\QualificationRow;
use Illuminate\Support\Collection;

/**
 * QualificationSelectionService
 *
 * Auswahl-Rangliste (Spec §8). Beantwortet die dritte Frage des Moduls — *wer fährt?* — und
 * greift erst, wenn aus der Qualifikantenliste mehr Namen kommen als Startplätze da sind.
 *
 * Rein lesend; nichts wird gespeichert. Baut auf
 * QualificationEvaluationService::qualificationOverview() auf: Die Entscheidung, ob eine Norm
 * erfüllt ist, bleibt an einer einzigen Stelle.
 *
 * Nach Punkten, nicht nach Zeit
 * -----------------------------
 * Zeiten sind über Bewerbe und Sportklassen hinweg nicht vergleichbar — 1:13 in S7 über
 * 100 m Freistil und 1:13 in S9 über 100 m Brust sagen nichts Gemeinsames aus. WPS-Punkte
 * schon; sie sind genau dafür gemacht.
 *
 * Die Punkte liegen bereits an den Ergebnissen (results.wps_points) und werden nicht neu
 * berechnet. Damit stimmt die Rangliste zwangsläufig mit dem überein, was anderswo im System
 * an diesem Ergebnis steht.
 */
final readonly class QualificationSelectionService
{
    public function __construct(
        private QualificationEvaluationService $evaluationService
    ) {}

    /**
     * Ranglisten je Bewerb, Geschlecht und Sportklasse.
     *
     * Grundlage sind ausschließlich Nachweise (§7.5): reale Zeiten auf der Bahnlänge der
     * Meisterschaft aus WPS-anerkannten Wettkämpfen unterhalb der MQS. Umgerechnete Zeiten
     * und `met_only` gehen nicht ein — wer nicht qualifiziert ist, steht in keiner
     * Auswahlliste.
     *
     * @return Collection<string, Collection<int, QualificationRankingEntry>>
     */
    public function rankingByEvent(Championship $championship, ?int $clubId): Collection
    {
        /** @var Collection<string, Collection<int, QualificationRankingEntry>> $gruppen */
        $gruppen = $this->proofRows($championship, $clubId)
            ->groupBy(static fn (QualificationRankingEntry $eintrag): string => sprintf(
                '%s · %s · %s',
                $eintrag->row->eventLabel,
                $eintrag->athlete->gender === 'M' ? 'männlich' : 'weiblich',
                $eintrag->row->sportClass,
            ))
            ->map(fn (Collection $gruppe): Collection => $this->rank($gruppe))
            ->sortKeys();

        return $gruppen;
    }

    /**
     * Gesamtrangliste der Athleten.
     *
     * Gemessen wird die **beste einzelne Punktzahl** über alle Bewerbe, nicht die Summe. Eine
     * Summe belohnte, wer viele Bewerbe schwimmt, und das sagt über internationale Chancen
     * nichts: Ein Athlet mit 850 Punkten in einem Bewerb ist stärker aufgestellt als einer
     * mit fünfmal 700.
     *
     * Die Zeile nennt deshalb auch, aus welchem Bewerb die Bestpunktzahl stammt, und wie
     * viele Normen der Athlet insgesamt erfüllt hat — beides gehört zur Einschätzung, ohne
     * die Reihenfolge zu bestimmen.
     *
     * @return Collection<int, QualificationRankingEntry>
     */
    public function rankingByAthlete(Championship $championship, ?int $clubId): Collection
    {
        $beste = $this->proofRows($championship, $clubId)
            ->groupBy(static fn (QualificationRankingEntry $eintrag): int => $eintrag->athlete->getKey())
            ->map(static fn (Collection $desAthleten): QualificationRankingEntry => $desAthleten
                ->sortByDesc(static fn (QualificationRankingEntry $e): int => $e->points ?? -1)
                ->first())
            ->values();

        return $this->rank($beste);
    }

    /**
     * Alle nachgewiesenen Bewerbszeilen als noch unplatzierte Einträge.
     *
     * @return Collection<int, QualificationRankingEntry>
     */
    private function proofRows(Championship $championship, ?int $clubId): Collection
    {
        return $this->evaluationService->qualificationOverview($championship, $clubId)
            ->flatMap(static function (QualificationAthleteSummary $eintrag): Collection {
                $erfuellt = $eintrag->mqsCount();

                return $eintrag->rows
                    ->filter(static fn (QualificationRow $zeile): bool => $zeile->status->isProof())
                    ->map(static fn (QualificationRow $zeile): QualificationRankingEntry => new QualificationRankingEntry(
                        null,
                        $eintrag->athlete,
                        $zeile,
                        $zeile->points(),
                        $erfuellt,
                    ));
            })
            ->values();
    }

    /**
     * Vergibt die Ränge nach Punkten absteigend.
     *
     * Punktgleiche teilen sich den Rang, der darauffolgende Rang springt entsprechend (1, 2,
     * 2, 4) — wie in der Cup-Wertung.
     *
     * Einträge ohne Punktzahl behalten `rank = null` und stehen am Ende. Sie entstehen, wenn
     * für die Kombination aus Bewerb, Geschlecht und Sportklasse kein Parametersatz vorliegt.
     * Sie mit null Punkten einzusortieren behauptete, die Leistung sei die schlechteste; sie
     * wegzulassen behauptete, es gebe sie nicht.
     *
     * @param  Collection<int, QualificationRankingEntry>  $eintraege
     * @return Collection<int, QualificationRankingEntry>
     */
    private function rank(Collection $eintraege): Collection
    {
        $mitPunkten = $eintraege
            ->filter(static fn (QualificationRankingEntry $e): bool => $e->points !== null)
            ->sortByDesc(static fn (QualificationRankingEntry $e): int => $e->points)
            ->values();

        $ohnePunkte = $eintraege
            ->filter(static fn (QualificationRankingEntry $e): bool => $e->points === null)
            ->sortBy(static fn (QualificationRankingEntry $e): string => (string) $e->athlete->last_name)
            ->values();

        $platziert = [];
        $rang = 0;
        $letztePunkte = null;

        foreach ($mitPunkten as $index => $eintrag) {
            if ($eintrag->points !== $letztePunkte) {
                $rang = $index + 1;
                $letztePunkte = $eintrag->points;
            }

            $platziert[] = new QualificationRankingEntry(
                $rang,
                $eintrag->athlete,
                $eintrag->row,
                $eintrag->points,
                $eintrag->fulfilledCount,
            );
        }

        return collect($platziert)->concat($ohnePunkte->all())->values();
    }
}
