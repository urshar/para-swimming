<?php

namespace App\Services\Public;

use App\Models\Meet;
use App\Models\Result;
use App\Models\SwimEvent;
use Illuminate\Support\Collection;

/**
 * PublicResultService — Ergebnisse einer Veranstaltung für den öffentlichen Bereich (Spec
 * public-frontend §5.2, Phase 4). Rein lesend, nur für veröffentlichte Meets aufzurufen (das
 * prüft der Controller, hier nicht nochmal).
 */
final readonly class PublicResultService
{
    /**
     * Ergebnisse gruppiert nach Bewerb (Session/Bewerbsnummer), darin nach Sportklasse
     * (Ergebnis-Feld, nicht Athlet-Stammdaten — kann laut LENEX abweichen), sortiert.
     *
     * @return Collection<int, object{event: SwimEvent, classes: Collection<string, Collection<int, Result>>}>
     */
    public function forMeet(Meet $meet): Collection
    {
        $swimEvents = $meet->swimEvents()
            ->with('strokeType')
            ->whereHas('results')
            ->orderBy('session_number')
            ->orderBy('event_number')
            ->get();

        $results = $meet->results()
            ->with(['athlete', 'club'])
            ->get()
            ->groupBy('swim_event_id');

        return $swimEvents->map(function (SwimEvent $swimEvent) use ($results): object {
            $classes = ($results->get($swimEvent->id) ?? collect())
                ->sortBy(fn (Result $result): string => $this->sortKey($result))
                ->values()
                ->groupBy(fn (Result $result): string => $result->sport_class ?? '');

            return (object) ['event' => $swimEvent, 'classes' => $classes];
        });
    }

    /** Ob für dieses Meet überhaupt Ergebnisse veröffentlicht sind (für den Link auf der Detailseite). */
    public function hasResults(Meet $meet): bool
    {
        return $meet->results()->exists();
    }

    /**
     * Zusammengesetzter Sortierschlüssel statt sortBy() mit Closure-Array (CLAUDE.md):
     * Sportklasse zuerst, darin gültige Zeiten nach Platz/Zeit, DNS/DNF/DSQ/SICK/WDR ans Ende.
     * EXH bleibt bei seiner reell erzielten Zeit einsortiert.
     */
    private function sortKey(Result $result): string
    {
        $invalidStatuses = ['DNS', 'DNF', 'DSQ', 'SICK', 'WDR'];
        $isInvalid = in_array($result->status, $invalidStatuses, true);

        return sprintf(
            '%s|%s|%010d|%010d',
            $result->sport_class ?? '',
            $isInvalid ? '1' : '0',
            $isInvalid ? 0 : ($result->place ?? 9999999999),
            $isInvalid ? 0 : ($result->swim_time ?? 9999999999)
        );
    }
}
