<?php

namespace App\Http\Controllers;

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\RelayEntryMember;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Teilnehmer einer Veranstaltung (Ziel der Kacheln "Teilnehmer" und "Clubs" auf meets/show), nur lesend.
 *
 * Athleten: dieselben wie Meet::participantIds() (Einzel- oder Staffelmeldung oder Ergebnis), je mit dem Verein, für
 * den sie bei dieser Veranstaltung starten (Meldung, sonst Ergebnis, sonst Staffelmeldung, sonst Stammverein).
 * Vereine: dieselben wie Meet::participatingClubsCount() (Vereine aus Meldungen und Ergebnissen) mit Zählern.
 */
class MeetParticipantsController extends Controller
{
    public const string VIEW_ATHLETES = 'athleten';

    public const string VIEW_CLUBS = 'vereine';

    public function index(Request $request, Meet $meet): View
    {
        $view = $request->query('ansicht') === self::VIEW_CLUBS ? self::VIEW_CLUBS : self::VIEW_ATHLETES;

        $entryCounts = $this->countBy($meet->entries()->pluck('athlete_id'));
        $relayRows = RelayEntryMember::query()
            ->join('relay_entries', 'relay_entries.id', '=', 'relay_entry_members.relay_entry_id')
            ->where('relay_entries.meet_id', $meet->id)
            ->get(['relay_entry_members.athlete_id', 'relay_entries.club_id']);
        $relayCounts = $this->countBy($relayRows->pluck('athlete_id'));
        $resultCounts = $this->countBy($meet->results()->pluck('athlete_id'));

        // Verein bei dieser Veranstaltung: Meldung vor Ergebnis vor Staffelmeldung (spätere Quellen überschreiben nicht).
        $meetClubs = $meet->entries()->pluck('club_id', 'athlete_id')->filter()->all()
            + $meet->results()->pluck('club_id', 'athlete_id')->filter()->all()
            + $relayRows->pluck('club_id', 'athlete_id')->filter()->all();

        $athletes = Athlete::query()
            ->with(['club', 'sportClasses'])
            ->whereKey($meet->participantIds())
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $clubIds = $meet->entryClubIds()->merge($meet->resultClubIds())->unique()->values();
        $clubs = Club::query()->whereKey($clubIds)->orderBy('name')->get();

        $rows = $athletes->map(fn (Athlete $athlete) => [
            'athlete' => $athlete,
            'club' => $clubs->firstWhere('id', $meetClubs[$athlete->id] ?? null) ?? $athlete->club,
            'entries' => $entryCounts[$athlete->id] ?? 0,
            'relays' => $relayCounts[$athlete->id] ?? 0,
            'results' => $resultCounts[$athlete->id] ?? 0,
        ]);

        $clubFilter = $request->integer('club_id') ?: null;
        if ($clubFilter !== null && ! $clubs->contains('id', $clubFilter)) {
            $clubFilter = null;
        }
        $search = trim((string) $request->query('suche', ''));

        $filteredRows = $rows
            ->when($clubFilter !== null, fn (Collection $r) => $r->filter(fn (array $row) => $row['club']?->id === $clubFilter))
            ->when($search !== '', fn (Collection $r) => $r->filter(fn (array $row) => Str::contains(
                $row['athlete']->first_name.' '.$row['athlete']->last_name, $search, ignoreCase: true)))
            ->values();

        $entriesByClub = $this->countBy($meet->entries()->pluck('club_id'));
        $relayEntriesByClub = $this->countBy($meet->relayEntries()->pluck('club_id'));
        $resultsByClub = $this->countBy($meet->results()->pluck('club_id')->merge($meet->relayResults()->pluck('club_id')));
        $athletesByClub = $this->countBy($rows->map(fn (array $row) => $row['club']?->id));

        $clubRows = $clubs->map(fn (Club $club) => [
            'club' => $club,
            'athletes' => $athletesByClub[$club->id] ?? 0,
            'entries' => $entriesByClub[$club->id] ?? 0,
            'relays' => $relayEntriesByClub[$club->id] ?? 0,
            'results' => $resultsByClub[$club->id] ?? 0,
        ]);

        return view('meets.participants', [
            'meet' => $meet,
            'view' => $view,
            'rows' => $filteredRows,
            'athleteCount' => $rows->count(),
            'clubRows' => $clubRows,
            'clubs' => $clubs,
            'clubFilter' => $clubFilter,
            'search' => $search,
        ]);
    }

    /**
     * Anzahl je Wert (leere Werte ausgelassen).
     *
     * @param  Collection<int, int|null>  $values
     * @return array<int, int>
     */
    private function countBy(Collection $values): array
    {
        return $values->filter()->countBy()->all();
    }
}
