<?php

namespace App\Services\Public;

use App\Models\ForeignStart;
use App\Models\Meet;
use App\Models\RelayResult;
use App\Models\Result;
use App\Models\SwimEvent;
use App\Services\ScoringGroupService;
use Illuminate\Support\Collection;

/**
 * PublicResultService — Ergebnisse einer Veranstaltung für den öffentlichen Bereich (Spec
 * public-frontend §5.2, Phase 4). Rein lesend, nur für veröffentlichte Meets aufzurufen (das
 * prüft der Controller, hier nicht nochmal).
 */
final readonly class PublicResultService
{
    public function __construct(
        private ScoringGroupService $scoring,
    ) {}

    /**
     * Ergebnisse gruppiert nach Bewerb (Session/Bewerbsnummer), darin nach Wertungsgruppen mit Platz je Gruppe
     * (ScoringGroupService; ohne definierte Gruppen je Geschlecht und Sportklasse bzw. Wertung und Staffelklasse).
     * Staffelbewerbe liefern Staffelergebnisse samt Schwimmern.
     *
     * Gäste (Vereine außerhalb Österreichs, foreign_starts) stehen je Bewerb getrennt unter "guests", ohne Platz und
     * ohne Einfluss auf die Wertung (ForeignStart::inListOrder()).
     *
     * @return Collection<int, object{event: SwimEvent, isRelay: bool, scoring: list<array{gender: ?string, name: string, rows: list<array{place: ?int, result: Result|RelayResult}>}>, guests: Collection<int, ForeignStart>}>
     */
    public function forMeet(Meet $meet): Collection
    {
        $swimEvents = $meet->swimEvents()
            ->with(['strokeType', 'scoringGroups'])
            ->where(fn ($q) => $q->whereHas('results')->orWhereHas('relayResults')->orWhereHas('foreignStarts'))
            ->orderBy('session_number')
            ->orderBy('event_number')
            ->get();

        $guests = $meet->foreignStarts()->with('nation:id,code')->get()->groupBy('swim_event_id');

        $results = $meet->results()
            ->with(['athlete', 'club'])
            ->get()
            ->groupBy('swim_event_id');
        $relayResults = $meet->relayResults()
            ->with(['club', 'members.athlete'])
            ->get()
            ->groupBy('swim_event_id');

        $meetYear = (int) $meet->start_date->format('Y');

        return $swimEvents->map(function (SwimEvent $swimEvent) use ($results, $relayResults, $guests, $meetYear): object {
            $isRelay = $swimEvent->relay_count > 1;
            $eventResults = ($isRelay ? $relayResults : $results)->get($swimEvent->id) ?? collect();

            return (object) [
                'event' => $swimEvent,
                'isRelay' => $isRelay,
                'scoring' => $this->scoring->rankedGroups($swimEvent, $eventResults, $meetYear),
                'guests' => ForeignStart::inListOrder($guests->get($swimEvent->id) ?? collect()),
            ];
        });
    }

    /** Ob für dieses Meet überhaupt Ergebnisse (Einzel, Staffel oder Gäste) veröffentlicht sind (Link auf der Detailseite). */
    public function hasResults(Meet $meet): bool
    {
        return $meet->results()->exists() || $meet->relayResults()->exists() || $meet->foreignStarts()->exists();
    }
}
