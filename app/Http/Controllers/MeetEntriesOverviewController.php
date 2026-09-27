<?php

namespace App\Http\Controllers;

use App\Models\Entry;
use App\Models\Meet;
use App\Models\RelayEntry;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * MeetEntriesOverviewController
 *
 * Meet-weite Gesamtübersicht aller Meldungen einer Veranstaltung — Einzel- UND
 * Staffelmeldungen, über alle Vereine hinweg, nach Disziplin gruppiert. Spart dem
 * Admin das mühsame Durchklicken je Verein (die `club-entries`-Ansichten sind
 * club-gescoped).
 *
 * Rein lesend/gruppierend: Bearbeiten und Löschen laufen weiterhin über die
 * bestehenden Formulare (`entries.edit`/`entries.destroy` für Einzel,
 * `club-entries.relay.edit`/`.destroy` mit `club_id` für Staffeln). Nur Admin
 * (Route-Middleware RequireAdmin).
 */
class MeetEntriesOverviewController extends Controller
{
    public function index(Request $request, Meet $meet): View
    {
        $events = $meet->swimEvents()
            ->with('strokeType')
            ->orderBy('session_number')
            ->orderBy('event_number')
            ->get();

        // Optionaler Disziplin-Filter; ein nicht zu diesem Meet gehörendes Event
        // wird ignoriert (statt eine leere Liste zu zeigen).
        $eventFilter = $request->integer('event_id') ?: null;
        if ($eventFilter !== null && ! $events->contains('id', $eventFilter)) {
            $eventFilter = null;
        }

        // Sortierung über zusammengesetzte sprintf()-Schlüssel statt sortBy() mit
        // Closure-Array (Letzteres ist laut CLAUDE.md unzuverlässig).
        $entriesByEvent = $meet->entries()
            ->with(['athlete', 'club', 'swimEvent.strokeType'])
            ->when($eventFilter, fn (Builder $q) => $q->where('swim_event_id', $eventFilter))
            ->get()
            ->sortBy(fn (Entry $e): string => sprintf('%s|%s', $e->club?->display_name ?? '', $e->athlete?->last_name ?? ''))
            ->groupBy('swim_event_id');

        $relaysByEvent = $meet->relayEntries()
            ->with(['club', 'swimEvent.strokeType', 'members.athlete'])
            ->when($eventFilter, fn (Builder $q) => $q->where('swim_event_id', $eventFilter))
            ->get()
            ->sortBy(fn (RelayEntry $r): string => $r->club?->display_name ?? '')
            ->groupBy('swim_event_id');

        return view('meets.entries-overview', [
            'meet' => $meet,
            'events' => $events,
            'entriesByEvent' => $entriesByEvent,
            'relaysByEvent' => $relaysByEvent,
            'eventFilter' => $eventFilter,
            'einzelTotal' => $meet->entries()->count(),
            'staffelTotal' => $meet->relayEntries()->count(),
        ]);
    }
}
