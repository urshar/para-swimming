<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\RelayEntryMember;
use App\Models\Result;
use App\Models\StrokeType;
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
     * @return Collection<int, Athlete>
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
     *
     * Ausgeschlossen werden Athleten die im selben Meet bereits in einer anderen
     * Staffelmeldung desselben Events gemeldet sind (gleiche swim_event_id).
     * Beim Bearbeiten einer bestehenden Staffel ($excludeRelayEntryId) bleiben
     * die eigenen Mitglieder dieser Staffel weiterhin wählbar.
     *
     * @return Collection<int, Athlete>
     */
    public function eligibleRelayAthletes(
        SwimEvent $event,
        Club $club,
        ?int $excludeRelayEntryId = null,
    ): Collection {
        // Athleten-IDs die bereits in einer anderen Staffelmeldung desselben Events gemeldet sind
        $bookedAthleteIds = RelayEntryMember::query()
            ->whereHas('relayEntry', function ($q) use ($event, $excludeRelayEntryId) {
                $q->where('meet_id', $event->meet_id)
                    ->where('swim_event_id', $event->id)
                    ->when($excludeRelayEntryId, fn ($q) => $q->where('id', '!=', $excludeRelayEntryId));
            })
            ->pluck('athlete_id');

        return $club->athletes()
            ->where('is_active', true) // nur aktive Sportler in der Auswahl
            ->with('sportClasses')
            ->get()
            ->filter(fn (Athlete $athlete) => $this->genderMatches($event->gender, $athlete->gender)
                && ! $bookedAthleteIds->contains($athlete->id)
            )
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

    /**
     * Vollständige Bestzeiten fürs Melde-Panel: je Kurs (LCM/SCM) Jahres- UND absolute
     * Bestzeit, jeweils roh + formatiert ('NT' wenn keine). Genutzt von den best-times-
     * AJAX-Endpoints in ClubEntryController (Vereinsmeldung) und EntryController (Admin).
     */
    public function bestTimesForPanel(Athlete $athlete, SwimEvent $event, Meet $meet): array
    {
        return [
            'LCM' => $this->panelCourse($athlete, $event, $meet, 'LCM'),
            'SCM' => $this->panelCourse($athlete, $event, $meet, 'SCM'),
        ];
    }

    /**
     * Vorgeschlagene Staffel-Meldezeit als Summe der Einzel-Bestzeiten der (der Reihe nach)
     * gemeldeten Athleten über die Teilstrecke. Je Kurs (LCM/SCM) Jahres- UND absolute Summe.
     *
     * Teilstrecken-Disziplin: Distanz = Staffeldistanz, relay_count = 1, Stil je nach Staffelart
     * (siehe relayLegStrokeIds()). Es wird über die vorhandenen Einzel-Bestzeiten summiert; fehlende
     * (leere Positionen oder Schwimmer ohne Zeit) werden über 'missing'/'total' gemeldet (Teilsumme).
     *
     * @param  array<int, int>  $orderedAthleteIds  Athleten-IDs in Startreihenfolge (Position = Index)
     * @return array{LCM: array, SCM: array}
     */
    public function relayBestTimes(SwimEvent $relayEvent, array $orderedAthleteIds, Meet $meet): array
    {
        $from = Carbon::create((int) $meet->start_date->format('Y') - 1);
        $until = $meet->start_date->copy()->subDay();

        $legStrokeIds = $this->relayLegStrokeIds($relayEvent);

        return [
            'LCM' => $this->relaySumCourse($relayEvent, $orderedAthleteIds, $legStrokeIds, 'LCM', $from, $until),
            'SCM' => $this->relaySumCourse($relayEvent, $orderedAthleteIds, $legStrokeIds, 'SCM', $from, $until),
        ];
    }

    // ── Private Hilfsmethoden ─────────────────────────────────────────────────

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

    /** Ein Kurs-Eintrag fürs Panel: Jahres- + absolute Bestzeit (Zeit, formatiert, Datum). */
    private function panelCourse(Athlete $athlete, SwimEvent $event, Meet $meet, string $course): array
    {
        $from = Carbon::create((int) $meet->start_date->format('Y') - 1);
        $until = $meet->start_date->copy()->subDay();

        return [
            'year' => $this->panelTime($this->bestResult($athlete, $event, $course, $from, $until)),
            'absolute' => $this->panelTime($this->bestResult($athlete, $event, $course)),
        ];
    }

    /** Result → {raw, formatted, date} fürs Panel ('NT' + null, wenn keine Zeit vorhanden). */
    private function panelTime(?Result $result): array
    {
        return [
            'raw' => $result?->swim_time,
            'formatted' => $this->formatTime($result?->swim_time) ?? 'NT',
            'date' => $result?->meet?->start_date?->format('d.m.Y'),
        ];
    }

    /**
     * Ein Kurs-Eintrag der Staffel-Summe. Enthält die aggregierten "Alles"-Summen
     * (year/absolute, jeweils {raw, formatted, missing, total}) UND eine per-Schwimmer-Aufschlüsselung
     * ('legs': je Startposition {athlete_id, year, absolute}), damit das Formular auch eine gemischte
     * Summe (pro Schwimmer JBZ oder ABZ) bilden kann. Beide Ergebnismengen entstehen in EINEM Durchlauf.
     */
    private function relaySumCourse(
        SwimEvent $relayEvent,
        array $orderedAthleteIds,
        array $legStrokeIds,
        string $course,
        CarbonInterface $from,
        CarbonInterface $until,
    ): array {
        $total = (int) $relayEvent->relay_count;
        $distance = (int) $relayEvent->distance;

        $legs = [];
        $yearSum = 0;
        $yearContributed = 0;
        $absoluteSum = 0;
        $absoluteContributed = 0;

        foreach (array_values($orderedAthleteIds) as $pos => $athleteId) {
            if ($pos >= $total) {
                break; // mehr Athleten als Staffelplätze — überzählige ignorieren
            }

            $strokeId = (int) ($legStrokeIds[$pos] ?? $legStrokeIds[0]);
            $athleteId = (int) $athleteId;

            $yearResult = $this->bestResultForDiscipline($athleteId, $distance, $strokeId, 1, $course, $from, $until);
            $absoluteResult = $this->bestResultForDiscipline($athleteId, $distance, $strokeId, 1, $course);

            if ($yearResult?->swim_time !== null) {
                $yearSum += $yearResult->swim_time;
                $yearContributed++;
            }
            if ($absoluteResult?->swim_time !== null) {
                $absoluteSum += $absoluteResult->swim_time;
                $absoluteContributed++;
            }

            $legs[] = [
                'athlete_id' => $athleteId,
                'year' => $this->panelTime($yearResult),
                'absolute' => $this->panelTime($absoluteResult),
            ];
        }

        return [
            'year' => $this->relaySumAggregate($yearSum, $yearContributed, $total),
            'absolute' => $this->relaySumAggregate($absoluteSum, $absoluteContributed, $total),
            'legs' => $legs,
        ];
    }

    /**
     * Fasst eine Summe zusammen: {raw, formatted, missing, total}. 'missing' = Positionen ohne Zeit
     * (leer oder ohne Ergebnis), 'total' = relay_count. 'NT'/null, wenn nichts beigetragen hat.
     *
     * @return array{raw: ?int, formatted: string, missing: int, total: int}
     */
    private function relaySumAggregate(int $sum, int $contributed, int $total): array
    {
        return [
            'raw' => $contributed > 0 ? $sum : null,
            'formatted' => $contributed > 0 ? $this->formatTime($sum) : 'NT',
            'missing' => $total - $contributed,
            'total' => $total,
        ];
    }

    /**
     * Ermittelt je Startposition (Index) die Einzel-Teilstrecken-Stroke-ID.
     *   - Freistilstaffel (FREE): jeder Freistil
     *   - Lagenstaffel (MEDLEY, genau 4 Beine): Pos 1 Rücken, 2 Brust, 3 Schmetterling, 4 Freistil
     *   - Lagen-Staffel jeder alle Stile (IMRELAY): jeder Einzel-Lagen (MEDLEY)
     *   - sonst / MEDLEY ≠ 4 Beine: Freistil-Fallback (bzw. Stroke des Staffel-Events, falls FREE fehlt)
     *
     * @return array<int, int> Position (0-basiert) => stroke_type_id
     */
    private function relayLegStrokeIds(SwimEvent $relayEvent): array
    {
        $count = max((int) $relayEvent->relay_count, 1);
        $ids = StrokeType::whereIn('lenex_code', ['FREE', 'BACK', 'BREAST', 'FLY', 'MEDLEY'])
            ->pluck('id', 'lenex_code');

        $relayCode = $relayEvent->strokeType?->lenex_code;
        $fallback = (int) ($ids['FREE'] ?? $relayEvent->stroke_type_id);

        if ($relayCode === 'MEDLEY' && $count === 4) {
            return [
                (int) ($ids['BACK'] ?? $fallback),
                (int) ($ids['BREAST'] ?? $fallback),
                (int) ($ids['FLY'] ?? $fallback),
                (int) ($ids['FREE'] ?? $fallback),
            ];
        }

        $legStroke = match ($relayCode) {
            'IMRELAY' => (int) ($ids['MEDLEY'] ?? $relayEvent->stroke_type_id),
            default => $fallback,
        };

        return array_fill(0, $count, $legStroke);
    }

    /**
     * Parst die sport_classes-Spalte in eine Collection von reinen Klassennummern (ints).
     *
     * swim_events.sport_classes speichert die vollen Codes mit Kategorie-Präfix
     * (z. B. "S1 S2 S9 SB4"), passend zum Formularhinweis in swim-events/form.blade.php.
     * (int) "S1" liefert 0 (PHP bricht beim ersten Nicht-Ziffern-Zeichen ab) — das
     * ließ hier JEDEN Athleten als nicht passend durchfallen, weil $eventClasses nur
     * Nullen enthielt, athlete_sport_classes.class_number aber nie 0 ist. Vor dem Cast
     * daher alles außer Ziffern entfernen ("S1" → "1", "SB4" → "4").
     */
    private function parseEventClasses(?string $sportClasses): Collection
    {
        if (! $sportClasses || trim($sportClasses) === '') {
            return collect();
        }

        return collect(preg_split('/[\s,]+/', trim($sportClasses)))
            ->filter(fn ($v) => $v !== '')
            ->map(fn ($v) => (int) preg_replace('/\D+/', '', $v));
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
        return $this->bestResult($athlete, $event, $course, $from, $until)?->swim_time;
    }

    /**
     * Das schnellste gültige Result eines Athleten für die Disziplin (Distanz + Schwimmstil +
     * relay_count) auf dem gegebenen Kurs — inkl. Meet (fürs Datum). Optionaler Datumsfilter.
     *
     * Match über die DISZIPLIN, nicht über die exakte swim_event_id: die historischen Ergebnisse
     * eines Athleten hängen an den Events VERGANGENER Meets, nicht an dem gerade angelegten Event
     * dieses Meets — ein Match auf $event->id fand daher nie etwas und lieferte immer NT.
     */
    private function bestResult(
        Athlete $athlete,
        SwimEvent $event,
        string $course,
        ?CarbonInterface $from = null,
        ?CarbonInterface $until = null,
    ): ?Result {
        return $this->bestResultForDiscipline(
            $athlete->id,
            (int) $event->distance,
            (int) $event->stroke_type_id,
            (int) $event->relay_count,
            $course,
            $from,
            $until,
        );
    }

    /**
     * Das schnellste gültige Result eines Athleten für eine explizit angegebene Disziplin
     * (Distanz + Schwimmstil + relay_count) auf dem gegebenen Kurs — inkl. Meet (fürs Datum).
     * Optionaler Datumsfilter. Basis für Einzel- (bestResult) und Staffel-Bestzeiten (relaySum).
     */
    private function bestResultForDiscipline(
        int $athleteId,
        int $distance,
        int $strokeTypeId,
        int $relayCount,
        string $course,
        ?CarbonInterface $from = null,
        ?CarbonInterface $until = null,
    ): ?Result {
        $query = Result::query()
            ->with('meet:id,start_date')
            ->where('athlete_id', $athleteId)
            ->whereNull('status')          // Keine DSQ/DNS/DNF
            ->whereNotNull('swim_time')
            ->where('swim_time', '>', 0)
            ->whereHas('swimEvent', function ($q) use ($distance, $strokeTypeId, $relayCount) {
                $q->where('distance', $distance)
                    ->where('stroke_type_id', $strokeTypeId)
                    ->where('relay_count', $relayCount);
            })
            ->whereHas('meet', function ($q) use ($course) {
                $q->where('course', $course);
            });

        if ($from && $until) {
            $query->whereHas('meet', function ($q) use ($from, $until) {
                $q->whereBetween('start_date', [$from->toDateString(), $until->toDateString()]);
            });
        }

        return $query->orderBy('swim_time')->first();
    }
}
