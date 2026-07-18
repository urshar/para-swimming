<?php

namespace App\Services;

use App\Models\Qualification;
use App\Models\QualifyingTime;
use App\Models\QualifyingTimeList;
use App\Models\Result;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * QualificationDeterminationService
 *
 * Automatische Ermittlung aller Schwimmer, die im Qualifikationszeitraum die
 * Richtzeiten der aktuellen Liste erreicht haben (Phase 4 der Spec
 * "Richtzeiten ÖSTM & ÖM").
 *
 * Der Qualifikationszeitraum wird direkt an der QualifyingTimeList gepflegt
 * (qualification_period_start/-end) statt automatisch aus einem verknüpften
 * Ziel-Meet abgeleitet zu werden (Erik, 2026-07-18: Das Ziel-Meet des
 * Folgejahres existiert zum Zeitpunkt der Ermittlung oft noch nicht — der
 * Zeitraum beginnt mit der bereits stattgefundenen Vorjahres-ÖSTM & ÖM und
 * das Ende steht erst fest, sobald der neue Termin bekannt ist).
 *
 * "Erreicht" = Schwimmzeit ≤ Richtzeit. Bei mehreren erreichten Ergebnissen
 * wird nur die schnellste Zeit gespeichert (Erik, 2026-07-17 bestätigt: Die
 * Schwimmzeit ist gemeint, nicht die Punktzahl). "Datum der Qualifikation"
 * = Startdatum des Meets, bei dem das Ergebnis erzielt wurde (Erik
 * bestätigt — es gibt keine tagesgenaue Datumsspalte pro Ergebnis).
 *
 * Snapshot-Prinzip (Erik bestätigt): sport_class, club_id, points und
 * swim_time werden zum Berechnungszeitpunkt eingefroren (siehe Qualification-
 * Model/Migration), damit spätere Korrekturen bestehende Qualifikationslisten
 * nicht rückwirkend verändern (Phase 5). Eine Neuberechnung ersetzt alle
 * Zeilen für diese Liste vollständig.
 */
class QualificationDeterminationService
{
    /**
     * @return array{
     *     error?: string,
     *     qualified: int,
     *     candidates_checked: int,
     *     period_start?: string,
     *     period_end?: string,
     *     period_end_is_provisional?: bool,
     * }
     *
     * @throws Throwable falls die Datenbank-Transaktion fehlschlägt
     */
    public function calculateForList(QualifyingTimeList $list): array
    {
        $empty = ['qualified' => 0, 'candidates_checked' => 0];

        if (! $list->qualification_period_start) {
            return $empty + ['error' => "Richtzeitenliste $list->year: Zeitraum-Beginn ist nicht gesetzt."];
        }

        // Zeitraum-Ende ist "variabel" (Erik, 2026-07-18): steht der ÖSTM & ÖM-
        // Termin des Folgejahres noch nicht fest, wird vorläufig bis heute
        // gerechnet. Sobald das Ende eingetragen wird, liefert eine erneute
        // Berechnung die endgültige Liste.
        $periodEndIsProvisional = ! $list->qualification_period_end;
        $periodEndDate = $list->qualification_period_end ?? now();

        if ($list->qualification_period_start->greaterThan($periodEndDate)) {
            return $empty + ['error' => 'Zeitraum-Beginn liegt nach Zeitraum-Ende.'];
        }

        $periodStart = $list->qualification_period_start->toDateString();
        $periodEnd = $periodEndDate->toDateString();

        // Richtzeiten der Liste indizieren: "stroke_type_id|distance|gender|sport_class" -> QualifyingTime
        $qualifyingTimesByKey = $list->times()
            ->whereNotNull('value_centiseconds')
            ->get()
            ->keyBy(fn (QualifyingTime $t) => "$t->stroke_type_id|$t->distance|$t->gender|$t->sport_class");

        if ($qualifyingTimesByKey->isEmpty()) {
            return $empty + ['error' => "Richtzeitenliste $list->year enthält keine berechneten Richtzeiten."];
        }

        $results = Result::query()
            ->whereNull('status')
            ->whereNotNull('swim_time')
            ->whereHas('swimEvent', fn ($q) => $q->where('relay_count', 1))
            ->whereHas('meet', fn ($q) => $q->whereBetween('start_date', [$periodStart, $periodEnd]))
            ->with(['swimEvent', 'athlete', 'meet'])
            ->get();

        $best = []; // "athlete_id|qualifying_time_id" -> ['result' => Result, 'qualifying_time' => QualifyingTime]

        foreach ($results as $result) {
            $event = $result->swimEvent;
            $athlete = $result->athlete;
            if (! $event || ! $athlete || ! $result->sport_class) {
                continue;
            }

            $gender = strtoupper((string) $athlete->gender);
            if (! in_array($gender, ['M', 'F'], true)) {
                continue;
            }

            $key = "$event->stroke_type_id|$event->distance|$gender|".strtoupper($result->sport_class);
            $qualifyingTime = $qualifyingTimesByKey->get($key);
            if (! $qualifyingTime) {
                continue; // keine Richtzeit für diese Kombination
            }

            if ($result->swim_time > $qualifyingTime->value_centiseconds) {
                continue; // Richtzeit nicht erreicht
            }

            $bestKey = "$athlete->id|$qualifyingTime->id";
            if (! isset($best[$bestKey]) || $result->swim_time < $best[$bestKey]['result']->swim_time) {
                $best[$bestKey] = ['result' => $result, 'qualifying_time' => $qualifyingTime];
            }
        }

        // Falls der Liste bereits ein Ziel-Meet zugeordnet ist, wird es rein
        // informativ mitgespeichert — ist aber für die Berechnung nicht erforderlich.
        $targetMeetId = $list->meets()->first()?->id;

        DB::transaction(function () use ($list, $best, $targetMeetId) {
            Qualification::where('qualifying_time_list_id', $list->id)->delete();

            foreach ($best as ['result' => $result, 'qualifying_time' => $qualifyingTime]) {
                Qualification::create([
                    'meet_id' => $targetMeetId,
                    'qualifying_time_list_id' => $list->id,
                    'qualifying_time_id' => $qualifyingTime->id,
                    'athlete_id' => $result->athlete_id,
                    'result_id' => $result->id,
                    'club_id' => $result->club_id,
                    'sport_class' => strtoupper($result->sport_class),
                    'swim_time_centiseconds' => $result->swim_time,
                    'points' => $result->points,
                    'qualified_at' => $result->meet->start_date,
                ]);
            }
        });

        return [
            'qualified' => count($best),
            'candidates_checked' => $results->count(),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'period_end_is_provisional' => $periodEndIsProvisional,
        ];
    }
}
