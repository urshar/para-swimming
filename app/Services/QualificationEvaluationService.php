<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\Championship;
use App\Models\ChampionshipStandard;
use App\Models\Result;
use App\Support\QualificationAthleteSummary;
use App\Support\QualificationResultEntry;
use App\Support\QualificationRow;
use App\Support\QualificationStatus;
use App\Support\WpsSportClass;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * QualificationEvaluationService
 *
 * Bewertet Ergebnisse gegen die Normen einer Meisterschaft (Spec §7). Rein lesend —
 * nichts wird gespeichert, Übersichten werden bei jedem Aufruf berechnet (§12).
 *
 * Eine Bewertungsstelle für zwei Fragen
 * -------------------------------------
 * Die Qualifikantenansicht ("wer hat sich qualifiziert, und wie weit fehlt den übrigen") und
 * die Förderansicht ("hat der Athlet international eine Chance") teilen sich diesen Service.
 * Getrennt sind nur die Auswahl der Zeilen und die Darstellung — es darf nur eine Stelle
 * geben, die entscheidet, ob eine Norm erfüllt ist. Die Unterscheidung liegt in
 * QualificationStatus::isProof().
 */
final readonly class QualificationEvaluationService
{
    /**
     * Kürzeste Strecke, die überhaupt betrachtet wird.
     *
     * 25-m-Bewerbe werden auf internationalen Meisterschaften nicht ausgetragen. Sie in der
     * Bewertung mitzuführen erzeugt Zeilen, zu denen es nie eine Norm geben wird, und
     * verlängert beide Ansichten ohne Erkenntnisgewinn.
     */
    private const int MIN_DISTANCE = 50;

    /** Ergebnisstatus ohne wertbare Leistung. EXH fehlt bewusst — siehe relevantResults(). */
    private const array NON_SCORING_STATUSES = ['DNS', 'DNF', 'DSQ', 'SICK', 'WDR'];

    public function __construct(
        private WpsScmConversionService $conversionService,
        private AthleteKaderResolver $kaderResolver,
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
        $ergebnisse = $this->relevantResults($championship, $clubId, $athleteId, false);

        return $ergebnisse
            ->groupBy(fn (Result $result): int => (int) $result->getAttribute('athlete_id'))
            ->map(function (Collection $desAthleten) use ($championship, $normen): array {
                $zeilen = $desAthleten
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
     * Die Qualifikantenansicht (Frage A): je Athlet alle Bewerbe, für die eine Norm
     * ausgeschrieben ist — erfüllte wie offene.
     *
     * Nur reale Zeiten auf der Bahnlänge der Meisterschaft aus WPS-anerkannten Wettkämpfen.
     * Umgerechnete Kurzbahnzeiten kommen hier GAR NICHT vor — nicht ausgegraut, nicht mit
     * Hinweis, sondern nicht. In einer Liste, die dem Verband vorgelegt wird, darf kein
     * Eintrag stehen, den man für einen Nachweis halten könnte ([Q4], Q-R1).
     *
     * Bewerbe ohne Norm entfallen: Sie sagen über die Qualifikation nichts aus. Nicht
     * erfüllte Bewerbe mit Norm bleiben drin — der Abstand ist die eigentliche Information.
     *
     * @return Collection<int, QualificationAthleteSummary>
     */
    public function qualificationOverview(Championship $championship, ?int $clubId): Collection
    {
        $normen = $this->standardsByKey($championship);
        $stichtag = $this->kaderReferenceDate($championship);
        $kaderarten = $this->kaderResolver->byAthlete($stichtag);

        return $this->relevantResults($championship, $clubId, null, true)
            ->groupBy(fn (Result $result): int => (int) $result->getAttribute('athlete_id'))
            ->map(function (Collection $desAthleten) use ($championship, $normen, $kaderarten): QualificationAthleteSummary {
                $zeilen = $desAthleten
                    ->groupBy(fn (Result $r): string => $this->rowKey($r))
                    ->map(fn (Collection $gruppe): QualificationRow => $this->buildRow($championship, $normen, $gruppe))
                    // Bewerbe ohne Norm entfallen.
                    ->filter(static fn (QualificationRow $zeile): bool => $zeile->standard !== null)
                    ->values();

                $athlet = $desAthleten->first()->athlete;
                $kader = $kaderarten[$athlet->getKey()] ?? null;

                return new QualificationAthleteSummary(
                    $athlet,
                    $this->resolveMetOnly($zeilen)->sortBy(
                        static fn (QualificationRow $z): string => $z->eventLabel
                    )->values(),
                    $kader['name'] ?? null,
                    $kader['sort_order'] ?? PHP_INT_MAX,
                    // In der Qualifikantenansicht sind Bewerbe ohne Norm bereits ausgefiltert.
                    collect(),
                );
            })
            ->filter(static fn (QualificationAthleteSummary $eintrag): bool => $eintrag->rows->isNotEmpty())
            ->values();
    }

    /**
     * Die Förderansicht (§7.7): je Athlet alle Bewerbe, mit Kaderart.
     *
     * Anders als qualificationOverview() ohne Einschränkung auf anerkannte Wettkämpfe und
     * ohne Einschränkung auf die Bahnlänge der Meisterschaft — hier zählt jede Auskunft über
     * das Leistungsvermögen, nur eben nicht als Nachweis.
     *
     * Bewerbe ohne Norm werden getrennt geführt statt weggeworfen: Sie erscheinen nicht als
     * Zeile, werden aber benannt, sonst entstünde der Eindruck, der Athlet sei dort gar nicht
     * angetreten (§7.4).
     *
     * @return Collection<int, QualificationAthleteSummary>
     */
    public function developmentOverview(Championship $championship, ?int $clubId): Collection
    {
        $kaderarten = $this->kaderResolver->byAthlete($this->kaderReferenceDate($championship));

        return $this->evaluate($championship, $clubId, null)
            ->map(function (array $eintrag) use ($kaderarten): QualificationAthleteSummary {
                $kader = $kaderarten[$eintrag['athlete']->getKey()] ?? null;

                return new QualificationAthleteSummary(
                    $eintrag['athlete'],
                    $eintrag['rows']
                        ->filter(static fn (QualificationRow $z): bool => $z->standard !== null)
                        ->sortBy(static fn (QualificationRow $z): string => $z->eventLabel)
                        ->values(),
                    $kader['name'] ?? null,
                    $kader['sort_order'] ?? PHP_INT_MAX,
                    $eintrag['rows']
                        ->filter(static fn (QualificationRow $z): bool => $z->standard === null)
                        ->values(),
                );
            })
            // Athleten, bei denen kein einziger Bewerb eine Norm hat, tragen zur Frage
            // "wie weit fehlt zur Norm" nichts bei.
            ->filter(static fn (QualificationAthleteSummary $e): bool => $e->rows->isNotEmpty())
            ->values();
    }

    /**
     * Stichtag der Kaderzugehörigkeit.
     *
     * Läuft der Qualifikationszeitraum noch, gilt der heutige Tag: Die Liste stützt eine
     * Nominierungsentscheidung, die jetzt getroffen wird, und dafür zählt der Kader, in dem
     * jemand jetzt ist.
     *
     * Ist der Zeitraum abgelaufen, gilt sein Ende. Die Liste ist dann ein Rückblick und muss
     * reproduzierbar bleiben — mit dem heutigen Tag stünde bei einer Auswertung der EM 2026
     * im Jahr 2028 die Kadereinteilung von 2028, nicht die von damals.
     */
    public function kaderReferenceDate(Championship $championship): string
    {
        // Datumsstrings im Format Y-m-d lassen sich als Zeichenketten vergleichen; min()
        // liefert damit den früheren der beiden Tage.
        return min(
            Carbon::now()->format('Y-m-d'),
            $championship->qualification_end->format('Y-m-d'),
        );
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
     * Bestenermittlung verlöre die Unterscheidung genau dort, wo sie zählt.
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
            $this->buildHistory($gruppe, $norm),
        );
    }

    /**
     * Der Leistungsverlauf eines Bewerbs: alle Ergebnisse des Zeitraums, chronologisch.
     *
     * Chronologisch und nicht nach Zeit sortiert — aus einer nach Zeit sortierten Liste ist
     * keine Entwicklung ablesbar, und genau darum geht es hier.
     *
     * @param  Collection<int, Result>  $gruppe
     * @return Collection<int, QualificationResultEntry>
     */
    private function buildHistory(Collection $gruppe, ?ChampionshipStandard $norm): Collection
    {
        $mqs = $norm?->getAttribute('mqs_centiseconds');
        $met = $norm?->getAttribute('met_centiseconds');

        return $gruppe
            ->sortBy(static fn (Result $r): string => $r->meet?->start_date?->format('Y-m-d') ?? '')
            ->map(static function (Result $r) use ($mqs, $met): QualificationResultEntry {
                $zeit = (int) $r->getAttribute('swim_time');
                $erfuelltMqs = $mqs !== null && $zeit <= $mqs;

                return new QualificationResultEntry(
                    $r->getKey(),
                    $zeit,
                    $r->getAttribute('wps_points'),
                    $r->getAttribute('place'),
                    $r->meet?->name,
                    $r->meet?->start_date?->format('Y-m-d'),
                    $r->getAttribute('status') === 'EXH',
                    $erfuelltMqs,
                    ! $erfuelltMqs && $met !== null && $zeit <= $met,
                );
            })
            ->values();
    }

    /**
     * Ermittelt den Status nach §7.2.
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
        // reale Zeit aus der Zeile — angezeigt stünde "rechnerisch erreicht" bei jemandem,
        // der auf der Zielbahnlänge nachweislich langsamer war, und die Zahl, die das
        // widerlegt, wäre nirgends zu sehen ([Q4]).
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
     * @param  bool  $onlyApprovedMeets  für die Qualifikantenansicht: nur sanktionierte
     *                                   Wettkämpfe, weil nur deren Zeiten Nachweise sind
     * @return Collection<int, Result>
     */
    private function relevantResults(
        Championship $championship,
        ?int $clubId,
        ?int $athleteId,
        bool $onlyApprovedMeets,
    ): Collection {
        [$von, $bis] = $championship->qualificationPeriodBounds();

        $abfrage = Result::query()
            ->with(['athlete.club', 'meet', 'swimEvent.strokeType'])
            ->whereNotNull('swim_time')
            ->where('swim_time', '>', 0)
            // EXH bleibt drin: Ob ein außer Konkurrenz erzieltes Ergebnis international
            // anerkannt wird, entscheidet World Para Swimming (§7.1, offener Punkt).
            ->where(function ($query) {
                $query->whereNull('status')
                    ->orWhereNotIn('status', self::NON_SCORING_STATUSES);
            })
            ->whereHas('meet', function ($query) use ($von, $bis, $championship, $onlyApprovedMeets) {
                $query->whereBetween('start_date', [$von, "$bis 23:59:59"]);

                if ($onlyApprovedMeets) {
                    $query->where('wps_approved', true)
                        ->where('course', $championship->course);
                }
            })
            ->whereHas('swimEvent', static fn ($query) => $query
                ->where('relay_count', 1)
                ->where('distance', '>=', self::MIN_DISTANCE))
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
