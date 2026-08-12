<?php

namespace App\Services;

use App\Models\Championship;
use App\Models\ChampionshipStandard;
use App\Models\WpsPointVersion;
use App\Support\AthleteAge;
use App\Support\WpsRankingEntry;
use App\Support\WpsRankingFilter;
use App\Support\WpsSportClass;
use App\Support\WpsTalentEntry;
use App\Support\WpsTalentReportConfiguration;
use Illuminate\Support\Collection;

/**
 * WpsTalentReportService
 *
 * Förderauswertung / Talentsichtung (Spec "WPS Rankings" §6.6).
 *
 * Beantwortet die Kaderfrage des Verbandes — *wer hat Potenzial und soll gefördert werden?*
 * — im Unterschied zur Selektionsfrage einer konkreten Meisterschaft, die im Modul
 * `wps-qualification` liegt.
 *
 * Abgrenzung (§6.6.6): Dies ist **keine** Qualifikationsübersicht. Die Auswertung sagt nichts
 * darüber aus, ob jemand startberechtigt ist; dafür braucht es eine reale Langbahnzeit im
 * Qualifikationszeitraum (`wps-qualification` [Q4]). Auf die Normen dieses Moduls wird
 * ausschließlich zugegriffen, um die Schwelle abzuleiten.
 *
 * Rein lesend; nichts wird gespeichert.
 */
final readonly class WpsTalentReportService
{
    /**
     * Verschiebung für die absteigende Sortierung über Zeichenketten.
     *
     * Abstände können negativ sein; die Verschiebung hält den Schlüssel positiv und damit
     * gleich lang, sonst sortierte "-50" vor "10".
     */
    private const int SORT_OFFSET = 100000;

    public function __construct(
        private WpsResultSelectionService $selection,
        private WpsPointCalculator $pointCalculator,
        private WpsPointVersionResolver $versionResolver,
    ) {}

    /**
     * Die Zeilen der Auswertung, nach Altersgruppe gruppiert.
     *
     * @return Collection<string, Collection<int, WpsTalentEntry>>
     */
    public function report(WpsTalentReportConfiguration $config): Collection
    {
        $zeilen = $this->entries($config);

        /** @var Collection<string, Collection<int, WpsTalentEntry>> $gruppen */
        $gruppen = $zeilen
            ->groupBy(static fn (WpsTalentEntry $e): string => $e->group)
            ->map(static function (Collection $gruppe): Collection {
                // Nach Athlet zusammengefasst, damit dessen Bewerbe beieinanderstehen.
                // Innerhalb eines Athleten und zwischen den Athleten entscheidet der Abstand
                // zur Schwelle: Wer am weitesten darüber liegt, steht oben.
                //
                // Sortiert wird über einen zusammengesetzten Zeichenkettenschlüssel — der
                // beste Abstand des Athleten führt seine Gruppe, danach sein Name, danach der
                // Abstand der einzelnen Zeile. sortBy() mit mehreren Closures verhält sich in
                // diesem Projekt unzuverlässig (CLAUDE.md).
                $besterAbstand = $gruppe
                    ->groupBy(static fn (WpsTalentEntry $e): int => $e->athlete->getKey())
                    ->map(static fn (Collection $desAthleten): int => $desAthleten
                        ->max(static fn (WpsTalentEntry $e): int => $e->gapToThreshold()));

                return $gruppe
                    ->sortBy(static fn (WpsTalentEntry $e): string => sprintf(
                        '%09d|%s|%09d',
                        self::SORT_OFFSET - $besterAbstand[$e->athlete->getKey()],
                        $e->athlete->last_name.' '.$e->athlete->first_name,
                        self::SORT_OFFSET - $e->gapToThreshold(),
                    ))
                    ->values();
            });

        return $gruppen;
    }

    /**
     * Alle Zeilen, ungruppiert.
     *
     * @return Collection<int, WpsTalentEntry>
     */
    public function entries(WpsTalentReportConfiguration $config): Collection
    {
        $normen = $this->standardsByKey($config->reference);
        $version = $this->pointVersion($config->reference);

        if ($version === null) {
            // Ohne Punkteversion lässt sich keine Normpunktzahl bilden, und ohne die gibt es
            // keine Schwelle. Eine Auswertung mit Schwelle 0 wäre schlimmer als keine.
            return collect();
        }

        return $this->bestPerAthleteAndEvent($config)
            ->map(fn (WpsRankingEntry $eintrag): ?WpsTalentEntry => $this->toTalentEntry(
                $eintrag,
                $config,
                $normen,
                $version,
            ))
            ->filter()
            ->values();
    }

    /**
     * Athleten ohne Geburtsdatum — sichtbarer Sammelposten (§5, §6.6.3).
     *
     * Sie lassen sich keiner Altersgruppe zuordnen und fallen deshalb aus der Auswertung. Sie
     * verschwinden aber nicht still: Eine fehlende Stammdatenangabe soll auffallen, damit sie
     * ergänzt werden kann.
     *
     * @return Collection<int, WpsRankingEntry>
     */
    public function withoutBirthDate(WpsTalentReportConfiguration $config): Collection
    {
        return $this->bestPerAthleteAndEvent($config)
            ->filter(static fn (WpsRankingEntry $e): bool => ! $e->hasAge())
            ->values();
    }

    /**
     * Die Referenz-Meisterschaft für die Vorbelegung: die mit dem spätesten Ende des
     * Qualifikationszeitraums (§6.6.1).
     *
     * Das ist im Regelfall die nächste anstehende Meisterschaft — und auf die zielt eine
     * Förderentscheidung. Bevorzugt eine Europameisterschaft: Sie ist die realistische erste
     * internationale Meisterschaft. Für einen 16-Jährigen ist die Paralympics-Norm kein
     * Maßstab, sondern eine Zahl ohne Aussagekraft.
     */
    public function defaultReference(): ?Championship
    {
        $meisterschaften = Championship::query()
            ->active()
            ->has('standards')
            ->orderByDesc('qualification_end')
            ->get();

        return $meisterschaften->firstWhere('type', Championship::TYPE_EC)
            ?? $meisterschaften->first();
    }

    /**
     * Je Athlet und Bewerb die beste Leistung des Zeitraums.
     *
     * Verwendet die Ergebnisauswahl aus §4 — sie gilt für alle Auswertungen dieses Moduls.
     *
     * @return Collection<int, WpsRankingEntry>
     */
    private function bestPerAthleteAndEvent(WpsTalentReportConfiguration $config): Collection
    {
        $alle = collect();

        // Je Jahr eine Abfrage: Der Filter kennt ein Jahr, nicht einen Zeitraum. Ein
        // mehrjähriger Filter wäre die sauberere Lösung, änderte aber die Signatur des
        // gemeinsamen Filterobjekts für alle Ranglisten — dafür ist der Anlass zu klein.
        foreach (range($config->fromYear, $config->toYear) as $jahr) {
            $alle = $alle->concat($this->selection->select(new WpsRankingFilter(
                year: $jahr,
                course: $config->course,
            ))->all());
        }

        return $this->selection->bestPerAthleteAndEvent($alle);
    }

    /**
     * Wandelt eine Ranglistenzeile in eine Auswertungszeile — oder null, wenn keine Norm
     * vorliegt.
     *
     * Bewerbe ohne Norm in der Referenz-Meisterschaft haben keine Schwelle und entfallen:
     * Eine Zeile ohne Bezugsgröße wäre nicht interpretierbar.
     *
     * @param  array<string, ChampionshipStandard>  $normen
     */
    private function toTalentEntry(
        WpsRankingEntry $eintrag,
        WpsTalentReportConfiguration $config,
        array $normen,
        WpsPointVersion $version,
    ): ?WpsTalentEntry {
        if (! $eintrag->hasAge()) {
            return null;
        }

        $event = $eintrag->result->swimEvent;
        $sportClass = WpsSportClass::mapToWps($eintrag->sportClass);

        $norm = $normen[$this->standardKey(
            (int) $event->getAttribute('stroke_type_id'),
            (int) $event->getAttribute('distance'),
            (string) $eintrag->athlete->getAttribute('gender'),
            $sportClass ?? '',
        )] ?? null;

        // MQS oder MET, je nach Einstellung — die Spec sah nur die MQS vor, die sich an
        // echten Daten aber als zu scharf erwies: Fast alle Nachwuchsathleten landeten im
        // einstelligen Prozentbereich, und dort unterscheidet die Zahl nichts mehr.
        $normzeit = $norm?->getAttribute($config->normColumn());

        if ($normzeit === null) {
            return null;
        }

        // Die Normzeit ist eine Langbahnzeit; ihre Punktzahl wird deshalb auf der Bahnlänge
        // der Meisterschaft gebildet, nicht auf der der ausgewerteten Ergebnisse.
        $normpunkte = $this->pointCalculator->pointsForTime(
            $normzeit,
            $config->reference->course,
            (string) $eintrag->athlete->getAttribute('gender'),
            (int) $event->getAttribute('stroke_type_id'),
            (int) $event->getAttribute('distance'),
            $eintrag->sportClass,
            $version,
        );

        if ($normpunkte === null || $normpunkte <= 0) {
            return null;
        }

        $gruppe = $config->groupForAge($eintrag->age);

        return new WpsTalentEntry(
            $eintrag->athlete,
            AthleteAge::birthYear($eintrag->athlete),
            $eintrag->age,
            $gruppe,
            $eintrag->sportClass,
            $eintrag->eventLabel,
            $eintrag->swimTime,
            $eintrag->course,
            $eintrag->estimatedLcmTime,
            $eintrag->points,
            $config->thresholdPoints($normpunkte, $gruppe),
            $normzeit,
            $normpunkte,
            $eintrag->meetName,
            $eintrag->meetDate,
        );
    }

    /**
     * Die WPS-Punkteversion, mit der die Normpunktzahl gebildet wird.
     *
     * Stichtag ist das Ende des Qualifikationszeitraums der Referenz-Meisterschaft — dieselbe
     * Wahl wie bei der Punktanzeige neben den Normen in `wps-qualification`.
     *
     * **Für mehrjährige Auswertungen wird damit eine Norm über den gesamten Zeitraum
     * festgehalten** (§6.6.1), nicht jährlich nachgezogen: MQS-Zeiten schwanken zwischen den
     * Ausgaben, und ein jährlicher Wechsel ließe einen Athleten schlechter dastehen, obwohl er
     * sich verbessert hat.
     */
    private function pointVersion(Championship $reference): ?WpsPointVersion
    {
        return $this->versionResolver->resolveForDate(
            $reference->qualification_end->format('Y-m-d')
        );
    }

    /**
     * Normen der Referenz-Meisterschaft, nach Merkmalskombination indiziert.
     *
     * Einmal geladen statt je Zeile abgefragt. Die Zuordnung 21 → 14 greift vor dem Abgleich.
     *
     * @return array<string, ChampionshipStandard>
     */
    private function standardsByKey(Championship $championship): array
    {
        return $championship->standards()
            ->get()
            ->keyBy(fn (ChampionshipStandard $s): string => $this->standardKey(
                (int) $s->getAttribute('stroke_type_id'),
                (int) $s->getAttribute('distance'),
                (string) $s->getAttribute('gender'),
                WpsSportClass::mapToWps($s->getAttribute('sport_class')) ?? '',
            ))
            ->all();
    }

    private function standardKey(int $strokeTypeId, int $distance, string $gender, string $sportClass): string
    {
        return implode('|', [$strokeTypeId, $distance, $gender, $sportClass]);
    }
}
