<?php

namespace App\Services;

use App\Models\Meet;
use App\Models\RecordSplit;
use App\Models\Result;
use App\Models\SwimRecord;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * RecordCheckerService
 *
 * Prüft Wettkampf-Ergebnisse gegen bestehende Rekorde und legt
 * neue Rekord-Einträge an wenn ein Ergebnis einen Rekord bricht.
 *
 * Unterstützte Rekord-Typen:
 *   WR  → Weltrekord
 *   ER  → Europarekord
 *   NR  → Nationalrekord (gefiltert nach Nation des Athleten)
 *
 * Ablauf pro Result:
 *   1. Aktuellen Rekord für diese Kombination suchen
 *      (record_type + sport_class + gender + course + distance + relay_count)
 *   2. Ist das Result schneller? → neuen Rekord anlegen
 *   3. Alten Rekord auf APPROVED.HISTORY + is_current=false setzen
 *   4. Result Rekord-Flags aktualisieren (is_world_record usw.)
 */
class RecordCheckerService
{
    /**
     * Prüft alle gültigen Results eines Meets auf neue Rekorde.
     *
     * @return int Anzahl geprüfter Results
     *
     * @throws Throwable
     */
    public function checkMeetResults(Meet $meet): int
    {
        $meet->load(['nation']);

        $results = $meet->results()
            ->with(['athlete.nation', 'swimEvent.strokeType', 'splits'])
            ->whereNull('status') // nur gültige Ergebnisse
            ->whereNotNull('swim_time')
            ->get();

        $checked = 0;

        foreach ($results as $result) {
            $this->checkResult($result, $meet);
            $checked++;
        }

        return $checked;
    }

    /**
     * Prüft ein einzelnes Result auf alle relevanten Rekord-Typen.
     *
     * @throws Throwable
     */
    public function checkResult(Result $result, Meet $meet): void
    {
        $event = $result->swimEvent;
        if (! $event || ! $result->swim_time || ! $result->sport_class) {
            return;
        }

        $strokeTypeId = $event->stroke_type_id;
        $course = $meet->course;
        $distance = $event->distance;
        $relayCount = $event->relay_count;
        $sportClass = $result->sport_class;
        $gender = $result->athlete?->gender === 'F' ? 'F' : 'M';

        // Weltrekord prüfen
        $isWr = $this->checkRecordType(
            'WR', $strokeTypeId, $sportClass, $gender,
            $course, $distance, $relayCount,
            $result, null
        );

        // Europarekord prüfen (nur wenn Nation in Europa)
        $isEr = false;
        if ($this->isEuropeanNation($result->athlete?->nation?->code)) {
            $isEr = $this->checkRecordType(
                'ER', $strokeTypeId, $sportClass, $gender,
                $course, $distance, $relayCount,
                $result, null
            );
        }

        // Nationalrekord prüfen
        $isNr = false;
        $nationCode = $result->athlete?->nation?->code;
        if ($nationCode) {
            $nationId = $result->athlete->nation->id;
            $isNr = $this->checkRecordType(
                $nationCode, $strokeTypeId, $sportClass, $gender,
                $course, $distance, $relayCount,
                $result, $nationId
            );
        }

        // Rekord-Flags am Result aktualisieren
        if ($isWr || $isEr || $isNr) {
            $result->update([
                'is_world_record' => $isWr,
                'is_european_record' => $isEr,
                'is_national_record' => $isNr,
            ]);
        }
    }

    // ── Private Hilfsmethoden ─────────────────────────────────────────────────

    /**
     * Prüft, ob das Result einen Rekord eines bestimmten Typs bricht.
     * Legt bei Bedarf einen neuen SwimRecord an.
     *
     * @throws Throwable
     */
    private function checkRecordType(
        string $recordType,
        int $strokeTypeId,
        string $sportClass,
        string $gender,
        string $course,
        int $distance,
        int $relayCount,
        Result $result,
        ?int $nationId
    ): bool {
        $current = SwimRecord::where('record_type', $recordType)
            ->where('stroke_type_id', $strokeTypeId)
            ->where('sport_class', $sportClass)
            ->where('gender', $gender)
            ->where('course', $course)
            ->where('distance', $distance)
            ->where('relay_count', $relayCount)
            ->where('is_current', true)
            ->first();

        // Kein bestehender Rekord → das ist automatisch ein neuer Rekord
        $isNewRecord = ! $current || $result->swim_time < $current->swim_time;

        if (! $isNewRecord) {
            return false;
        }

        DB::transaction(function () use (
            $recordType,
            $strokeTypeId,
            $sportClass,
            $gender,
            $course,
            $distance,
            $relayCount,
            $result,
            $nationId,
            $current
        ) {
            $newRecord = SwimRecord::create([
                'stroke_type_id' => $strokeTypeId,
                'nation_id' => $nationId,
                'athlete_id' => $result->athlete_id,
                'result_id' => $result->id,
                'supersedes_id' => $current?->id,
                'record_type' => $recordType,
                'sport_class' => $sportClass,
                'gender' => $gender,
                'course' => $course,
                'distance' => $distance,
                'relay_count' => $relayCount,
                'swim_time' => $result->swim_time,
                'record_status' => 'APPROVED',
                'is_current' => true,
                'set_date' => $result->meet?->start_date,
                'meet_name' => $result->meet?->name,
                'meet_city' => $result->meet?->city,
                'meet_course' => $result->meet?->course,
            ]);

            // Splits übernehmen
            foreach ($result->splits as $split) {
                RecordSplit::create([
                    'swim_record_id' => $newRecord->id,
                    'distance' => $split->distance,
                    'split_time' => $split->split_time,
                ]);
            }

            // Alten Rekord auf historisch setzen
            $current?->markAsSupersededBy($newRecord);
        });

        return true;
    }

    /**
     * Europäische Nationen laut IOC-Codes.
     * Relevant für Europarekord-Prüfung.
     */
    private function isEuropeanNation(?string $code): bool
    {
        if (! $code) {
            return false;
        }

        $european = [
            'ALB', 'AND', 'ARM', 'AUT', 'AZE', 'BEL', 'BIH', 'BLR',
            'BUL', 'CRO', 'CYP', 'CZE', 'DEN', 'ESP', 'EST', 'FIN',
            'FRA', 'GBR', 'GEO', 'GER', 'GRE', 'HUN', 'IRL', 'ISL',
            'ISR', 'ITA', 'KAZ', 'KOS', 'LAT', 'LIE', 'LTU', 'LUX',
            'MDA', 'MKD', 'MLT', 'MNE', 'MON', 'NED', 'NOR', 'POL',
            'POR', 'ROU', 'RUS', 'SLO', 'SMR', 'SRB', 'SVK', 'SUI',
            'SWE', 'TUR', 'UKR',
        ];

        return in_array(strtoupper($code), $european, true);
    }
}
