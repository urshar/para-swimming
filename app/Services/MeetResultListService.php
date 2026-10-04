<?php

namespace App\Services;

use App\Models\Meet;
use App\Models\Result;
use App\Models\SwimEvent;
use Illuminate\Support\Collection;

/**
 * MeetResultListService
 *
 * Bereitet die Einzelergebnisse einer Veranstaltung für die Ergebnisliste (PDF) auf: je Disziplin,
 * darin je Wertungsgruppe mit eigener Platzierung.
 *
 * Wertungsgruppe ist vorläufig die Sportklasse des Ergebnisses (results.sport_class). Die echten
 * Wertungsgruppen der Veranstaltung sind noch nicht abgebildet (Open Point "Meetstruktur /
 * Wertungsgruppen"); sobald es sie gibt, ändert sich nur groupKey()/groupLabel().
 *
 * Platzierung: innerhalb der Gruppe nach Zeit berechnet, gleiche Zeit = gleicher Platz (1, 1, 3).
 * Gewertet werden nur Ergebnisse ohne Status mit gültiger Zeit; außer Konkurrenz (EXH) folgt mit
 * Zeit, aber ohne Platz, danach DSQ/DNS/DNF usw.
 */
final readonly class MeetResultListService
{
    /** Nicht gewertete Status in der Reihenfolge, in der sie unter den gewerteten Ergebnissen stehen. */
    private const array UNRANKED_ORDER = ['EXH' => 0, 'DSQ' => 1, 'DNF' => 2, 'DNS' => 3, 'SICK' => 4, 'WDR' => 5];

    /**
     * @return Collection<int, array{event: SwimEvent, groups: list<array{label: string, rows: list<array{place: ?int, result: Result}>}>}>
     */
    public function byEvent(Meet $meet, ?int $eventId): Collection
    {
        $events = $meet->swimEvents()
            ->with('strokeType')
            ->where('relay_count', '<=', 1)
            ->when($eventId, fn ($q) => $q->where('id', $eventId))
            ->orderBy('session_number')
            ->orderBy('event_number')
            ->get();

        $resultsByEvent = Result::query()
            ->where('meet_id', $meet->id)
            ->whereIn('swim_event_id', $events->pluck('id'))
            ->with(['athlete', 'club'])
            ->get()
            ->groupBy('swim_event_id');

        return $events
            ->filter(fn (SwimEvent $event): bool => $resultsByEvent->has($event->id))
            ->map(fn (SwimEvent $event): array => [
                'event' => $event,
                'groups' => $this->groups($resultsByEvent[$event->id]),
            ])
            ->values();
    }

    /**
     * @param  Collection<int, Result>  $results
     * @return list<array{label: string, rows: list<array{place: ?int, result: Result}>}>
     */
    private function groups(Collection $results): array
    {
        return $results
            ->groupBy(fn (Result $r): string => self::groupKey($r->sport_class))
            ->sortKeys()
            ->map(fn (Collection $group): array => [
                'label' => self::groupLabel($group->first()->sport_class),
                'rows' => $this->rank($group),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Result>  $results
     * @return list<array{place: ?int, result: Result}>
     */
    private function rank(Collection $results): array
    {
        // Zusammengesetzter sprintf()-Schlüssel statt sortBy() mit Closure-Array (CLAUDE.md).
        $sorted = $results->sortBy(fn (Result $r): string => sprintf(
            '%d|%d|%010d|%s',
            self::isRanked($r) ? 0 : 1,
            self::isRanked($r) ? 0 : (self::UNRANKED_ORDER[$r->status] ?? 9),
            $r->swim_time ?? 9999999999,
            $r->athlete?->last_name ?? '',
        ))->values();

        $rows = [];
        $place = 0;
        $previousTime = null;
        foreach ($sorted as $index => $result) {
            if (self::isRanked($result)) {
                if ($result->swim_time !== $previousTime) {
                    $place = $index + 1;
                    $previousTime = $result->swim_time;
                }
                $rows[] = ['place' => $place, 'result' => $result];
            } else {
                $rows[] = ['place' => null, 'result' => $result];
            }
        }

        return $rows;
    }

    private static function isRanked(Result $result): bool
    {
        return $result->status === null && $result->swim_time > 0;
    }

    /** Sortierschlüssel: Kategorie (S, SB, SM, Sonstige) und Klassennummer; ohne Klasse ans Ende. */
    private static function groupKey(?string $sportClass): string
    {
        $class = strtoupper(trim((string) $sportClass));
        if ($class === '') {
            return '9|';
        }
        if (preg_match('/^(SB|SM|S)(\d+)$/', $class, $m)) {
            $category = ['S' => 0, 'SB' => 1, 'SM' => 2][$m[1]];

            return sprintf('%d|%03d', $category, (int) $m[2]);
        }

        return '8|'.$class;
    }

    private static function groupLabel(?string $sportClass): string
    {
        $class = trim((string) $sportClass);

        return $class === '' ? 'Ohne Sportklasse' : 'Sportklasse '.strtoupper($class);
    }
}
