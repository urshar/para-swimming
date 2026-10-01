<?php

namespace App\Services;

use App\Models\Club;
use App\Models\Entry;
use App\Models\Meet;
use App\Models\MeetFee;
use App\Models\RelayEntry;
use App\Support\AthleteFees;
use App\Support\ClubFeeStatement;
use App\Support\FeeLine;
use App\Support\RelayNames;
use Illuminate\Support\Collection;

/**
 * Meldegeld-Abrechnung je Verein aus den Meldungen einer Veranstaltung (docs/specs/club-entries.md "Meldegelder").
 *
 * Berechnet werden alle Einzelmeldungen außer abgelehnten (status RJC) und alle Staffeln außer zurückgezogenen
 * (status withdrawn). Positionen:
 *   - je Einzelstart die Bewerbsgebühr (swim_events.fee_cents),
 *   - je Staffel die Bewerbsgebühr, sonst RELAY des Abschnitts, sonst RELAY der Veranstaltung,
 *   - CLUB/ATHLETE der Veranstaltung einmal je Verein bzw. Athlet,
 *   - CLUB/ATHLETE eines Abschnitts einmal je Abschnitt, in dem der Verein bzw. Athlet startet.
 * Als Athleten eines Vereins zählen seine Einzelstarter und die Mitglieder seiner Staffeln. TEAM und LATEENTRY.*
 * werden noch nicht berechnet (docs/open-points.md).
 */
final readonly class EntryFeeCalculator
{
    /**
     * @param  int|null  $clubId  null = alle Vereine, sonst nur dieser
     * @return Collection<int, ClubFeeStatement> alphabetisch nach Verein
     */
    public function statements(Meet $meet, ?int $clubId): Collection
    {
        $fees = $meet->fees()->get();

        $entries = $meet->entries()
            ->with(['athlete', 'club', 'swimEvent.strokeType'])
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'RJC'))
            ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
            ->get();

        $relays = $meet->relayEntries()
            ->with(['club', 'swimEvent.strokeType', 'members.athlete'])
            ->where('status', '!=', 'withdrawn')
            ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
            ->get();
        $relayNames = RelayNames::for($relays);

        return $entries->pluck('club_id')->merge($relays->pluck('club_id'))->unique()
            ->map(fn (int $id): ?ClubFeeStatement => $this->statement(
                $entries->where('club_id', $id)->values(),
                $relays->where('club_id', $id)->values(),
                $relayNames,
                $fees,
            ))
            ->filter()
            ->sortBy(fn (ClubFeeStatement $s): string => mb_strtolower($s->club->display_name))
            ->values();
    }

    /**
     * Gesamtsumme mehrerer Abrechnungen.
     *
     * @param  Collection<int, ClubFeeStatement>  $statements
     */
    public static function total(Collection $statements): int
    {
        return $statements->sum(fn (ClubFeeStatement $s): int => $s->totalCents);
    }

    /**
     * @param  Collection<int, Entry>  $entries
     * @param  Collection<int, RelayEntry>  $relays
     * @param  array<int, string>  $relayNames
     * @param  Collection<int, MeetFee>  $fees
     */
    private function statement(Collection $entries, Collection $relays, array $relayNames, Collection $fees): ?ClubFeeStatement
    {
        /** @var Club|null $club */
        $club = $entries->first()?->club ?? $relays->first()?->club;
        if (! $club) {
            return null;
        }

        // Einzelstarts je Athlet.
        $athletes = $entries->filter(fn (Entry $e): bool => $e->athlete !== null)
            ->groupBy('athlete_id')
            ->map(function (Collection $athleteEntries): AthleteFees {
                $starts = $athleteEntries
                    ->sortBy(fn (Entry $e): string => sprintf('%05d', $e->swimEvent?->event_number ?? 0))
                    ->map(fn (Entry $e): FeeLine => FeeLine::of(
                        self::eventLabel($e),
                        1,
                        $e->swimEvent?->fee_cents ?? 0,
                    ))
                    ->values();

                return new AthleteFees($athleteEntries->first()->athlete, $starts, $starts->sum('totalCents'));
            });

        // Staffelmitglieder ohne eigenen Einzelstart als Athleten ergänzen (zählen für die Gebühr je Athlet).
        foreach ($relays as $relay) {
            foreach ($relay->members as $member) {
                if ($member->athlete && ! $athletes->has($member->athlete_id)) {
                    $athletes->put($member->athlete_id, new AthleteFees($member->athlete, collect(), 0));
                }
            }
        }

        $relayLines = $relays
            ->sortBy(fn (RelayEntry $r): string => sprintf('%05d|%s', $r->swimEvent?->event_number ?? 0, $relayNames[$r->id]))
            ->map(fn (RelayEntry $r): FeeLine => FeeLine::of(
                $relayNames[$r->id].' · '.self::eventLabel($r),
                1,
                $r->swimEvent?->fee_cents
                    ?? self::fee($fees, $r->swimEvent?->session_number ?? 1, MeetFee::TYPE_RELAY)
                    ?? self::fee($fees, null, MeetFee::TYPE_RELAY)
                    ?? 0,
            ))
            ->values();

        $flat = $this->flatFees($entries, $relays, $athletes->count(), $fees);

        $athleteList = $athletes
            ->sortBy(fn (AthleteFees $a): string => mb_strtolower($a->athlete->display_name))
            ->values();

        return new ClubFeeStatement(
            club: $club,
            athletes: $athleteList,
            relays: $relayLines,
            flatFees: $flat,
            startCount: $entries->count(),
            totalCents: $athleteList->sum('totalCents') + $relayLines->sum('totalCents') + $flat->sum('totalCents'),
        );
    }

    /**
     * Pauschalen CLUB/ATHLETE: einmal für die Veranstaltung, dann je Abschnitt mit Starts des Vereins.
     *
     * @param  Collection<int, Entry>  $entries
     * @param  Collection<int, RelayEntry>  $relays
     * @param  Collection<int, MeetFee>  $fees
     * @return Collection<int, FeeLine>
     */
    private function flatFees(Collection $entries, Collection $relays, int $athleteCount, Collection $fees): Collection
    {
        $lines = collect();

        if (($club = self::fee($fees, null, MeetFee::TYPE_CLUB)) !== null) {
            $lines->push(FeeLine::of('Gebühr je Verein', 1, $club));
        }
        if (($athlete = self::fee($fees, null, MeetFee::TYPE_ATHLETE)) !== null && $athleteCount > 0) {
            $lines->push(FeeLine::of('Gebühr je Athlet', $athleteCount, $athlete));
        }

        // Athleten je Abschnitt: Einzelstarter + Staffelmitglieder der Bewerbe dieses Abschnitts.
        $athletesBySession = [];
        foreach ($entries as $entry) {
            $session = $entry->swimEvent?->session_number ?? 1;
            $athletesBySession[$session] ??= [];
            if ($entry->athlete_id) {
                $athletesBySession[$session][$entry->athlete_id] = true;
            }
        }
        foreach ($relays as $relay) {
            $session = $relay->swimEvent?->session_number ?? 1;
            $athletesBySession[$session] ??= [];
            foreach ($relay->members as $member) {
                if ($member->athlete_id) {
                    $athletesBySession[$session][$member->athlete_id] = true;
                }
            }
        }
        ksort($athletesBySession);

        foreach ($athletesBySession as $session => $athleteIds) {
            if (($club = self::fee($fees, $session, MeetFee::TYPE_CLUB)) !== null) {
                $lines->push(FeeLine::of("Gebühr je Verein – Abschnitt $session", 1, $club));
            }
            if (($athlete = self::fee($fees, $session, MeetFee::TYPE_ATHLETE)) !== null && $athleteIds !== []) {
                $lines->push(FeeLine::of("Gebühr je Athlet – Abschnitt $session", count($athleteIds), $athlete));
            }
        }

        return $lines;
    }

    /** @param  Collection<int, MeetFee>  $fees */
    private static function fee(Collection $fees, ?int $session, string $type): ?int
    {
        return $fees->first(fn (MeetFee $f): bool => $f->session_number === $session && $f->type === $type)?->amount_cents;
    }

    /** "Nr. 3 · 100m Freistil" */
    private static function eventLabel(Entry|RelayEntry $entry): string
    {
        $event = $entry->swimEvent;

        return trim(($event?->event_number ? 'Nr. '.$event->event_number.' · ' : '').($event?->display_name ?? ''));
    }
}
