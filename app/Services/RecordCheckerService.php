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
 * neue Rekord-Einträge an, wenn ein Ergebnis einen Rekord bricht.
 *
 * Unterstützte Rekord-Typen (nur nationale/regionale Rekorde):
 *   AUT             → Österreichischer Nationalrekord (altersunabhängig)
 *   AUT.JR          → Österreichischer Jugendrekord (Jahrgang ≤ 18 im Wettkampfjahr)
 *   AUT.WBSV        → Wiener BehindertenSportVerband (altersunabhängig)
 *   AUT.WBSV.JR     → Wiener BehindertenSportVerband Jugend
 *   ... (alle 9 Landesverbände, jeweils altersunabhängig + JR)
 *
 * NICHT geprüft (internationale Rekorde):
 *   WR, ER, OR — werden nicht automatisch gesetzt
 *
 * Nationalitätsprüfung:
 *   - nation.code == 'AUT'  → normale Rekordprüfung, status = APPROVED
 *   - nation == null        → PENDING-Rekord anlegen (Nationalität ungeklärt)
 *   - nation.code != 'AUT'  → überspringen, kein Rekord
 *
 * Altersregel Jugendrekord:
 *   Alter = Wettkampfjahr − Geburtsjahr (Jahrgangs-Regel, Stand 31.12.)
 *   Jugend = Alter ≤ 18
 *
 * Ablauf pro Result:
 *   1. Nationalität prüfen → skip / PENDING / normal
 *   2. Nationalrekord (AUT) prüfen
 *   3. Jugendrekord (AUT.JR) prüfen wenn Alter ≤ 18
 *   4. Regionalrekord (AUT.XXXX) prüfen, wenn Club einem Verband zugeordnet ist
 *   5. Regionalen Jugendrekord (AUT.XXXX.JR) prüfen wenn Alter ≤ 18 + Regionalverband
 *   6. Result Rekord-Flags aktualisieren
 */
class RecordCheckerService
{
    /**
     * Prüft alle gültigen Results eines Meets auf neue Rekorde.
     *
     * @return array{
     *     new_records: array<int, array{record: SwimRecord, types: string[]}>,
     *     pending_records: array<int, array{record: SwimRecord, athlete_name: string}>,
     *     checked: int,
     * }
     *
     * @throws Throwable
     */
    public function checkMeet(Meet $meet): array
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

        $newRecords = [];
        $pendingRecords = [];
        $checked = 0;

        foreach ($results as $result) {
            ['new' => $new, 'pending' => $pending] = $this->checkResult($result, $meet);
            $newRecords = array_merge($newRecords, $new);
            $pendingRecords = array_merge($pendingRecords, $pending);
            $checked++;
        }

        return [
            'new_records' => $newRecords,
            'pending_records' => $pendingRecords,
            'checked' => $checked,
        ];
    }

    /**
     * Prüft ein einzelnes Result auf alle relevanten Rekord-Typen.
     *
     * @return array{new: array, pending: array}
     *
     * @throws Throwable
     */
    public function checkResult(Result $result, Meet $meet): array
    {
        $new = [];
        $pending = [];

        $event = $result->swimEvent;
        if (! $event || ! $result->swim_time || ! $result->sport_class) {
            return ['new' => $new, 'pending' => $pending];
        }

        // ── Nationalitätsprüfung ──────────────────────────────────────────────
        $nationCode = $result->athlete?->nation?->code;

        if ($nationCode !== 'AUT' && $nationCode !== null) {
            // Explizit nicht österreichisch → kein Rekord
            return ['new' => $new, 'pending' => $pending];
        }

        $isPending = ($nationCode === null);
        $strokeTypeId = $event->stroke_type_id;
        $course = $meet->course;
        $distance = $event->distance;
        $relayCount = $event->relay_count;
        $sportClass = $result->sport_class;
        $gender = $result->athlete?->gender === 'F' ? 'F' : 'M';
        $nationId = $result->athlete?->nation?->id;
        $isJunior = ! $isPending && $this->isJunior($result, $meet);

        $recordStatus = $isPending ? 'PENDING' : 'APPROVED';

        // ── 1. Österreichischer Nationalrekord ────────────────────────────────
        [$isNr, $newRecord] = $this->checkRecordType(
            'AUT',
            $strokeTypeId, $sportClass, $gender,
            $course, $distance, $relayCount,
            $result, $nationId, $recordStatus
        );

        if ($newRecord) {
            if ($isPending) {
                $pending[] = [
                    'record' => $newRecord,
                    'athlete_name' => $result->athlete?->display_name ?? '–',
                    'type' => 'AUT',
                ];
            } else {
                $new[] = ['record' => $newRecord, 'types' => ['AUT']];
            }
        }

        // Wenn PENDING → keine weiteren Rekord-Typen prüfen
        if ($isPending) {
            return ['new' => $new, 'pending' => $pending];
        }

        // ── 2. Österreichischer Jugendrekord ──────────────────────────────────
        if ($isJunior) {
            [$isJr, $newRecord] = $this->checkRecordType(
                'AUT.JR',
                $strokeTypeId, $sportClass, $gender,
                $course, $distance, $relayCount,
                $result, $nationId
            );

            if ($newRecord) {
                $new[] = ['record' => $newRecord, 'types' => ['AUT.JR']];
            }
        }

        // ── 3. Regionalrekord + 4. Regionaler Jugendrekord ───────────────────
        // regional_record_type liefert z.B. "AUT.WBSV" (siehe Club Model Accessor)
        $regionalBase = $result->athlete?->club?->regional_record_type;

        if ($regionalBase) {
            [$isRr, $newRecord] = $this->checkRecordType(
                $regionalBase,
                $strokeTypeId, $sportClass, $gender,
                $course, $distance, $relayCount,
                $result, $nationId
            );

            if ($newRecord) {
                $new[] = ['record' => $newRecord, 'types' => [$regionalBase]];
            }

            if ($isJunior) {
                [$isRjr, $newRecord] = $this->checkRecordType(
                    $regionalBase.'.JR',
                    $strokeTypeId, $sportClass, $gender,
                    $course, $distance, $relayCount,
                    $result, $nationId
                );

                if ($newRecord) {
                    $new[] = ['record' => $newRecord, 'types' => [$regionalBase.'.JR']];
                }
            }
        }

        // ── 5. Result-Flags aktualisieren ─────────────────────────────────────
        $isNr = $isNr ?? false;
        $isJr = $isJr ?? false;
        $isRr = $isRr ?? false;
        $isRjr = $isRjr ?? false;

        if ($isNr || $isJr || $isRr || $isRjr) {
            $result->update([
                'is_national_record' => $isNr,
                'is_junior_record' => $isJr,
                'is_regional_record' => $isRr,
                'is_regional_junior_record' => $isRjr,
            ]);
        }

        return ['new' => $new, 'pending' => $pending];
    }

    // ── Private Hilfsmethoden ─────────────────────────────────────────────────

    /**
     * Prüft, ob ein Athlet beim Wettkampf als Jugendlicher gilt.
     *
     * Regel: Jahrgangs-Alter = Wettkampfjahr − Geburtsjahr ≤ 18
     * (Stichtag 31.12. des Wettkampfjahres)
     *
     * Gibt false zurück, wenn Geburtsdatum fehlt oder Meet kein Datum hat.
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

    /**
     * Prüft, ob das Result einen Rekord eines bestimmten Typs bricht.
     * Legt bei Bedarf einen neuen SwimRecord an.
     *
     * @return array{0: bool, 1: SwimRecord|null} [isNewRecord, createdRecord]
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
        ?int $nationId,
        string $recordStatus = 'APPROVED',
    ): array {
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
            return [false, null];
        }

        $createdRecord = null;

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
            $current,
            $recordStatus,
            &$createdRecord
        ) {
            $createdRecord = SwimRecord::create([
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
                'record_status' => $recordStatus,
                'is_current' => true,
                'set_date' => $result->meet?->start_date,
                'meet_name' => $result->meet?->name,
                'meet_city' => $result->meet?->city,
                'meet_course' => $result->meet?->course,
            ]);

            // Splits übernehmen
            foreach ($result->splits as $split) {
                RecordSplit::create([
                    'swim_record_id' => $createdRecord->id,
                    'distance' => $split->distance,
                    'split_time' => $split->split_time,
                ]);
            }

            // Alten Rekord auf historisch setzen (nur wenn APPROVED, nicht wenn PENDING)
            if ($recordStatus === 'APPROVED') {
                $current?->markAsSupersededBy($createdRecord);
            }
        });

        return [true, $createdRecord];
    }
}
