<?php

namespace App\Services\Public;

use App\Models\Meet;
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
     * (ScoringGroupService; ohne definierte Gruppen je Geschlecht und Sportklasse).
     *
     * @return Collection<int, object{event: SwimEvent, scoring: list<array{gender: ?string, name: string, rows: list<array{place: ?int, result: Result}>}>}>
     */
    public function forMeet(Meet $meet): Collection
    {
        $swimEvents = $meet->swimEvents()
            ->with(['strokeType', 'scoringGroups'])
            ->whereHas('results')
            ->orderBy('session_number')
            ->orderBy('event_number')
            ->get();

        $results = $meet->results()
            ->with(['athlete', 'club'])
            ->get()
            ->groupBy('swim_event_id');

        $meetYear = (int) $meet->start_date->format('Y');

        return $swimEvents->map(fn (SwimEvent $swimEvent): object => (object) [
            'event' => $swimEvent,
            'scoring' => $this->scoring->rankedGroups($swimEvent, $results->get($swimEvent->id) ?? collect(), $meetYear),
        ]);
    }

    /** Ob für dieses Meet überhaupt Ergebnisse veröffentlicht sind (für den Link auf der Detailseite). */
    public function hasResults(Meet $meet): bool
    {
        return $meet->results()->exists();
    }
}
