<?php

namespace App\Services;

use App\Models\RelayResult;
use App\Models\Result;
use App\Models\ScoringGroup;
use App\Models\SwimEvent;
use Illuminate\Support\Collection;

/**
 * ScoringGroupService — Wertung der Ergebnisse eines Bewerbs nach Wertungsgruppen.
 *
 * Zuordnung (berechnet, nicht gespeichert): Ein Ergebnis gehört in jede Wertungsgruppe des Bewerbs, zu der es passt
 * (ScoringGroup::matches()) — Einzelergebnis über Geschlecht, Sportklasse und Jahrgangsalter des Athleten,
 * Staffelergebnis über Wertung (M/F/X) und Staffelklasse. Mehrfachwertung ist möglich (z. B. Klassen- und
 * Jugendwertung). Passt ein Ergebnis in keine Gruppe, steht es unter "Ohne Wertungsgruppe", damit nichts verschwindet.
 *
 * Bewerbe ohne Wertungsgruppen (Altbestand): Rückfall je Geschlecht und Sportklasse ("Herren – S9"), damit Damen und
 * Herren auch bei gemeinsam ausgeschriebenen Bewerben ("A") getrennt gewertet werden.
 *
 * Platzierung je Gruppe:
 *   - Mit Wertungsgruppen nach den Punkten (results.points, ÖBSV-Punkte), höchste zuerst — so werden die
 *     zusammengefassten Klassen vergleichbar (ÖSTM 2025: ergibt exakt die offiziellen Plätze). Gewertet werden
 *     Ergebnisse ohne Status mit Zeit und Punkten; Ergebnisse mit Zeit, aber ohne Punkte folgen ohne Platz
 *     ("missingPoints" je Gruppe, die Ansicht weist darauf hin).
 *   - Ohne Wertungsgruppen (Rückfall je Klasse) nach der Zeit. Gewertet werden Ergebnisse ohne Status mit Zeit.
 *   Gleicher Wert = gleicher Platz (1, 1, 3). Außer Konkurrenz (EXH) folgt ohne Platz, danach DSQ/DNF/DNS usw.
 */
final readonly class ScoringGroupService
{
    public const string UNASSIGNED_LABEL = 'Ohne Wertungsgruppe';

    /** Nicht gewertete Status in der Reihenfolge, in der sie unter den gewerteten Ergebnissen stehen. */
    private const array UNRANKED_ORDER = ['EXH' => 0, 'DSQ' => 1, 'DNF' => 2, 'DNS' => 3, 'SICK' => 4, 'WDR' => 5];

    private const array GENDER_ORDER = ['M' => 0, 'F' => 1, 'X' => 2, 'A' => 3];

    /**
     * Gewertete Gruppen eines Bewerbs. Erwartet Einzelergebnisse (mit athlete) bzw. Staffelergebnisse (mit members)
     * dieses Bewerbs; das Wettkampfjahr bestimmt das Jahrgangsalter.
     *
     * @param  Collection<int, Result|RelayResult>  $results
     * @return list<array{group: ?ScoringGroup, gender: ?string, name: string, label: string, missingPoints: int, rows: list<array{place: ?int, result: Result|RelayResult}>}>
     */
    public function rankedGroups(SwimEvent $event, Collection $results, int $meetYear): array
    {
        if ($results->isEmpty()) {
            return [];
        }

        $groups = $event->relationLoaded('scoringGroups') ? $event->scoringGroups : $event->scoringGroups()->get();

        return $groups->isEmpty()
            ? $this->fallbackGroups($results, $meetYear)
            : $this->definedGroups($groups, $results, $meetYear);
    }

    /**
     * Schreibt die berechneten Plätze eines Bewerbs in results.place bzw. relay_results.place, damit alle Stellen,
     * die das Feld lesen (Athletenseite, Ergebnislisten, Qualifikation, LENEX-Export), dieselbe Wertung zeigen. Liegt
     * ein Ergebnis in mehreren Gruppen, gilt der Platz der ersten (wie beim LENEX-Import); nicht Gewertete → null.
     */
    public function syncPlaces(SwimEvent $event): void
    {
        $meetYear = (int) $event->meet->start_date->format('Y');
        $results = $event->relay_count > 1
            ? RelayResult::where('swim_event_id', $event->id)->with('members')->get()
            : Result::where('swim_event_id', $event->id)->with('athlete')->get();

        $places = [];
        foreach ($this->rankedGroups($event, $results, $meetYear) as $group) {
            foreach ($group['rows'] as $row) {
                if (! array_key_exists($row['result']->id, $places)) {
                    $places[$row['result']->id] = $row['place'];
                }
            }
        }

        foreach ($results as $result) {
            $place = $places[$result->id] ?? null;
            if ($result->place !== $place) {
                $result->update(['place' => $place]);
            }
        }
    }

    /**
     * Berechnete Plätze eines Ergebnisses je Wertungsgruppe (für die Anzeige im Formular).
     *
     * @return list<array{label: string, place: ?int}>
     */
    public function placementsOf(Result|RelayResult $result): array
    {
        $event = $result->swimEvent;
        $results = $result instanceof RelayResult
            ? RelayResult::where('swim_event_id', $event->id)->with('members')->get()
            : Result::where('swim_event_id', $event->id)->with('athlete')->get();

        $placements = [];
        foreach ($this->rankedGroups($event, $results, (int) $event->meet->start_date->format('Y')) as $group) {
            foreach ($group['rows'] as $row) {
                if ($row['result']->id === $result->id) {
                    $placements[] = ['label' => $group['label'], 'place' => $row['place']];
                }
            }
        }

        return $placements;
    }

    /**
     * @param  Collection<int, ScoringGroup>  $groups
     * @param  Collection<int, Result|RelayResult>  $results
     * @return list<array{group: ?ScoringGroup, gender: ?string, name: string, label: string, missingPoints: int, rows: list<array{place: ?int, result: Result|RelayResult}>}>
     */
    private function definedGroups(Collection $groups, Collection $results, int $meetYear): array
    {
        $assigned = [];
        $out = [];
        foreach ($groups as $group) {
            $members = $results->filter(function (Result|RelayResult $r) use ($group, $meetYear, &$assigned): bool {
                [$gender, $class, $age] = self::traits($r, $meetYear);
                $match = $group->matches($gender, $class, $age);
                if ($match) {
                    $assigned[spl_object_id($r)] = true;
                }

                return $match;
            });
            if ($members->isNotEmpty()) {
                $out[] = ['group' => $group, 'gender' => $group->gender, 'name' => $group->name, 'label' => $group->label()] + $this->rank($members, true);
            }
        }

        $unassigned = $results->reject(fn (Result|RelayResult $r): bool => isset($assigned[spl_object_id($r)]));
        if ($unassigned->isNotEmpty()) {
            $out[] = ['group' => null, 'gender' => null, 'name' => self::UNASSIGNED_LABEL, 'label' => self::UNASSIGNED_LABEL] + $this->rank($unassigned, true);
        }

        return $out;
    }

    /**
     * Rückfall ohne definierte Gruppen: je Geschlecht und Sportklasse bzw. Staffelklasse.
     *
     * @param  Collection<int, Result|RelayResult>  $results
     * @return list<array{group: ?ScoringGroup, gender: ?string, name: string, label: string, missingPoints: int, rows: list<array{place: ?int, result: Result|RelayResult}>}>
     */
    private function fallbackGroups(Collection $results, int $meetYear): array
    {
        return $results
            ->groupBy(function (Result|RelayResult $r) use ($meetYear): string {
                [$gender] = self::traits($r, $meetYear);
                $class = self::classLabel($r);

                // Schlüssel "Geschlechtsreihenfolge#Klassensortierung#Geschlecht#Klasse" — sortierbar und zerlegbar.
                return implode('#', [self::GENDER_ORDER[$gender ?? 'A'] ?? 9, self::classSortKey($class), $gender ?? '', $class]);
            })
            ->sortKeys()
            ->map(function (Collection $members, string $key): array {
                [, , $gender, $class] = explode('#', $key, 4);
                $group = new ScoringGroup([
                    'name' => $class !== '' ? $class : 'Ohne Sportklasse',
                    'gender' => $gender !== '' ? $gender : 'A',
                    'sport_classes' => $class,
                ]);

                return ['group' => null, 'gender' => $group->gender, 'name' => $class, 'label' => $group->label()] + $this->rank($members, false);
            })
            ->values()
            ->all();
    }

    /**
     * Wertungsmerkmale eines Ergebnisses: [Geschlecht, Klassennummer, Jahrgangsalter].
     *
     * @return array{0: ?string, 1: ?int, 2: ?int}
     */
    private static function traits(Result|RelayResult $result, int $meetYear): array
    {
        if ($result instanceof RelayResult) {
            return [$result->gender, self::classNumber($result->relay_class), null];
        }

        $birthYear = $result->athlete?->birth_date?->year;

        return [
            $result->athlete?->gender,
            self::classNumber($result->sport_class),
            $birthYear ? $meetYear - $birthYear : null,
        ];
    }

    private static function classNumber(?string $class): ?int
    {
        $digits = preg_replace('/\D+/', '', (string) $class);

        return $digits === '' ? null : (int) $digits;
    }

    /** Klassen-Bezeichnung für den Rückfall: Sportklasse (S9, SB4) bzw. Staffelklasse (S14). */
    private static function classLabel(Result|RelayResult $result): string
    {
        $class = $result instanceof RelayResult ? $result->relay_class : $result->sport_class;

        return strtoupper(trim((string) $class));
    }

    /** Sortierschlüssel: Kategorie (S, SB, SM, Sonstige) und Klassennummer; ohne Klasse ans Ende. */
    private static function classSortKey(string $class): string
    {
        if ($class === '') {
            return '9|';
        }
        if (preg_match('/^(SB|SM|S)(\d+)$/', $class, $m)) {
            return sprintf('%d|%03d', ['S' => 0, 'SB' => 1, 'SM' => 2][$m[1]], (int) $m[2]);
        }

        return '8|'.$class;
    }

    /**
     * Sortiert und platziert eine Gruppe — nach Punkten (höchste zuerst) oder nach Zeit.
     *
     * @param  Collection<int, Result|RelayResult>  $results
     * @return array{missingPoints: int, rows: list<array{place: ?int, result: Result|RelayResult}>}
     */
    private function rank(Collection $results, bool $byPoints): array
    {
        $isRanked = fn (Result|RelayResult $r): bool => $r->status === null && $r->swim_time > 0
            && (! $byPoints || $r->points > 0);
        // Rangwert: kleiner = besser. Punkte werden dafür umgedreht.
        $rankValue = fn (Result|RelayResult $r): int => $byPoints ? 99999 - (int) $r->points : (int) $r->swim_time;

        // Zusammengesetzter sprintf()-Schlüssel statt sortBy() mit Closure-Array (CLAUDE.md). Reihenfolge der nicht
        // Gewerteten: zuerst mit Zeit ohne Punkte, dann AK, dann DSQ/DNF/DNS ...
        $sorted = $results->sortBy(fn (Result|RelayResult $r): string => sprintf(
            '%d|%d|%010d|%010d|%s',
            $isRanked($r) ? 0 : 1,
            $isRanked($r) ? 0 : ($r->status === null ? 0 : 1 + (self::UNRANKED_ORDER[$r->status] ?? 9)),
            $isRanked($r) ? $rankValue($r) : 0,
            $r->swim_time ?? 9999999999,
            $r instanceof RelayResult ? $r->display_name : ($r->athlete?->last_name ?? ''),
        ))->values();

        $rows = [];
        $place = 0;
        $previous = null;
        $missingPoints = 0;
        foreach ($sorted as $index => $result) {
            if ($isRanked($result)) {
                if ($rankValue($result) !== $previous) {
                    $place = $index + 1;
                    $previous = $rankValue($result);
                }
                $rows[] = ['place' => $place, 'result' => $result];
            } else {
                if ($byPoints && $result->status === null && $result->swim_time > 0) {
                    $missingPoints++;
                }
                $rows[] = ['place' => null, 'result' => $result];
            }
        }

        return ['missingPoints' => $missingPoints, 'rows' => $rows];
    }
}
