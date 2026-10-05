<?php

namespace App\Services;

use App\Models\Meet;
use App\Models\RelayResult;
use App\Models\Result;
use App\Models\ScoringGroup;
use App\Models\SwimEvent;
use Illuminate\Support\Collection;

/**
 * MeetResultListService
 *
 * Bereitet die Ergebnisse einer Veranstaltung für die Ergebnisliste (PDF) auf: je Bewerb, darin je Wertungsgruppe
 * mit eigener Platzierung (ScoringGroupService). Einzelbewerbe werten Einzelergebnisse, Staffelbewerbe
 * Staffelergebnisse (relay_results).
 */
final readonly class MeetResultListService
{
    public function __construct(
        private ScoringGroupService $scoring,
    ) {}

    /**
     * @return Collection<int, array{event: SwimEvent, isRelay: bool, groups: list<array{group: ?ScoringGroup, label: string, rows: list<array{place: ?int, result: Result|RelayResult}>}>}>
     */
    public function byEvent(Meet $meet, ?int $eventId): Collection
    {
        $events = $meet->swimEvents()
            ->with(['strokeType', 'scoringGroups'])
            ->when($eventId, fn ($q) => $q->where('id', $eventId))
            ->orderBy('session_number')
            ->orderBy('event_number')
            ->get();

        $resultsByEvent = Result::query()
            ->where('meet_id', $meet->id)
            ->whereIn('swim_event_id', $events->where('relay_count', '<=', 1)->pluck('id'))
            ->with(['athlete', 'club'])
            ->get()
            ->groupBy('swim_event_id');

        $relayResultsByEvent = RelayResult::query()
            ->where('meet_id', $meet->id)
            ->whereIn('swim_event_id', $events->where('relay_count', '>', 1)->pluck('id'))
            ->with(['club', 'members.athlete'])
            ->get()
            ->groupBy('swim_event_id');

        $meetYear = (int) $meet->start_date->format('Y');

        return $events
            ->map(function (SwimEvent $event) use ($resultsByEvent, $relayResultsByEvent, $meetYear): array {
                $isRelay = $event->relay_count > 1;
                $results = ($isRelay ? $relayResultsByEvent : $resultsByEvent)->get($event->id) ?? collect();

                return [
                    'event' => $event,
                    'isRelay' => $isRelay,
                    'groups' => $this->scoring->rankedGroups($event, $results, $meetYear),
                ];
            })
            ->filter(fn (array $block): bool => $block['groups'] !== [])
            ->values();
    }
}
