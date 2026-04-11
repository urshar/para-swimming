<?php

namespace App\Services;

use App\Models\Entry;
use App\Models\Meet;
use App\Models\RecordSplit;
use App\Models\RelayTeamMember;
use App\Models\Result;
use App\Models\SwimRecord;
use Illuminate\Support\Collection;
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
 * NICHT geprüft: WR, ER, OR
 *
 * Nationalitätsprüfung (Einzelrekorde):
 *   nation == 'AUT' → APPROVED | nation == null → PENDING | sonst → skip
 *
 * Staffelrekorde (via RelayClassValidator):
 *   Alle Athleten vom selben Verein, Sportklassen-Kombination muss
 *   S20 / S34 / S49 / S21 / S14 / S15 ergeben (sonst kein Rekord).
 *
 * Jugend: Einzeln Wettkampfjahr − Geburtsjahr ≤ 18 |
 *         Staffeln: alle Mitglieder mit Geburtsdatum ≤ 18
 */
readonly class RecordCheckerService
{
    public function __construct(
        private RelayClassValidator $relayValidator,
    ) {}

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
                'club',
                'swimEvent.strokeType',
                'splits',
            ])
            ->whereNull('status')
            ->whereNotNull('swim_time')
            ->get();

        $newRecords = [];
        $pendingRecords = [];
        $checked = 0;

        foreach ($results as $result) {
            $isRelay = ($result->swimEvent?->relay_count ?? 1) > 1;

            ['new' => $new, 'pending' => $pending] = $isRelay
                ? $this->checkRelayResult($result, $meet)
                : $this->checkResult($result, $meet);

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

    // ── Einzelrekord-Prüfung ──────────────────────────────────────────────────

    /**
     * Prüft ein Staffel-Result auf neue Rekorde.
     *
     * @return array{new: array, pending: array}
     *
     * @throws Throwable
     */
    public function checkRelayResult(Result $result, Meet $meet): array
    {
        $new = [];
        $pending = [];

        $event = $result->swimEvent;
        if (! $event || ! $result->swim_time || ! $result->club_id) {
            return ['new' => $new, 'pending' => $pending];
        }

        // Entries = Staffelmitglieder (selber Club + Event)
        $entries = Entry::where('swim_event_id', $event->id)
            ->where('club_id', $result->club_id)
            ->with(['athlete.nation', 'athlete.club', 'athlete.sportClasses'])
            ->get();

        if ($entries->isEmpty()) {
            return ['new' => $new, 'pending' => $pending];
        }

        // Staffelklasse validieren
        $memberClasses = $this->relayValidator->extractMemberClasses($entries, $event);
        $resolvedClass = $this->relayValidator->resolveRelayClass($memberClasses);

        if ($resolvedClass === null) {
            return ['new' => $new, 'pending' => $pending];
        }

        // Nationalitätsprüfung: alle Athleten müssen AUT sein
        foreach ($entries as $entry) {
            $code = $entry->athlete?->nation?->code;
            if ($code !== null && $code !== 'AUT') {
                return ['new' => $new, 'pending' => $pending];
            }
        }

        $strokeTypeId = $event->stroke_type_id;
        $course = $meet->course;
        $distance = $event->distance;
        $relayCount = $event->relay_count;
        $gender = $event->gender === 'F' ? 'F' : 'M';
        $meetYear = (int) $meet->start_date->format('Y');
        $isJunior = $this->relayValidator->isJuniorRelay($entries, $meetYear);

        // sport_class im Result auf validierte Klasse setzen
        if ($result->sport_class !== $resolvedClass) {
            $result->update(['sport_class' => $resolvedClass]);
        }

        // ── 1. Nationalrekord ─────────────────────────────────────────────────
        [$isNr, $newRecord] = $this->checkRecordType(
            'AUT', $strokeTypeId, $resolvedClass, $gender,
            $course, $distance, $relayCount, $result, null
        );
        if ($newRecord) {
            $this->saveRelayMembers($newRecord, $entries);
            $new[] = ['record' => $newRecord, 'types' => ['AUT']];
        }

        // ── 2. Jugendrekord ───────────────────────────────────────────────────
        if ($isJunior) {
            [$isJr, $newRecord] = $this->checkRecordType(
                'AUT.JR', $strokeTypeId, $resolvedClass, $gender,
                $course, $distance, $relayCount, $result, null
            );
            if ($newRecord) {
                $this->saveRelayMembers($newRecord, $entries);
                $new[] = ['record' => $newRecord, 'types' => ['AUT.JR']];
            }
        }

        // ── 3. Regionalrekord + 4. Regionaler Jugendrekord ───────────────────
        $regionalBase = $result->club?->regional_record_type;

        if ($regionalBase) {
            [$isRr, $newRecord] = $this->checkRecordType(
                $regionalBase, $strokeTypeId, $resolvedClass, $gender,
                $course, $distance, $relayCount, $result, null
            );
            if ($newRecord) {
                $this->saveRelayMembers($newRecord, $entries);
                $new[] = ['record' => $newRecord, 'types' => [$regionalBase]];
            }

            if ($isJunior) {
                [$isRjr, $newRecord] = $this->checkRecordType(
                    $regionalBase.'.JR', $strokeTypeId, $resolvedClass, $gender,
                    $course, $distance, $relayCount, $result, null
                );
                if ($newRecord) {
                    $this->saveRelayMembers($newRecord, $entries);
                    $new[] = ['record' => $newRecord, 'types' => [$regionalBase.'.JR']];
                }
            }
        }

        // ── Result-Flags aktualisieren ────────────────────────────────────────
        $this->updateResultFlags(
            $result,
            $isNr ?? false,
            $isJr ?? false,
            $isRr ?? false,
            $isRjr ?? false,
        );

        return ['new' => $new, 'pending' => $pending];
    }

    /**
     * Prüft ein einzelnes (Nicht-Staffel) Result auf alle relevanten Rekord-Typen.
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

        $nationCode = $result->athlete?->nation?->code;

        if ($nationCode !== 'AUT' && $nationCode !== null) {
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

        // ── 1. Nationalrekord ─────────────────────────────────────────────────
        [$isNr, $newRecord] = $this->checkRecordType(
            'AUT', $strokeTypeId, $sportClass, $gender,
            $course, $distance, $relayCount, $result, $nationId, $recordStatus, $result->athlete_id
        );

        if ($newRecord) {
            if ($isPending) {
                $pending[] = [
                    'record' => $newRecord, 'athlete_name' => $result->athlete?->display_name ?? '–', 'type' => 'AUT',
                ];
            } else {
                $new[] = ['record' => $newRecord, 'types' => ['AUT']];
            }
        }

        if ($isPending) {
            return ['new' => $new, 'pending' => $pending];
        }

        // ── 2. Jugendrekord ───────────────────────────────────────────────────
        if ($isJunior) {
            [$isJr, $newRecord] = $this->checkRecordType(
                'AUT.JR', $strokeTypeId, $sportClass, $gender,
                $course, $distance, $relayCount, $result, $nationId, 'APPROVED', $result->athlete_id
            );
            if ($newRecord) {
                $new[] = ['record' => $newRecord, 'types' => ['AUT.JR']];
            }
        }

        // ── 3. Regionalrekord + 4. Regionaler Jugendrekord ───────────────────
        $regionalBase = $result->athlete?->club?->regional_record_type;

        if ($regionalBase) {
            [$isRr, $newRecord] = $this->checkRecordType(
                $regionalBase, $strokeTypeId, $sportClass, $gender,
                $course, $distance, $relayCount, $result, $nationId, 'APPROVED', $result->athlete_id
            );
            if ($newRecord) {
                $new[] = ['record' => $newRecord, 'types' => [$regionalBase]];
            }

            if ($isJunior) {
                [$isRjr, $newRecord] = $this->checkRecordType(
                    $regionalBase.'.JR', $strokeTypeId, $sportClass, $gender,
                    $course, $distance, $relayCount, $result, $nationId, 'APPROVED', $result->athlete_id
                );
                if ($newRecord) {
                    $new[] = ['record' => $newRecord, 'types' => [$regionalBase.'.JR']];
                }
            }
        }

        // ── 5. Result-Flags aktualisieren ─────────────────────────────────────
        $this->updateResultFlags(
            $result,
            $isNr ?? false,
            $isJr ?? false,
            $isRr ?? false,
            $isRjr ?? false,
        );

        return ['new' => $new, 'pending' => $pending];
    }

    // ── Staffelrekord-Prüfung ─────────────────────────────────────────────────

    /**
     * Prüft, ob das Result einen Rekord eines bestimmten Typs bricht.
     * Legt bei Bedarf einen neuen SwimRecord an.
     *
     * @return array{0: bool, 1: SwimRecord|null}
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
        ?int $athleteId = null,
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

        if (! $current || $result->swim_time < $current->swim_time) {
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
                $athleteId,
                &$createdRecord
            ) {
                $createdRecord = SwimRecord::create([
                    'stroke_type_id' => $strokeTypeId,
                    'nation_id' => $nationId,
                    'athlete_id' => $athleteId,
                    'club_id' => $result->club_id,
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

                foreach ($result->splits as $split) {
                    RecordSplit::create([
                        'swim_record_id' => $createdRecord->id,
                        'distance' => $split->distance,
                        'split_time' => $split->split_time,
                    ]);
                }

                if ($recordStatus === 'APPROVED') {
                    $current?->markAsSupersededBy($createdRecord);
                }
            });

            return [true, $createdRecord];
        }

        return [false, null];
    }

    // ── Private Hilfsmethoden ─────────────────────────────────────────────────

    /**
     * Speichert Staffelmitglieder (aus Entries) für einen neuen SwimRecord.
     */
    private function saveRelayMembers(SwimRecord $record, Collection $entries): void
    {
        $position = 1;
        foreach ($entries as $entry) {
            $athlete = $entry->athlete;
            RelayTeamMember::create([
                'swim_record_id' => $record->id,
                'position' => $position++,
                'first_name' => $athlete?->first_name ?? '',
                'last_name' => $athlete?->last_name ?? '',
                'birth_date' => $athlete?->birth_date,
                'gender' => $athlete?->gender,
                'athlete_id' => $athlete?->id,
            ]);
        }
    }

    /**
     * Aktualisiert die Rekord-Flags am Result — extrahiert, um Duplikation zu vermeiden.
     */
    private function updateResultFlags(
        Result $result,
        bool $isNr,
        bool $isJr,
        bool $isRr,
        bool $isRjr,
    ): void {
        if ($isNr || $isJr || $isRr || $isRjr) {
            $result->update([
                'is_national_record' => $isNr,
                'is_junior_record' => $isJr,
                'is_regional_record' => $isRr,
                'is_regional_junior_record' => $isRjr,
            ]);
        }
    }

    /**
     * Prüft, ob ein Einzelathlet beim Wettkampf als Jugendlicher gilt.
     */
    private function isJunior(Result $result, Meet $meet): bool
    {
        $birthDate = $result->athlete?->birth_date;
        $meetDate = $meet->start_date;

        if (! $birthDate || ! $meetDate) {
            return false;
        }

        return ((int) $meetDate->format('Y') - (int) $birthDate->format('Y')) <= 18;
    }
}
