<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Result;
use App\Models\SwimEvent;
use App\Support\TimeParser;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * ClubEntryService
 *
 * Kapselt die Geschäftslogik für Club-Meldungen:
 *   - Welche Athleten dürfen in einem Event starten?
 *   - Welche Bestzeit hat ein Athlet für ein Event?
 */
readonly class ClubEntryService
{
    // ── Athleten-Eignung ──────────────────────────────────────────────────────

    /**
     * Gibt alle Athleten eines Clubs zurück, die für ein Einzel-Event geeignet sind.
     *
     * Kriterien:
     *   1. Gleiche Geschlechtsklasse (event.gender = athlete. gender, oder 'X'/'A' = alle)
     *   2. Athlet hat mindestens eine Sportklasse, die im Event enthalten ist
     *
     * @return Collection<Athlete>
     */
    public function eligibleAthletes(SwimEvent $event, Club $club): Collection
    {
        $eventClasses = $this->parseEventClasses($event->sport_classes);

        return $club->athletes()
            ->with('sportClasses')
            ->get()
            ->filter(function (Athlete $athlete) use ($event, $eventClasses): bool {
                // Geschlecht prüfen
                if (! $this->genderMatches($event->gender, $athlete->gender)) {
                    return false;
                }

                // Wenn keine Sportklassen am Event definiert → alle Athleten erlaubt
                if ($eventClasses->isEmpty()) {
                    return true;
                }

                // Athlet muss mindestens eine passende Sportklasse haben
                $athleteClasses = $athlete->sportClasses
                    ->pluck('class_number')
                    ->map(fn ($n) => (int) $n);

                return $athleteClasses->intersect($eventClasses)->isNotEmpty();
            })
            ->sortBy([
                ['last_name', 'asc'],
                ['first_name', 'asc'],
            ])
            ->values();
    }

    /**
     * Gibt alle Athleten eines Clubs zurück, die für ein Staffel-Event geeignet sind.
     * Logik analog zu eligibleAthletes — Staffelklassen-Validierung obliegt RelayClassValidator.
     *
     * @return Collection<Athlete>
     */
    public function eligibleRelayAthletes(SwimEvent $event, Club $club): Collection
    {
        // Für Staffeln: gleiche Logik, aber ohne Sportklassen-Filter
        // (RelayClassValidator prüft Teamzusammenstellung separat)
        return $club->athletes()
            ->with('sportClasses')
            ->get()
            ->filter(fn (Athlete $athlete) => $this->genderMatches($event->gender, $athlete->gender))
            ->sortBy([['last_name', 'asc'], ['first_name', 'asc']])
            ->values();
    }

    // ── Zeitformatierung (Delegation an TimeParser) ───────────────────────────

    /**
     * Jahresbestzeit: Ergebnisse vom 1.1. des Vorjahres bis zum Tag vor Meet beginn.
     * Nur gleicher Kurs (LCM oder SCM), keine SCY.
     * Nur gültige Results (status = null).
     *
     * Gibt beide Kurse zurück, falls relevant:
     *   ['LCM' ⇒ ?int, 'SCM' ⇒ ?int]  (Werte in Hundertstelsekunden)
     */
    public function bestTimes(Athlete $athlete, SwimEvent $event, Meet $meet): array
    {
        $from = Carbon::create((int) $meet->start_date->format('Y') - 1);
        $until = $meet->start_date->copy()->subDay();

        return [
            'LCM' => $this->queryBestTime($athlete, $event, 'LCM', $from, $until),
            'SCM' => $this->queryBestTime($athlete, $event, 'SCM', $from, $until),
        ];
    }

    // ── Private Hilfsmethoden ─────────────────────────────────────────────────

    /**
     * Absolute Bestzeit ohne Datum filter, für einen bestimmten Kurs.
     * Gibt null zurück, wenn keine gültige Zeit vorhanden.
     */
    public function absoluteBestTime(Athlete $athlete, SwimEvent $event, string $course): ?int
    {
        return $this->queryBestTime($athlete, $event, $course);
    }

    public function formatTime(?int $centiseconds): ?string
    {
        if ($centiseconds === null) {
            return null;
        }

        return TimeParser::display($centiseconds);
    }

    public function parseTime(string $time): ?int
    {
        return TimeParser::parse($time);
    }

    /**
     * Parst die sport_classes-Spalte ("1 2 9 10") in eine Collection von ints.
     */
    private function parseEventClasses(?string $sportClasses): Collection
    {
        if (! $sportClasses || trim($sportClasses) === '') {
            return collect();
        }

        return collect(preg_split('/[\s,]+/', trim($sportClasses)))
            ->filter(fn ($v) => $v !== '')
            ->map(fn ($v) => (int) $v);
    }

    // ── Bestzeiten ────────────────────────────────────────────────────────────

    /**
     * Prüft, ob das Event-Geschlecht auf den Athleten passt.
     * 'X' und 'A' bedeuten "alle Geschlechter".
     */
    private function genderMatches(string $eventGender, string $athleteGender): bool
    {
        return match (strtoupper($eventGender)) {
            'X', 'A' => true,
            default => strtoupper($eventGender) === strtoupper($athleteGender),
        };
    }

    /**
     * Fragt die beste Result-Zeit für einen Athleten in einem Event ab.
     * Optionaler Datumsfilter über $from/$until.
     */
    private function queryBestTime(
        Athlete $athlete,
        SwimEvent $event,
        string $course,
        ?CarbonInterface $from = null,
        ?CarbonInterface $until = null,
    ): ?int {
        $query = Result::query()
            ->where('athlete_id', $athlete->id)
            ->where('swim_event_id', $event->id)
            ->whereNull('status')          // Keine DSQ/DNS/DNF
            ->whereNotNull('swim_time')
            ->where('swim_time', '>', 0)
            ->whereHas('meet', function ($q) use ($course) {
                $q->where('course', $course);
            });

        if ($from && $until) {
            $query->whereHas('meet', function ($q) use ($from, $until) {
                $q->whereBetween('start_date', [$from->toDateString(), $until->toDateString()]);
            });
        }

        return $query->min('swim_time');
    }
}
