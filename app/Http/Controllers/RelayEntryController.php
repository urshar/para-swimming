<?php

namespace App\Http\Controllers;

use App\Models\Meet;
use App\Models\RelayEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RelayEntryController extends Controller
{
    /**
     * Staffel-Cockpit (Tab neben dem Einzel-Cockpit). Route ist admin-only
     * (RequireAdmin) — zeigt immer alle Staffelmeldungen, nur zur Ansicht.
     */
    public function index(Request $request): View
    {
        // Basis-Query (Wettkampf + Vereinssuche) als gemeinsame Grundlage für Kennzahlen
        // und Liste. Staffeln haben keinen einzelnen Athleten, daher Suche über den Verein.
        $base = RelayEntry::query();

        if ($meetId = $request->query('meet_id')) {
            $base->where('meet_id', $meetId);
        }

        if ($search = $request->query('search')) {
            $base->whereHas('club', function (Builder $q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('short_name', 'like', '%'.$search.'%');
            });
        }

        // Kennzahlen-Kacheln: zählen im aktuellen Wettkampf-/Such-Kontext, aber unabhängig
        // vom gewählten Status-/Problemfilter (Aufschlüsselung bleibt vollständig).
        $counts = [
            'total' => (clone $base)->count(),
            'pending' => $this->countFiltered($base, fn (Builder $q) => $this->applyStatusFilter($q, 'pending')),
            'confirmed' => $this->countFiltered($base, fn (Builder $q) => $this->applyStatusFilter($q, 'confirmed')),
            'incomplete' => $this->countFiltered($base, fn (Builder $q) => $this->applyProblemFilter($q, 'incomplete')),
            'no_time' => $this->countFiltered($base, fn (Builder $q) => $this->applyProblemFilter($q, 'no_time')),
        ];

        // Liste zusätzlich nach Status/Problem filtern.
        $query = (clone $base)
            ->with(['club', 'meet', 'swimEvent.strokeType', 'members.athlete'])
            ->latest();
        $this->applyStatusFilter($query, $request->query('status'));
        $this->applyProblemFilter($query, $request->query('problem'));

        $relayEntries = $query->paginate(25)->withQueryString();
        $meets = Meet::orderByDesc('start_date')->get();

        return view('relay-entries.index', compact('relayEntries', 'meets', 'counts'));
    }

    // ── Private Hilfsmethoden ─────────────────────────────────────────────────

    /**
     * Statusfilter des Staffel-Cockpits. Staffeln haben einen Workflow-Status
     * (pending/confirmed), nicht die LENEX-Status der Einzelmeldungen; ein
     * leerer/null-Wert lässt die Liste ungefiltert.
     */
    private function applyStatusFilter(Builder $query, ?string $status): void
    {
        if (in_array($status, ['pending', 'confirmed'], true)) {
            $query->where('status', $status);
        }
    }

    /**
     * Problemfilter des Staffel-Cockpits: unvollständig besetzte Teams
     * (Mitgliederzahl < relay_count des Bewerbs) oder ohne Meldezeit.
     */
    private function applyProblemFilter(Builder $query, ?string $problem): void
    {
        if ($problem === 'no_time') {
            $query->whereNull('entry_time');
        } elseif ($problem === 'incomplete') {
            // Korrelierte Subqueries statt withCount+Join, damit es auf MySQL und SQLite
            // gleich läuft: tatsächliche Mitgliederzahl < Soll-Besetzung des Bewerbs.
            $query->whereRaw(
                '(select count(*) from relay_entry_members '
                .'where relay_entry_members.relay_entry_id = relay_entries.id) '
                .'< (select relay_count from swim_events '
                .'where swim_events.id = relay_entries.swim_event_id)'
            );
        }
    }

    /**
     * Zählt die Staffelmeldungen einer Klon-Basis-Query nach Anwenden eines Filter-
     * Callbacks. Eigene Methode statt tap()->count(), damit PhpStorms Generics-Resolver
     * nicht auf HigherOrderTapProxy zurückfällt ("Method 'count' not found").
     *
     * @param  callable(Builder): void  $filter
     */
    private function countFiltered(Builder $base, callable $filter): int
    {
        $query = clone $base;
        $filter($query);

        return $query->count();
    }
}
