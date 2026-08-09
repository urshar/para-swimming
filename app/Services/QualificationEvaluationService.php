<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\Championship;
use App\Models\ChampionshipStandard;
use App\Models\Result;
use App\Support\QualificationRow;
use App\Support\QualificationStatus;
use App\Support\WpsSportClass;
use Illuminate\Support\Collection;

/**
 * QualificationEvaluationService
 *
 * Bewertet Ergebnisse gegen die Normen einer Meisterschaft (Spec §7). Rein lesend —
 * nichts wird gespeichert, Übersichten werden bei jedem Aufruf berechnet (§12).
 *
 * Eine Bewertungsstelle für zwei Fragen
 * -------------------------------------
 * Die Qualifikantenliste ("wer hat sich qualifiziert") und die Förderansicht ("hat der
 * Athlet international eine Chance") teilen sich diesen Service. Getrennt sind nur die
 * Auswahl der Zeilen und die Darstellung — es darf nur eine Stelle geben, die entscheidet,
 * ob eine Norm erfüllt ist.
 *
 * Der Unterschied liegt in QualificationStatus::isProof(): Die Qualifikantenliste zeigt
 * ausschließlich Nachweise, die Förderansicht alles.
 */
final readonly class QualificationEvaluationService
{
    /** Ergebnisstatus ohne wertbare Leistung. EXH fehlt bewusst — siehe evaluate(). */
    private const array NON_SCORING_STATUSES = ['DNS', 'DNF', 'DSQ', 'SICK', 'WDR'];

    public function __construct(
        private WpsScmConversionService $conversionService
    ) {}

    /**
     * Bewertet alle Athleten einer Meisterschaft.
     *
     * @param  int|null  $clubId  auf einen Verein einschränken (Vereinsnutzer sehen nur eigene)
     * @param  int|null  $athleteId  auf einen Athleten einschränken (Förderansicht)
     * @return Collection<int, array{athlete: Athlete, rows: Collection<int, QualificationRow>}>
     */
    public function evaluate(Championship $championship, ?int $clubId, ?int $athleteId): Collection
    {
        $normen = $this->standardsByKey($championship);
        $ergebnisse = $this->relevantResults($championship, $clubId, $athleteId);

        return $ergebnisse
            ->groupBy(fn (Result $result): int => (int) $result->getAttribute('athlete_id'))
            ->map(function (Collection $desAthleten) use ($championship, $normen): array {
                $zeilen = $desAthleten
                    // Je Athlet, Bewerb und Klasse eine Zeile — die Bestleistung entscheidet.
                    ->groupBy(fn (Result $r): string => $this->rowKey($r))
                    ->map(fn (Collection $gruppe): QualificationRow => $this->buildRow($championship, $normen, $gruppe))
                    ->values();

                return [
                    'athlete' => $desAthleten->first()->athlete,
                    'rows' => $this->resolveMetOnly($zeilen),
                ];
            })
            ->values();
    }

    /**
     * Die Qualifikantenliste (Frage A): ausschließlich Nachweise.
     *
     * Umgerechnete Kurzbahnzeiten und Zeiten aus nicht sanktionierten Wettkämpfen kommen
     * hier GAR NICHT vor — nicht ausgegraut, nicht mit Hinweis, sondern nicht. In einer
     * Liste, die dem Verband vorgelegt wird, darf kein Eintrag stehen, den man für einen
     * Nachweis halten könnte ([Q4], Q-R1). met_only ebenfalls nicht: Die MET allein
     * qualifiziert niemanden.
     *
     * @return Collection<int, QualificationRow> nach Bewerb, Geschlecht, Klasse gruppierbar
     */
    public function qualified(Championship $championship, ?int $clubId): Collection
    {
        return $this->evaluate($championship, $clubId, null)
            ->flatMap(static fn (array $eintrag): Collection => $eintrag['rows']
                ->filter(static fn (QualificationRow $zeile): bool => $zeile->status->isProof())
                ->map(static fn (QualificationRow $zeile): QualificationRow => $zeile
                    ->withAthlete($eintrag['athlete'])))
            ->values();
    }

    /**
     * Ergebnisse, die wegen fehlender WPS-Anerkennung des Wettkampfs nicht als Nachweis
     * gelten, aber sonst eine Norm erfüllen würden.
     *
     * Grundlage des Hinweises über der Qualifikantenliste. Ohne ihn wäre eine leere Liste
     * nicht von einer korrekt leeren zu unterscheiden — und der wahrscheinlichste Grund ist,
     * dass die Kennzeichnung am Wettkampf schlicht noch nicht gesetzt wurde (Q-R9).
     *
     * @return Collection<int, QualificationRow>
     */
    public function excludedForMissingApproval(Championship $championship, ?int $clubId): Collection
    {
        return $this->evaluate($championship, $clubId, null)
            ->flatMap(static fn (array $eintrag): Collection => $eintrag['rows']
                ->filter(static fn (QualificationRow $zeile): bool => ! $zeile->status->meetApproved
                    && in_array($zeile->status->status, [
                        QualificationStatus::MQS_MET,
                        QualificationStatus::OBSV_MET,
                    ], true))
                ->map(static fn (QualificationRow $zeile): QualificationRow => $zeile
                    ->withAthlete($eintrag['athlete'])))
            ->values();
    }

    /**
     * Zielzeit auf der abweichenden Bahnlänge (§6), für den Trainingsalltag:
     *
     *     Zielzeit_SCM = Norm_LCM ÷ Faktor
     *
     * Fehlt ein Faktor, wird null geliefert — ein fehlender Faktor darf nicht als 1
     * behandelt werden, sonst entstünde eine Zielzeit, die keine ist.
     */
    public function targetTimeOnOtherCourse(ChampionshipStandard $standard, ?int $normCentiseconds): ?int
    {
        if ($normCentiseconds === null) {
            return null;
        }

        $faktor = $this->conversionService->resolveFactor(
            (int) $standard->getAttribute('stroke_type_id'),
            (int) $standard->getAttribute('distance'),
            WpsSportClass::mapToWps($standard->getAttribute('sport_class')) ?? '',
            $standard->getAttribute('gender'),
        );

        if ($faktor === null || $faktor->factor <= 0) {
            return null;
        }

        return (int) floor($normCentiseconds / $faktor->factor);
    }

    /**
     * Baut die Zeile eines Athleten für einen Bewerb.
     *
     * Kern der Trennung aus [Q4]: Es werden ZWEI Bestleistungen ermittelt — die beste reale
     * Zeit auf der Bahnlänge der Meisterschaft und die beste umgerechnete. Eine gemeinsame
     * Bestenermittlung verlöre die Unterscheidung genau dort, wo sie zählt: Eine schnellere
     * umgerechnete Zeit würde die langsamere reale verdrängen, und aus einem Nachweis würde
     * eine Schätzung.
     *
     * @param  array<string, ChampionshipStandard>  $normen
     * @param  Collection<int, Result>  $gruppe
     */
    private function buildRow(Championship $championship, array $normen, Collection $gruppe): QualificationRow
    {
        $erstes = $gruppe->first();
        $event = $erstes->swimEvent;
        $sportClass = WpsSportClass::mapToWps($erstes->getAttribute('sport_class'));

        $norm = $normen[$this->standardKey(
            (int) $event->getAttribute('stroke_type_id'),
            (int) $event->getAttribute('distance'),
            $erstes->athlete->getAttribute('gender'),
            $sportClass ?? '',
        )] ?? null;

        $realeBeste = $gruppe
            ->filter(fn (Result $r): bool => $r->meet?->course === $championship->course)
            ->sortBy(fn (Result $r): int => (int) $r->getAttribute('swim_time'))
            ->first();

        $aufAndererBahn = $gruppe
            ->filter(fn (Result $r): bool => $r->meet?->course !== $championship->course)
            ->map(fn (Result $r): array => ['result' => $r] + $this->convert($r, $sportClass));

        // Nicht umrechenbare Ergebnisse werden NICHT weggeworfen: Sie tragen die Begründung,
        // warum keine Umrechnung möglich war, und die gehört in die Anzeige (§6). Sie kommen
        // nur hinter die umrechenbaren, damit eine vorhandene Schätzung den Vorrang behält.
        $umgerechnetBeste = $aufAndererBahn
            ->sortBy(static fn (array $k): int => $k['estimated'] ?? PHP_INT_MAX)
            ->first();

        return new QualificationRow(
            sprintf(
                '%d m %s',
                $event->getAttribute('distance'),
                $event->strokeType?->name_de ?? '',
            ),
            (string) $erstes->getAttribute('sport_class'),
            $sportClass,
            $norm,
            $norm === null
                ? null
                : $this->targetTimeOnOtherCourse($norm, $norm->getAttribute('obsv_centiseconds')
                ?? $norm->getAttribute('mqs_centiseconds')),
            $this->determineStatus($norm, $realeBeste, $umgerechnetBeste),
            null,
            null,
        );
    }

    /**
     * Ermittelt den Status nach §7.2.
     *
     * Reihenfolge ist bedeutsam: Eine reale Zeit schlägt eine umgerechnete IMMER, auch wenn
     * die umgerechnete schneller ist. Nur die reale ist ein Nachweis.
     *
     * @param  array{result: Result, estimated: int|null, factor: float|null, note: string|null}|null  $umgerechnet
     */
    private function determineStatus(
        ?ChampionshipStandard $norm,
        ?Result $real,
        ?array $umgerechnet,
    ): QualificationStatus {
        if ($norm === null) {
            return $this->status(
                QualificationStatus::NO_STANDARD,
                $real,
                null,
                null,
                null,
                null,
                'Für diesen Bewerb und diese Klasse ist bei der Meisterschaft keine Norm ausgeschrieben.',
            );
        }

        $mqs = $norm->getAttribute('mqs_centiseconds');
        $met = $norm->getAttribute('met_centiseconds');
        $obsv = $norm->getAttribute('obsv_centiseconds');

        if ($real !== null) {
            $zeit = (int) $real->getAttribute('swim_time');
            $abstandMqs = $mqs === null ? null : $zeit - $mqs;
            $abstandObsv = $obsv === null ? null : $zeit - $obsv;

            if ($obsv !== null && $zeit <= $obsv) {
                return $this->status(QualificationStatus::OBSV_MET, $real, null, null, $abstandMqs, $abstandObsv, null);
            }

            if ($mqs !== null && $zeit <= $mqs) {
                return $this->status(QualificationStatus::MQS_MET, $real, null, null, $abstandMqs, $abstandObsv, null);
            }

            if ($met !== null && $zeit <= $met) {
                return $this->status(QualificationStatus::MET_ONLY, $real, null, null, $abstandMqs, $abstandObsv, null);
            }
        }

        // Die Schätzung greift nur, wenn gar keine reale Zeit auf der Bahnlänge der
        // Meisterschaft vorliegt. Läge eine vor und die Schätzung gewönne, verschwände die
        // reale Zeit aus der Zeile — in der Anzeige stünde "rechnerisch erreicht" bei
        // jemandem, der auf der Zielbahnlänge nachweislich langsamer war, und die Zahl, die
        // das widerlegt, wäre nirgends zu sehen. Eine reale Zeit ist der stärkere Beleg,
        // auch wenn sie ein Nein ist ([Q4]).
        if ($real === null
            && $umgerechnet !== null
            && $umgerechnet['estimated'] !== null
            && $mqs !== null
            && $umgerechnet['estimated'] <= $mqs) {
            return $this->status(
                QualificationStatus::ESTIMATED_MQS,
                $umgerechnet['result'],
                $umgerechnet['estimated'],
                $umgerechnet['factor'],
                $umgerechnet['estimated'] - $mqs,
                $obsv === null ? null : $umgerechnet['estimated'] - $obsv,
                null,
            );
        }

        // Nicht erreicht — der Abstand wird trotzdem ausgewiesen (§7.3).
        $bezug = $real ?? $umgerechnet['result'] ?? null;
        $bezugszeit = $real !== null
            ? (int) $real->getAttribute('swim_time')
            : ($umgerechnet['estimated'] ?? null);

        return $this->status(
            QualificationStatus::NOT_MET,
            $bezug,
            $real === null ? ($umgerechnet['estimated'] ?? null) : null,
            $real === null ? ($umgerechnet['factor'] ?? null) : null,
            $bezugszeit === null || $mqs === null ? null : $bezugszeit - $mqs,
            $bezugszeit === null || $obsv === null ? null : $bezugszeit - $obsv,
            $umgerechnet['note'] ?? null,
        );
    }

    private function status(
        string $status,
        ?Result $result,
        ?int $estimated,
        ?float $factor,
        ?int $gapToMqs,
        ?int $gapToObsv,
        ?string $note,
    ): QualificationStatus {
        return new QualificationStatus(
            $status,
            $result === null ? null : (int) $result->getAttribute('swim_time'),
            $result?->meet?->course,
            $estimated,
            $factor,
            $gapToMqs,
            $gapToObsv,
            (bool) ($result?->meet?->wps_approved ?? false),
            $result?->getAttribute('status') === 'EXH',
            $note,
            $result?->getKey(),
            $result?->meet?->getKey(),
            $result?->meet?->name,
            $result?->meet?->start_date?->format('Y-m-d'),
        );
    }

    /**
     * Rechnet ein Ergebnis auf die Bahnlänge der Meisterschaft um (§6).
     *
     * Fehlt ein Faktor, wird NICHT umgerechnet und die Begründung mitgeführt. Ein fehlender
     * Faktor als 1 zu behandeln erzeugte eine Zeit, die nie geschwommen wurde.
     *
     * @return array{estimated: int|null, factor: float|null, note: string|null}
     */
    private function convert(Result $result, ?string $sportClass): array
    {
        $event = $result->swimEvent;

        $faktor = $sportClass === null ? null : $this->conversionService->resolveFactor(
            (int) $event->getAttribute('stroke_type_id'),
            (int) $event->getAttribute('distance'),
            $sportClass,
            $result->athlete->getAttribute('gender'),
        );

        if ($faktor === null) {
            return [
                'estimated' => null,
                'factor' => null,
                'note' => 'Keine Umrechnung möglich — für diesen Bewerb und diese Klasse ist kein '
                    .'Umrechnungsfaktor hinterlegt.',
            ];
        }

        return [
            'estimated' => $this->conversionService->convert(
                (int) $result->getAttribute('swim_time'),
                $faktor
            ),
            'factor' => $faktor->factor,
            'note' => null,
        ];
    }

    /**
     * Löst die bedingte MET-Auswertung auf (§7.2, Q-R5).
     *
     * met_only ist nur von Belang, wenn derselbe Athlet in einem ANDEREN Bewerb die MQS
     * erfüllt hat. Das lässt sich nicht je Bewerb entscheiden, sondern erst, wenn alle
     * Bewerbe des Athleten bewertet sind — deshalb ein zweiter Durchgang.
     *
     * @param  Collection<int, QualificationRow>  $zeilen
     * @return Collection<int, QualificationRow>
     */
    private function resolveMetOnly(Collection $zeilen): Collection
    {
        $hatMqs = $zeilen->contains(
            static fn (QualificationRow $zeile): bool => in_array($zeile->status->status, [
                QualificationStatus::MQS_MET,
                QualificationStatus::OBSV_MET,
            ], true)
        );

        return $zeilen->map(static fn (QualificationRow $zeile): QualificationRow => $zeile->withMetUsable(
            $zeile->status->status === QualificationStatus::MET_ONLY ? $hatMqs : null
        ));
    }

    /**
     * Ergebnisse im Qualifikationszeitraum (§7.1).
     *
     * Der Zeitraum stammt aus der Meisterschaft. Verglichen wird über whereBetween mit
     * Datumsstrings statt über whereDate() oder YEAR() — beides ist nicht DB-portabel, und
     * start_date kann eine Uhrzeit tragen.
     *
     * @return Collection<int, Result>
     */
    private function relevantResults(Championship $championship, ?int $clubId, ?int $athleteId): Collection
    {
        [$von, $bis] = $championship->qualificationPeriodBounds();

        $abfrage = Result::query()
            ->with(['athlete.club', 'meet', 'swimEvent.strokeType'])
            ->whereNotNull('swim_time')
            ->where('swim_time', '>', 0)
            // EXH bleibt drin: Ob ein außer Konkurrenz erzieltes Ergebnis international
            // anerkannt wird, entscheidet World Para Swimming (§7.1, offener Punkt).
            // Die Kennzeichnung bleibt sichtbar.
            ->where(function ($query) {
                $query->whereNull('status')
                    ->orWhereNotIn('status', self::NON_SCORING_STATUSES);
            })
            ->whereHas('meet', static fn ($query) => $query
                ->whereBetween('start_date', [$von, "$bis 23:59:59"]))
            ->whereHas('swimEvent', static fn ($query) => $query->where('relay_count', 1))
            ->whereNotNull('sport_class');

        if ($clubId !== null) {
            $abfrage->whereHas('athlete', static fn ($query) => $query->where('club_id', $clubId));
        }

        if ($athleteId !== null) {
            $abfrage->where('athlete_id', $athleteId);
        }

        return $abfrage->get()->filter(static fn (Result $r): bool => $r->athlete !== null
            && $r->swimEvent !== null
            && $r->meet !== null);
    }

    /**
     * Normen der Meisterschaft, nach Merkmalskombination indiziert.
     *
     * Einmal geladen statt je Zeile abgefragt. Maßgeblich ist results.sport_class, nicht der
     * Athletenstammsatz; die Zuordnung 21 → 14 greift vor dem Abgleich (§7.1).
     *
     * @return array<string, ChampionshipStandard>
     */
    private function standardsByKey(Championship $championship): array
    {
        return $championship->standards()
            ->with('strokeType')
            ->get()
            ->keyBy(fn (ChampionshipStandard $s): string => $this->standardKey(
                (int) $s->getAttribute('stroke_type_id'),
                (int) $s->getAttribute('distance'),
                $s->getAttribute('gender'),
                WpsSportClass::mapToWps($s->getAttribute('sport_class')) ?? '',
            ))
            ->all();
    }

    private function standardKey(int $strokeTypeId, int $distance, string $gender, string $sportClass): string
    {
        return implode('|', [$strokeTypeId, $distance, $gender, $sportClass]);
    }

    private function rowKey(Result $result): string
    {
        $event = $result->swimEvent;

        return implode('|', [
            $event->getAttribute('stroke_type_id'),
            $event->getAttribute('distance'),
            WpsSportClass::mapToWps($result->getAttribute('sport_class')) ?? '',
        ]);
    }
}
