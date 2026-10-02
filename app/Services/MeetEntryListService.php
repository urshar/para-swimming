<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Entry;
use App\Models\Meet;
use App\Models\MeetSession;
use App\Models\RelayEntry;
use App\Models\SwimEvent;
use App\Support\RelayNames;
use App\Support\TimeParser;
use Illuminate\Support\Collection;

/**
 * MeetEntryListService
 *
 * Sammelt die tatsächlich an einer Veranstaltung teilnehmenden Personen
 * (Athleten mit mindestens einer Einzel- oder Staffelmeldung) für die
 * meldebasierten Listen:
 *
 *   - Teilnehmerliste  (pro Verein, ein Block je Verein)
 *   - Sportpasskontrolle (über alle Vereine, eine flache, nach Namen sortierte Liste)
 *
 * Ein Athlet zählt genau einmal je Verein, unter dem er gemeldet ist — egal wie
 * viele Bewerbe/Staffeln. Der Verein ergibt sich aus der Meldung (entry.club_id
 * bzw. relay.club_id), nicht aus dem Stammverein des Athleten.
 *
 * Der Service rechnet nur zusammen; das Rendern (PDF/Excel) liegt in
 * MeetEntryListExportService bzw. den pdf.entry-lists-Views.
 */
final readonly class MeetEntryListService
{
    /**
     * Teilnehmer je Verein, nach Vereinsname und innerhalb dessen nach
     * Nachname/Vorname sortiert. Mit $clubId auf einen Verein beschränkt
     * (Vereins-Sicht); ohne alle Vereine (Admin-Sicht).
     *
     * @return Collection<int, array{club: Club, participants: Collection<int, Athlete>}>
     */
    public function participantsByClub(Meet $meet, ?int $clubId = null): Collection
    {
        [$pairs, $athletes, $clubs] = $this->collect($meet);

        if ($clubId !== null) {
            $pairs = $pairs->filter(fn (array $p): bool => $p[0] === $clubId)->values();
        }

        return $pairs->groupBy(fn (array $p): int => $p[0])
            ->map(fn (Collection $clubPairs, int $cid): array => [
                'club' => $clubs[$cid],
                'participants' => $clubPairs
                    ->map(fn (array $p): ?Athlete => $athletes[$p[1]] ?? null)
                    ->filter()
                    ->sortBy(fn (Athlete $a): string => $this->nameKey($a))
                    ->values(),
            ])
            ->filter(fn (array $g): bool => $g['club'] !== null)
            ->sortBy(fn (array $g): string => $g['club']->display_name)
            ->values();
    }

    /**
     * Flache Teilnehmerliste über alle Vereine, zuerst nach Verein und innerhalb
     * dessen nach Nachname/Vorname sortiert — Grundlage der Sportpasskontrolle.
     *
     * @return Collection<int, array{athlete: Athlete, club: Club}>
     */
    public function allParticipants(Meet $meet): Collection
    {
        [$pairs, $athletes, $clubs] = $this->collect($meet);

        return $pairs
            ->map(fn (array $p): array => [
                'athlete' => $athletes[$p[1]] ?? null,
                'club' => $clubs[$p[0]] ?? null,
            ])
            ->filter(fn (array $r): bool => $r['athlete'] !== null && $r['club'] !== null)
            ->sortBy(fn (array $r): string => sprintf('%s|%s', $r['club']->display_name, $this->nameKey($r['athlete'])))
            ->values();
    }

    /** Dauer der Veranstaltung in Tagen (inklusive Start- und Endtag). */
    public function days(Meet $meet): int
    {
        if (! $meet->end_date) {
            return 1;
        }

        return (int) $meet->start_date->diffInDays($meet->end_date) + 1;
    }

    /** Anzeigename für die Listen: "Nachname Vorname" (wie in der swimify-Vorlage). */
    public static function personName(Athlete $athlete): string
    {
        return trim(($athlete->last_name ?? '').' '.($athlete->first_name ?? ''));
    }

    /**
     * Meldungen gruppiert für die "Übersichtsliste nach Namen":
     * Geschlecht -> Verein -> Athlet (mit seinen Bewerben), plus Staffeln je Verein.
     *
     * Admin: alle Vereine; mit $clubId auf einen Verein beschränkt (Vereins-Sicht).
     *
     * @return Collection<int, array{label: string, clubs: Collection<int, array<string, mixed>>}>
     */
    public function byName(Meet $meet, ?int $clubId = null): Collection
    {
        $course = $this->courseLabel($meet->course);

        $entries = $meet->entries()
            ->with(['athlete', 'club.nation', 'swimEvent.strokeType'])
            ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
            ->get();

        $relays = $meet->relayEntries()
            ->with(['club.nation', 'swimEvent.strokeType', 'members.athlete'])
            ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
            ->get();
        $relayNames = RelayNames::for($relays);

        // Geschlechts-Sektionen in fester Reihenfolge (Mixed nur für Staffeln relevant).
        $order = ['M' => 'Herren', 'F' => 'Damen', 'X' => 'Mixed'];
        $sections = collect();

        foreach ($order as $genderKey => $genderLabel) {
            $genderEntries = $entries->filter(fn (Entry $e): bool => $e->athlete?->gender === $genderKey);
            $genderRelays = $relays->filter(fn (RelayEntry $r): bool => ($r->teamGender() ?? 'M') === $genderKey);

            if ($genderEntries->isEmpty() && $genderRelays->isEmpty()) {
                continue;
            }

            $clubIds = $genderEntries->pluck('club_id')->merge($genderRelays->pluck('club_id'))->unique();
            $clubs = collect();

            foreach ($clubIds as $cid) {
                $clubEntries = $genderEntries->where('club_id', $cid);
                $clubRelays = $genderRelays->where('club_id', $cid)->values();

                /** @var Entry|null $firstEntry */
                $firstEntry = $clubEntries->first();
                /** @var RelayEntry|null $firstRelay */
                $firstRelay = $clubRelays->first();
                $club = $firstEntry?->club ?? $firstRelay?->club;

                if (! $club) {
                    continue;
                }

                $athletes = $clubEntries
                    ->groupBy('athlete_id')
                    ->map(fn (Collection $athEntries): array => [
                        'athlete' => $athEntries->first()->athlete,
                        'birthYear' => $athEntries->first()->athlete?->birth_date?->format('y') ?? '',
                        'entries' => $athEntries
                            ->sortBy(fn (Entry $e): string => sprintf('%03d%03d',
                                $e->swimEvent?->session_number ?? 0, $e->swimEvent?->event_number ?? 0))
                            ->values(),
                    ])
                    ->filter(fn (array $a): bool => $a['athlete'] !== null)
                    ->sortBy(fn (array $a): string => sprintf('%s|%s',
                        $a['athlete']->last_name ?? '', $a['athlete']->first_name ?? ''))
                    ->values();

                // Staffelname siehe App\Support\RelayNames (Nummer je Bewerb, nicht je Verein über alle
                // Bewerbe). members in Positionsreihenfolge (members() ist bereits nach position sortiert).
                $relayList = $clubRelays->map(fn (RelayEntry $r): array => [
                    'name' => $relayNames[$r->id].($r->is_exhibition ? ' (AK)' : ''),
                    'event' => $r->swimEvent,
                    'class' => $r->relay_class ?: 'allg.',
                    'time' => $r->entry_time ? TimeParser::display($r->entry_time) : ($r->entry_time_code ?: 'NT'),
                    'members' => $r->members->map(fn ($m): array => [
                        'position' => $m->position,
                        'name' => $m->athlete ? self::personName($m->athlete) : '',
                    ])->filter(fn (array $m): bool => $m['name'] !== '')->values(),
                ])->values();

                $clubs->push([
                    'club' => $club,
                    'codeLine' => $this->clubCodeLine($club),
                    'athletes' => $athletes,
                    'relays' => $relayList,
                ]);
            }

            $sections->push([
                'label' => trim($genderLabel.', '.$course, ', '),
                'clubs' => $clubs->sortBy(fn (array $c): string => $c['club']->name)->values(),
            ]);
        }

        return $sections;
    }

    /**
     * Meldungen gruppiert für die "Übersichtsliste nach Wettkämpfen":
     * Abschnitt (Session) -> Bewerb -> Teilnehmer (alphabetisch), Staffeln mit
     * ihren Schwimmern. Bewerbe ohne Meldungen entfallen.
     *
     * Admin: alle Vereine; mit $clubId auf einen Verein beschränkt.
     *
     * @return Collection<int, array{label: string, events: Collection<int, array<string, mixed>>}>
     */
    public function byEvent(Meet $meet, ?int $clubId = null): Collection
    {
        $entries = $meet->entries()
            ->with(['athlete', 'club', 'swimEvent.strokeType'])
            ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
            ->get();

        $relays = $meet->relayEntries()
            ->with(['club', 'swimEvent.strokeType', 'members.athlete'])
            ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
            ->get();
        $relayNames = RelayNames::for($relays);

        $events = $meet->swimEvents()->with('strokeType')
            ->orderBy('session_number')->orderBy('event_number')->get();
        /** @var Collection<int, MeetSession> $sessions Abschnitte nach Nummer */
        $sessions = $meet->sessions()->get()->keyBy('number');

        $sections = collect();

        foreach ($events->groupBy(fn (SwimEvent $e): int => $e->session_number ?? 1) as $session => $sessionEvents) {
            $eventBlocks = collect();

            foreach ($sessionEvents->sortBy('event_number') as $event) {
                if ($event->isRelay()) {
                    $evRelays = $relays->where('swim_event_id', $event->id)
                        ->sortBy(fn (RelayEntry $r): string => $relayNames[$r->id])
                        ->values();

                    if ($evRelays->isEmpty()) {
                        continue;
                    }

                    $eventBlocks->push([
                        'title' => $this->eventTitle($event, true),
                        'isRelay' => true,
                        'entrants' => collect(),
                        'relays' => $evRelays->map(fn (RelayEntry $r): array => [
                            'name' => $relayNames[$r->id].($r->is_exhibition ? ' (AK)' : ''),
                            'class' => $r->relay_class ?: 'allg.',
                            'time' => $r->entry_time ? TimeParser::display($r->entry_time) : ($r->entry_time_code ?: 'NT'),
                            'members' => $r->members->map(fn ($m): array => [
                                'position' => $m->position,
                                'name' => $m->athlete ? self::personName($m->athlete) : '',
                            ])->filter(fn (array $m): bool => $m['name'] !== '')->values(),
                        ])->values(),
                    ]);
                } else {
                    $evEntries = $entries->where('swim_event_id', $event->id)
                        ->sortBy(fn (Entry $e): string => sprintf('%s|%s',
                            $e->athlete?->last_name ?? '', $e->athlete?->first_name ?? ''))
                        ->values();

                    if ($evEntries->isEmpty()) {
                        continue;
                    }

                    $eventBlocks->push([
                        'title' => $this->eventTitle($event, false),
                        'isRelay' => false,
                        'relays' => collect(),
                        'entrants' => $evEntries->map(fn (Entry $e): array => [
                            // AK = außer Konkurrenz (status EXH).
                            'name' => ($e->athlete?->display_name ?? '').($e->status === 'EXH' ? ' (AK)' : ''),
                            'club' => $e->club?->display_name ?? '',
                            'year' => $e->athlete?->birth_date?->format('y') ?? '',
                            'time' => $e->formatted_entry_time,
                            'class' => $e->sport_class,
                        ])->values(),
                    ]);
                }
            }

            if ($eventBlocks->isEmpty()) {
                continue;
            }

            $sections->push([
                'label' => $this->sessionLabel($meet, (int) $session, $sessions->get((int) $session)),
                'events' => $eventBlocks,
            ]);
        }

        return $sections;
    }

    /** Bahn-Bezeichnung für die Sektionsüberschrift. */
    public function courseLabel(?string $course): string
    {
        return match ($course) {
            'SCM' => 'Kurze Bahn (25m)',
            'LCM' => 'Lange Bahn (50m)',
            'SCY' => 'Kurze Bahn (Yards)',
            default => $course ?? '',
        };
    }

    /** Titel eines Bewerbs: "Nr. X  <Bewerb> [Geschlecht/Alle]". */
    private function eventTitle(SwimEvent $event, bool $isRelay): string
    {
        $suffix = match ($event->gender) {
            'M' => ' Herren',
            'F' => ' Damen',
            'X' => ' Mixed',
            default => $isRelay ? ' Alle' : '',
        };

        return 'Nr. '.$event->event_number.'  '.$event->display_name.$suffix;
    }

    /**
     * Abschnitts-Überschrift "Abschnitt N - Wochentag, Datum": Datum aus meet_sessions, sonst bei eintägiger
     * Veranstaltung deren Datum, sonst ohne Datum. Die Startzeit wird bewusst nicht angezeigt (swimify-Vorlage).
     */
    private function sessionLabel(Meet $meet, int $session, ?MeetSession $sessionInfo): string
    {
        $label = 'Abschnitt '.$session;

        $date = $sessionInfo?->date
            ?? ((! $meet->end_date || $meet->start_date->isSameDay($meet->end_date)) ? $meet->start_date : null);

        if ($date) {
            $label .= ' - '.$date->locale('de')->translatedFormat('l, j. F Y');
        }

        return $label;
    }

    /** Vereins-Codezeile "CODE / Regionalverband / NATION" (leere Teile entfallen). */
    private function clubCodeLine(Club $club): string
    {
        return collect([$club->code, $club->regional_association, $club->nation?->code])
            ->filter()
            ->join(' / ');
    }

    /**
     * Sammelt die eindeutigen (Verein, Athlet)-Paare der Veranstaltung aus
     * Einzel- und Staffelmeldungen und lädt die zugehörigen Modelle einmalig.
     *
     * @return array{0: Collection<int, array{0: int, 1: int}>, 1: Collection<int, Athlete>, 2: Collection<int, Club>}
     */
    private function collect(Meet $meet): array
    {
        $pairs = collect();

        foreach ($meet->entries()->get(['athlete_id', 'club_id']) as $entry) {
            if ($entry->athlete_id && $entry->club_id) {
                $pairs->push([$entry->club_id, $entry->athlete_id]);
            }
        }

        $relays = $meet->relayEntries()
            ->with('members:id,relay_entry_id,athlete_id')
            ->get(['id', 'club_id']);

        foreach ($relays as $relay) {
            foreach ($relay->members as $member) {
                if ($member->athlete_id && $relay->club_id) {
                    $pairs->push([$relay->club_id, $member->athlete_id]);
                }
            }
        }

        $pairs = $pairs->unique(fn (array $p): string => $p[0].'-'.$p[1])->values();

        $athletes = Athlete::whereIn('id', $pairs->pluck(1)->unique())->get()->keyBy('id');
        $clubs = Club::whereIn('id', $pairs->pluck(0)->unique())->get()->keyBy('id');

        return [$pairs, $athletes, $clubs];
    }

    /** Sortierschlüssel "Nachname|Vorname" (CLAUDE.md: zusammengesetzter sprintf-Schlüssel). */
    private function nameKey(Athlete $athlete): string
    {
        return sprintf('%s|%s', $athlete->last_name ?? '', $athlete->first_name ?? '');
    }
}
