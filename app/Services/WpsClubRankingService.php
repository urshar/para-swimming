<?php

namespace App\Services;

use App\Models\Club;
use App\Support\WpsClubRankingConfiguration;
use App\Support\WpsClubRankingDetail;
use App\Support\WpsClubRankingEntry;
use App\Support\WpsRankingEntry;
use App\Support\WpsRankingFilter;
use Illuminate\Support\Collection;

/**
 * WpsClubRankingService
 *
 * Vereinsauswertung (Spec "WPS Rankings" §9): beste Vereinsleistungen, Durchschnittswerte,
 * Zahl der Leistungen über einer Schwelle.
 *
 * Abgrenzung
 * ----------
 * Die Vereinswertung im Modul `obsv-cup-vereinswertung` ist eine **offizielle ÖBSV-Wertung**
 * mit festgelegten Regeln. Diese Auswertung hier ist ein **Analysewerkzeug** ohne offiziellen
 * Charakter — ihre Rechenweise ist wählbar, und je nach Methode ergeben sich völlig
 * verschiedene Reihenfolgen. Wer die beiden verwechselt, zieht falsche Schlüsse.
 *
 * Punkte, nicht Zeiten
 * --------------------
 * Anders als die Athletenanalyse (§7.3a) rechnet diese Auswertung mit **WPS-Punkten**: Über
 * Vereine hinweg vergleicht man verschiedene Bewerbe und Sportklassen, und dafür sind Punkte
 * gemacht. Zeiten wären hier nicht vergleichbar.
 *
 * Rein lesend; nichts wird gespeichert.
 */
final readonly class WpsClubRankingService
{
    public function __construct(
        private WpsResultSelectionService $selection
    ) {}

    /**
     * Die Vereinsauswertung.
     *
     * Vereine unterhalb der Mindestzahl gewerteter Leistungen erhalten keinen Rang, fallen
     * aber nicht heraus — sie erscheinen unterhalb der Liste. Ein Verein mit einem einzigen
     * starken Athleten soll sichtbar bleiben, statt still zu verschwinden.
     *
     * @param  int|null  $clubId  Einschränkung auf einen Verein; Vereinsnutzer sehen nur den
     *                            eigenen (**[R2]**)
     * @return Collection<int, WpsClubRankingEntry>
     */
    public function ranking(
        WpsRankingFilter $filter,
        WpsClubRankingConfiguration $config,
        ?int $clubId,
    ): Collection {
        $eintraege = $this->clubEntries($filter, $config, $clubId);

        /** @var Collection<int, WpsClubRankingEntry> $ueberMindestzahl */
        $ueberMindestzahl = $eintraege
            ->filter(static fn (WpsClubRankingEntry $e): bool => $e->entryCount >= $config->minEntriesPerClub)
            ->sortByDesc(static fn (WpsClubRankingEntry $e): float => $e->value)
            ->values();

        /** @var Collection<int, WpsClubRankingEntry> $darunter */
        $darunter = $eintraege
            ->filter(static fn (WpsClubRankingEntry $e): bool => $e->entryCount < $config->minEntriesPerClub)
            ->sortByDesc(static fn (WpsClubRankingEntry $e): float => $e->value)
            ->values();

        return $this->rank($ueberMindestzahl)->concat($darunter->all())->values();
    }

    /**
     * Die Vereine mit ihrem Wert, noch unplatziert.
     *
     * @return Collection<int, WpsClubRankingEntry>
     */
    private function clubEntries(
        WpsRankingFilter $filter,
        WpsClubRankingConfiguration $config,
        ?int $clubId,
    ): Collection {
        // Die Ergebnisauswahl aus §4 gilt auch hier; ein eigener Vereinsfilter überschreibt
        // eine etwaige Auswahl im Ranglistenfilter, weil die Sichtbarkeitsregel Vorrang hat.
        $eingeschraenkt = $clubId === null ? $filter : $filter->withClub($clubId);

        /** @var Collection<int, WpsClubRankingEntry> $vereine */
        $vereine = $this->selection->select($eingeschraenkt)
            ->filter(static fn (WpsRankingEntry $e): bool => $e->athlete->club !== null)
            ->groupBy(static fn (WpsRankingEntry $e): int => $e->athlete->club->getKey())
            ->map(fn (Collection $desVereins): WpsClubRankingEntry => $this->buildEntry($desVereins, $config))
            ->values();

        return $vereine;
    }

    /**
     * Baut den Eintrag eines Vereins.
     *
     * @param  Collection<int, WpsRankingEntry>  $entries
     */
    private function buildEntry(Collection $entries, WpsClubRankingConfiguration $config): WpsClubRankingEntry
    {
        /** @var Club $club */
        $club = $entries->first()->athlete->club;

        /** @var Collection<int, WpsClubRankingDetail> $details */
        $details = $entries
            ->groupBy(static fn (WpsRankingEntry $e): int => $e->athlete->getKey())
            ->map(fn (Collection $desAthleten): WpsClubRankingDetail => $this->buildDetail($desAthleten, $config))
            // Athleten ohne eingegangene Leistung fallen heraus: Bei der Schwellenmethode
            // erreicht nicht jeder die Schwelle, und ein Beitrag von null ist keiner.
            ->filter(static fn (WpsClubRankingDetail $d): bool => $d->entryCount > 0)
            ->sortByDesc(static fn (WpsClubRankingDetail $d): float => $d->contribution)
            ->values();

        $gewertet = $details->sum(static fn (WpsClubRankingDetail $d): int => $d->entryCount);
        $summe = $details->sum(static fn (WpsClubRankingDetail $d): float => $d->contribution);

        // Der Durchschnitt bezieht sich auf die eingegangenen Leistungen, nicht auf die
        // Athleten: Zählte man durch die Athletenzahl, ergäbe "beste zwei Leistungen je
        // Athlet" einen doppelt so hohen Wert wie "beste eine" — das wäre kein Durchschnitt.
        $wert = match ($config->method) {
            WpsClubRankingConfiguration::METHOD_AVERAGE => $gewertet > 0 ? $summe / $gewertet : 0.0,
            default => $summe,
        };

        return new WpsClubRankingEntry(
            null,
            $club,
            (float) $wert,
            $details->count(),
            $gewertet,
            $details,
        );
    }

    /**
     * Der Beitrag eines Athleten.
     *
     * @param  Collection<int, WpsRankingEntry>  $entries
     */
    private function buildDetail(
        Collection $entries,
        WpsClubRankingConfiguration $config,
    ): WpsClubRankingDetail {
        $athlet = $entries->first()->athlete;

        if ($config->countsEntries()) {
            // Bei der Zählmethode geht jede Leistung über der Schwelle ein, nicht nur die
            // besten n: Gefragt ist, wie viele Athleten ein Niveau erreichen.
            $ueberSchwelle = $entries->filter(
                static fn (WpsRankingEntry $e): bool => $e->points >= $config->threshold
            );

            return new WpsClubRankingDetail(
                $athlet,
                (float) $ueberSchwelle->count(),
                $ueberSchwelle->count(),
                $ueberSchwelle->max(static fn (WpsRankingEntry $e): int => $e->points),
            );
        }

        /** @var Collection<int, WpsRankingEntry> $besten */
        $besten = $entries
            ->sortByDesc(static fn (WpsRankingEntry $e): int => $e->points)
            ->take($config->countedPerAthlete)
            ->values();

        return new WpsClubRankingDetail(
            $athlet,
            (float) $besten->sum(static fn (WpsRankingEntry $e): int => $e->points),
            $besten->count(),
            $besten->max(static fn (WpsRankingEntry $e): int => $e->points),
        );
    }

    /**
     * Vergibt die Ränge nach Wert absteigend.
     *
     * Gleichstand teilt den Rang, der folgende springt — wie in der Cup-Wertung und den
     * übrigen Ranglisten dieses Moduls.
     *
     * @param  Collection<int, WpsClubRankingEntry>  $entries
     * @return Collection<int, WpsClubRankingEntry>
     */
    private function rank(Collection $entries): Collection
    {
        $platziert = [];
        $rang = 0;
        $letzterWert = null;

        foreach ($entries as $index => $eintrag) {
            if ($letzterWert === null || abs($eintrag->value - $letzterWert) > 0.001) {
                $rang = $index + 1;
                $letzterWert = $eintrag->value;
            }

            $platziert[] = $eintrag->withRank($rang);
        }

        return collect($platziert);
    }
}
