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
 * Unterstützte Rekord-Typen (nur nationale/regionale Rekorde):
 *   AUT             → Österreichischer Nationalrekord (altersunabhängig)
 *   AUT.JR          → Österreichischer Jugendrekord (Jahrgang ≤ 18 im Wettkampfjahr)
 *   AUT.WBSV        → Wiener BehindertenSportVerband (altersunabhängig)
 *   AUT.WBSV.JR     → Wiener BehindertenSportVerband Jugend
 *   AUT.BBSV        → Burgenländischer BSV (altersunabhängig)
 *   AUT.BBSV.JR     → Burgenländischer BSV Jugend
 *   AUT.KLSV        → Kärntner BSV (altersunabhängig)
 *   AUT.KLSV.JR     → Kärntner BSV Jugend
 *   AUT.NOEVSV      → Niederösterreichischer VSV (altersunabhängig)
 *   AUT.NOEVSV.JR   → Niederösterreichischer VSV Jugend
 *   AUT.OBSV        → Oberösterreichischer BSV (altersunabhängig)
 *   AUT.OBSV.JR     → Oberösterreichischer BSV Jugend
 *   AUT.SBSV        → Salzburger BSV (altersunabhängig)
 *   AUT.SBSV.JR     → Salzburger BSV Jugend
 *   AUT.STBSV       → Steirischer BSV (altersunabhängig)
 *   AUT.STBSV.JR    → Steirischer BSV Jugend
 *   AUT.TBSV        → Tiroler BSV (altersunabhängig)
 *   AUT.TBSV.JR     → Tiroler BSV Jugend
 *   AUT.VBSV        → Vorarlberger BSV (altersunabhängig)
 *   AUT.VBSV.JR     → Vorarlberger BSV Jugend
 *
 * NICHT geprüft (internationale Rekorde):
 *   WR, ER, OR — werden nicht automatisch gesetzt
 *
 * Altersregel Jugendrekord:
 *   Alter = Wettkampfjahr − Geburtsjahr (Jahrgangs-Regel, Stand 31.12.)
 *   Jugend = Alter ≤ 18
 *
 * Ablauf pro Result:
 *   1. Prüfen ob Athlet Österreicher ist → sonst kein Check
 *   2. Nationalrekord (AUT) prüfen
 *   3. Jugendrekord (AUT.JR) prüfen wenn Alter ≤ 18
 *   4. Regionalrekord (AUT.XXXX) prüfen wenn Club einem Verband zugeordnet ist
 *   5. Regionalen Jugendrekord (AUT.XXXX.JR) prüfen wenn Alter ≤ 18 + Regionalverband
 *   6. Result Rekord-Flags aktualisieren
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
        $results = $meet->results()
            ->with([
                'athlete.nation',
                'athlete.club',
                'swimEvent.strokeType',
                'splits',
            ])
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

        // Nur österreichische Athleten
        $nationCode = $result->athlete?->nation?->code;
        if ($nationCode !== 'AUT') {
            return;
        }

        $strokeTypeId = $event->stroke_type_id;
        $course = $meet->course;
        $distance = $event->distance;
        $relayCount = $event->relay_count;
        $sportClass = $result->sport_class;
        $gender = $result->athlete->gender === 'F' ? 'F' : 'M';
        $nationId = $result->athlete->nation->id;
        $isJunior = $this->isJunior($result, $meet);

        $isJr = false;
        $isRr = false;
        $isRjr = false;

        // ── 1. Österreichischer Nationalrekord ────────────────────────────────
        $isNr = $this->checkRecordType(
            'AUT',
            $strokeTypeId, $sportClass, $gender,
            $course, $distance, $relayCount,
            $result, $nationId
        );

        // ── 2. Österreichischer Jugendrekord ──────────────────────────────────
        if ($isJunior) {
            $isJr = $this->checkRecordType(
                'AUT.JR',
                $strokeTypeId, $sportClass, $gender,
                $course, $distance, $relayCount,
                $result, $nationId
            );
        }

        // ── 3. Regionalrekord + 4. Regionaler Jugendrekord ───────────────────
        // regional_record_type liefert z.B. "AUT.WBSV" (siehe Club Model)
        $regionalBase = $result->athlete?->club?->regional_record_type;

        if ($regionalBase) {
            // Altersunabhängiger Regionalrekord
            $isRr = $this->checkRecordType(
                $regionalBase,
                $strokeTypeId, $sportClass, $gender,
                $course, $distance, $relayCount,
                $result, $nationId
            );

            // Regionaler Jugendrekord — z.B. "AUT.WBSV.JR"
            if ($isJunior) {
                $isRjr = $this->checkRecordType(
                    $regionalBase.'.JR',
                    $strokeTypeId, $sportClass, $gender,
                    $course, $distance, $relayCount,
                    $result, $nationId
                );
            }
        }

        // ── 5. Result-Flags aktualisieren ─────────────────────────────────────
        if ($isNr || $isJr || $isRr || $isRjr) {
            $result->update([
                'is_national_record' => $isNr,
                'is_junior_record' => $isJr,
                'is_regional_record' => $isRr,
                'is_regional_junior_record' => $isRjr,
            ]);
        }
    }

    // ── Private Hilfsmethoden ─────────────────────────────────────────────────

    /**
     * Prüft ob das Result einen Rekord eines bestimmten Typs bricht.
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

        // Kein bestehender Rekord → automatisch neuer Rekord
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
     * Prüft ob ein Athlet beim Wettkampf als Jugendlicher gilt.
     *
     * Regel: Jahrgangs-Alter = Wettkampfjahr − Geburtsjahr ≤ 18
     * (Stichtag 31.12. des Wettkampfjahres)
     *
     * Gibt false zurück wenn Geburtsdatum fehlt oder Meet kein Datum hat.
     */
    private function isJunior(Result $result, Meet $meet): bool
    {
        $birthDate = $result->athlete?->birth_date;
        $meetDate = $meet->start_date;

        if (! $birthDate || ! $meetDate) {
            return false;
        }

        $meetYear = (int) $meetDate->format('Y');
        $birthYear = (int) $birthDate->format('Y');

        return ($meetYear - $birthYear) <= 18;
    }
}
