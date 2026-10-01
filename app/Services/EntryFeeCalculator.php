<?php

namespace App\Services;

use App\Models\Club;
use App\Models\Entry;
use App\Models\Meet;
use App\Models\MeetFee;
use App\Models\RelayEntry;
use App\Models\SwimEvent;
use App\Support\AthleteFees;
use App\Support\ClubFeeStatement;
use App\Support\FeeLine;
use App\Support\FeeScheduleLine;
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
 *   - CLUB/ATHLETE eines Abschnitts einmal je Abschnitt, in dem der Verein bzw. Athlet startet,
 *   - je Nachmeldung (is_late_entry) zusätzlich LATEENTRY.INDIVIDUAL bzw. LATEENTRY.RELAY des Abschnitts, sonst der
 *     Veranstaltung.
 * Als Athleten eines Vereins zählen seine Einzelstarter und die Mitglieder seiner Staffeln. TEAM wird noch nicht
 * berechnet (docs/open-points.md).
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
     * Übersicht der hinterlegten Gebühren — nur befüllte, in der Abrechnung berechnete Beträge (TEAM entfällt):
     * zuerst die der Veranstaltung, dann je Abschnitt, dann die Bewerbsgebühren. Bewerbe mit gleichem Betrag
     * werden zusammengefasst ("Einzelbewerbe pro Start" bzw.
     * "Einzelbewerbe Nr. 1–3, 5 pro Start").
     *
     * @return Collection<int, FeeScheduleLine>
     */
    public function schedule(Meet $meet): Collection
    {
        $lines = collect();

        $fees = $meet->fees()->get()
            ->reject(fn (MeetFee $f): bool => in_array($f->type, MeetFee::NOT_CALCULATED, true))
            ->sortBy(fn (MeetFee $f): string => sprintf(
                '%05d|%02d',
                $f->session_number ?? 0,
                array_search($f->type, array_keys(MeetFee::TYPES), true),
            ));
        foreach ($fees as $fee) {
            $lines->push(new FeeScheduleLine(
                $fee->session_number === null ? 'Veranstaltung' : 'Abschnitt '.$fee->session_number,
                MeetFee::TYPES[$fee->type] ?? $fee->type,
                $fee->amount_cents,
            ));
        }

        $events = $meet->swimEvents()->orderBy('event_number')->get();
        foreach ([['Einzelbewerbe', false], ['Staffelbewerbe', true]] as [$kind, $isRelay]) {
            $ofKind = $events->filter(fn (SwimEvent $e): bool => ($e->relay_count > 1) === $isRelay);
            $byFee = $ofKind->whereNotNull('fee_cents')->groupBy('fee_cents');

            foreach ($byFee as $amount => $group) {
                $label = $group->count() === $ofKind->count()
                    ? $kind.' pro Start'
                    : $kind.' Nr. '.self::numberRanges($group->pluck('event_number')->filter()->all()).' pro Start';
                $lines->push(new FeeScheduleLine('Bewerbe', $label, (int) $amount));
            }
        }

        return $lines;
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
     * Bewerbsnummern als Bereiche: [1, 2, 3, 5] → "1–3, 5".
     *
     * @param  array<int, int>  $numbers
     */
    private static function numberRanges(array $numbers): string
    {
        $numbers = array_map('intval', $numbers);
        sort($numbers);
        $parts = [];
        $start = $prev = null;

        foreach ($numbers as $n) {
            if ($start !== null && $n === $prev + 1) {
                $prev = $n;

                continue;
            }
            if ($start !== null) {
                $parts[] = $start === $prev ? (string) $start : $start.'–'.$prev;
            }
            $start = $prev = $n;
        }
        if ($start !== null) {
            $parts[] = $start === $prev ? (string) $start : $start.'–'.$prev;
        }

        return implode(', ', $parts);
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
                        self::eventLabel($e).($e->is_late_entry ? ' (Nachmeldung)' : ''),
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
                $relayNames[$r->id].' · '.self::eventLabel($r).($r->is_late_entry ? ' (Nachmeldung)' : ''),
                1,
                $r->swimEvent?->fee_cents
                    ?? self::fee($fees, $r->swimEvent?->session_number ?? 1, MeetFee::TYPE_RELAY)
                    ?? self::fee($fees, null, MeetFee::TYPE_RELAY)
                    ?? 0,
            ))
            ->values();

        $flat = $this->flatFees($entries, $relays, $athletes->count(), $fees);
        $late = $this->lateFees($entries, MeetFee::TYPE_LATE_INDIVIDUAL, 'Nachmeldung je Einzelstart', $fees)
            ->merge($this->lateFees($relays, MeetFee::TYPE_LATE_RELAY, 'Nachmeldung je Staffel', $fees));

        $athleteList = $athletes
            ->sortBy(fn (AthleteFees $a): string => mb_strtolower($a->athlete->display_name))
            ->values();

        return new ClubFeeStatement(
            club: $club,
            athletes: $athleteList,
            relays: $relayLines,
            flatFees: $flat,
            lateFees: $late,
            startCount: $entries->count(),
            totalCents: $athleteList->sum('totalCents') + $relayLines->sum('totalCents') + $flat->sum('totalCents')
                + $late->sum('totalCents'),
        );
    }

    /**
     * Nachmeldegebühr (LATEENTRY.*) je nachgemeldetem Einzelstart bzw. je nachgemeldeter Staffel, zusätzlich zur
     * Start-/Staffelgebühr. Der Betrag des Abschnitts geht dem der Veranstaltung vor; zusammengefasst wird je
     * Abschnitt mit eigenem Betrag, alle übrigen Nachmeldungen in einer Position zum Betrag der Veranstaltung.
     *
     * @param  Collection<int, Entry>|Collection<int, RelayEntry>  $entries
     * @param  Collection<int, MeetFee>  $fees
     * @return Collection<int, FeeLine>
     */
    private function lateFees(Collection $entries, string $type, string $label, Collection $fees): Collection
    {
        $meetLevel = 0;
        /** @var array<int, array{count: int, amount: int}> $bySession */
        $bySession = [];

        foreach ($entries->filter(fn (Entry|RelayEntry $e): bool => $e->is_late_entry) as $entry) {
            $session = $entry->swimEvent?->session_number ?? 1;
            $amount = self::fee($fees, $session, $type);
            if ($amount !== null) {
                $bySession[$session] = ['count' => ($bySession[$session]['count'] ?? 0) + 1, 'amount' => $amount];
            } else {
                $meetLevel++;
            }
        }
        ksort($bySession);

        $lines = collect();
        if ($meetLevel > 0 && ($amount = self::fee($fees, null, $type)) !== null) {
            $lines->push(FeeLine::of($label, $meetLevel, $amount));
        }
        foreach ($bySession as $session => $line) {
            $lines->push(FeeLine::of("$label – Abschnitt $session", $line['count'], $line['amount']));
        }

        return $lines;
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
