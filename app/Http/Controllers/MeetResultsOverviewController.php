<?php

namespace App\Http\Controllers;

use App\Concerns\SearchesAthletes;
use App\Models\Meet;
use App\Models\PointSystem;
use App\Models\RelayResult;
use App\Models\Result;
use App\Models\SwimEvent;
use App\Services\MeetResultListService;
use App\Services\PdfExportService;
use App\Support\ListUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MeetResultsOverviewController
 *
 * Meet-weite Sammelansicht aller Einzelergebnisse einer Veranstaltung, nach Disziplin
 * gruppiert und je Disziplin nach Platz bzw. Zeit sortiert. Gegenstück zur
 * "Alle Meldungen"-Übersicht (MeetEntriesOverviewController), aber für Ergebnisse.
 *
 * Anlegen, Bearbeiten und Einzel-Löschen laufen über den ResultController. Die Route merkt
 * sich als Rücksprungziel des Bereichs "results" (remember.list:results, wie die globale
 * Ergebnisliste), damit man nach Speichern/Löschen mit denselben Filtern hierher zurückkommt. Zusätzlich
 * löscht destroyEvent() alle Ergebnisse einer Disziplin auf einmal (z. B. nach einem
 * fehlerhaften LENEX-Import). Nur Admin (Route-Middleware RequireAdmin).
 */
class MeetResultsOverviewController extends Controller
{
    use SearchesAthletes;

    public function index(Request $request, Meet $meet): View
    {
        $events = $meet->swimEvents()
            ->with('strokeType')
            ->orderBy('session_number')
            ->orderBy('event_number')
            ->get();

        // Ein nicht zu diesem Meet gehörendes Event wird ignoriert (statt eine leere Liste zu zeigen).
        $eventFilter = $request->integer('event_id') ?: null;
        if ($eventFilter !== null && ! $events->contains('id', $eventFilter)) {
            $eventFilter = null;
        }

        $clubs = $meet->clubsByIds($meet->resultClubIds());
        $clubFilter = $request->integer('club_id') ?: null;
        if ($clubFilter !== null && ! $clubs->contains('id', $clubFilter)) {
            $clubFilter = null;
        }

        $search = trim((string) $request->query('search', ''));

        $query = Result::query()
            ->where('meet_id', $meet->id)
            ->with(['athlete', 'club'])
            ->when($eventFilter, fn (Builder $q) => $q->where('swim_event_id', $eventFilter))
            ->when($clubFilter, fn (Builder $q) => $q->where('club_id', $clubFilter));
        if ($search !== '') {
            $this->applyAthleteSearch($query, $search);
        }

        // Platzierte zuerst nach Platz, dann ohne Platz nach Zeit, ohne Zeit (DNS/DSQ ...) ans Ende.
        // Zusammengesetzter sprintf()-Schlüssel statt sortBy() mit Closure-Array (CLAUDE.md).
        $resultsByEvent = $query->get()
            ->sortBy(fn (Result $r): string => sprintf(
                '%d|%06d|%d|%010d|%s',
                $r->place ? 0 : 1,
                $r->place ?? 0,
                $r->swim_time ? 0 : 1,
                $r->swim_time ?? 0,
                $r->athlete?->last_name ?? '',
            ))
            ->groupBy('swim_event_id');

        // Staffelergebnisse: Vereinsfilter auf den Staffelverein, Athletensuche auf die eingesetzten Schwimmer.
        $relayResultsByEvent = RelayResult::query()
            ->where('meet_id', $meet->id)
            ->with(['club', 'members.athlete'])
            ->when($eventFilter, fn (Builder $q) => $q->where('swim_event_id', $eventFilter))
            ->when($clubFilter, fn (Builder $q) => $q->where('club_id', $clubFilter))
            ->when($search !== '', fn (Builder $q) => $q->whereHas('members', fn (Builder $m) => $m
                ->where(fn (Builder $w) => $w
                    ->where('last_name', 'like', '%'.$search.'%')
                    ->orWhere('first_name', 'like', '%'.$search.'%')
                    ->orWhereHas('athlete', fn (Builder $a) => $a
                        ->where('last_name', 'like', '%'.$search.'%')
                        ->orWhere('first_name', 'like', '%'.$search.'%')))))
            ->get()
            // Plätze gelten je Wertung (Herren, Damen, Mixed): zuerst nach Wertung, dann wie bei Einzelergebnissen.
            ->sortBy(fn (RelayResult $r): string => sprintf(
                '%d|%d|%06d|%d|%010d|%s',
                ['M' => 0, 'F' => 1, 'X' => 2][$r->gender] ?? 3,
                $r->place ? 0 : 1,
                $r->place ?? 0,
                $r->swim_time ? 0 : 1,
                $r->swim_time ?? 0,
                $r->display_name,
            ))
            ->groupBy('swim_event_id');

        // Ungefilterte Anzahl je Disziplin: "Alle löschen" löscht auch die per Verein/Name ausgeblendeten
        // Ergebnisse, die Rückfrage nennt deshalb diese Zahl statt der sichtbaren.
        $eventTotals = Result::query()
            ->where('meet_id', $meet->id)
            ->selectRaw('swim_event_id, COUNT(*) as total')
            ->groupBy('swim_event_id')
            ->pluck('total', 'swim_event_id');
        $relayTotals = RelayResult::query()
            ->where('meet_id', $meet->id)
            ->selectRaw('swim_event_id, COUNT(*) as total')
            ->groupBy('swim_event_id')
            ->pluck('total', 'swim_event_id');
        foreach ($relayTotals as $eventId => $count) {
            $eventTotals[$eventId] = (int) ($eventTotals[$eventId] ?? 0) + (int) $count;
        }

        return view('meets.results-overview', [
            'meet' => $meet,
            'pointColumns' => self::pointColumns($meet),
            'events' => $events,
            'hasRelayEvents' => $events->contains(fn (SwimEvent $event): bool => $event->relay_count > 1),
            'clubs' => $clubs,
            'resultsByEvent' => $resultsByEvent,
            'relayResultsByEvent' => $relayResultsByEvent,
            'eventTotals' => $eventTotals,
            'filterConfig' => [
                'event_id' => $eventFilter !== null ? (string) $eventFilter : '',
                'club_id' => $clubFilter !== null ? (string) $clubFilter : '',
                'search' => $search,
            ],
            'isFiltered' => $eventFilter !== null || $clubFilter !== null || $search !== '',
            'total' => $meet->results()->count() + $meet->relayResults()->count(),
        ]);
    }

    /**
     * Ergebnisliste als PDF: je Disziplin nach Wertungsgruppe (vorläufig Sportklasse) mit Platzierung.
     * Ein Disziplin-Filter der Sammelansicht (event_id) wird übernommen.
     */
    public function pdf(Request $request, Meet $meet, MeetResultListService $lists, PdfExportService $pdf): Response
    {
        $eventId = $request->integer('event_id') ?: null;
        if ($eventId !== null && ! $meet->swimEvents()->whereKey($eventId)->exists()) {
            $eventId = null;
        }

        $slug = str($meet->name)->slug()->limit(40, '')->value() ?: 'wettkampf';

        return $pdf->stream(
            'pdf.result-list',
            ['meet' => $meet, 'events' => $lists->byEvent($meet, $eventId), 'pointColumns' => self::pointColumns($meet)],
            "ergebnisliste-$slug.pdf",
        );
    }

    /** Löscht alle Ergebnisse einer Disziplin, Einzel- wie Staffelergebnisse (Splits/Mitglieder über cascadeOnDelete). */
    public function destroyEvent(Meet $meet, SwimEvent $swimEvent): RedirectResponse
    {
        abort_unless($swimEvent->meet_id === $meet->id, 404);

        $deleted = 0;
        $results = Result::where('meet_id', $meet->id)->where('swim_event_id', $swimEvent->id)->get();
        $relayResults = RelayResult::where('meet_id', $meet->id)->where('swim_event_id', $swimEvent->id)->get();
        foreach ($results->concat($relayResults) as $result) {
            $result->delete();
            $deleted++;
        }

        return redirect()
            ->to(self::backUrl($meet))
            ->with('success', $deleted.' '.($deleted === 1 ? 'Ergebnis' : 'Ergebnisse').' von "'.$swimEvent->display_name.'" gelöscht.');
    }

    /**
     * Rücksprung zur Sammelansicht dieses Meets: die zuletzt gemerkte Ergebnisliste, wenn sie
     * die Sammelansicht dieses Meets ist (mit Filtern), sonst die ungefilterte Sammelansicht.
     */
    public static function backUrl(Meet $meet): string
    {
        $overview = route('meets.results-overview', $meet);
        $remembered = ListUrl::to('results');

        return $remembered === $overview || str_starts_with($remembered, $overview.'?')
            ? $remembered
            : $overview;
    }

    /**
     * Welche Punktespalten Liste und PDF zeigen: ÖBSV-Punkte (World-Aquatics-Formel, results.points) bzw.
     * WPS-Punkte, jeweils wenn das System für die Veranstaltung aktiviert ist oder schon Werte vorliegen
     * (z. B. aus einem LENEX-Import oder einer früheren Berechnung).
     *
     * @return array{points: bool, wps: bool}
     */
    private static function pointColumns(Meet $meet): array
    {
        $codes = $meet->pointSystems()->pluck('code');

        return [
            'points' => $codes->contains(PointSystem::CODE_WORLD_AQUATICS)
                || $meet->results()->whereNotNull('points')->exists(),
            'wps' => $codes->contains(PointSystem::CODE_WPS)
                || $meet->results()->whereNotNull('wps_points')->exists(),
        ];
    }
}
