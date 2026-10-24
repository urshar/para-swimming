<?php

namespace App\Services;

use App\Models\AthleteClubHistory;
use App\Models\Club;
use App\Models\Meet;
use App\Models\RecordSplit;
use App\Models\RelayResult;
use App\Models\RelayResultMember;
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
 * Nationalitätsprüfung (Einzel und alle Staffelmitglieder): nur 'AUT' kann einen Rekord aufstellen — eine unbekannte
 * Nationalität ist kein Rekord (Entscheidung Erik, 11.10.2026).
 *
 * Mehrere Rekordbrüche in einer Veranstaltung: checkMeet() prüft die Ergebnisse nach Zeit sortiert (schnellste
 * zuerst). So stellt je Rekordtyp nur das beste Ergebnis der Veranstaltung den Rekord auf; langsamere, die den alten
 * Rekord ebenfalls unterboten hätten, landen nicht in der Kette. Jedes Ergebnis wird trotzdem auf alle Typen geprüft
 * (z. B. kein ÖR, aber Landesrekord seines Verbands).
 *
 * Außer Konkurrenz (Ergebnisstatus EXH, Einzel + Staffel): alle Rekordtypen werden geprüft, neue Rekorde aber
 * als PENDING angelegt — der Verband (Admin) bestätigt sie (SwimRecord::approve()).
 *
 * Staffelrekorde (relay_results, via RelayClassValidator):
 *   Alle Positionen besetzt, alle Athleten zum Startzeitpunkt beim Staffelverein (siehe memberBelongsToClub()) und
 *   AUT, Sportklassen-Kombination muss
 *   S20 / S34 / S49 / S21 / S14 / S15 ergeben, und die Zusammensetzung muss zum Staffel-Geschlecht passen
 *   (RelayResult::hasRecordComposition(): Herren nur Männer, Damen nur Frauen, Mixed 2 + 2). Eine Herrenstaffel mit
 *   Damenbeteiligung bleibt ein gültiges Ergebnis, stellt aber keinen Rekord auf.
 *   Die Staffelklasse wird vor Nationalität und Vereinszugehörigkeit bestimmt und am Ergebnis gespeichert. Eine
 *   Landesauswahl bzw. ein Nationalteam (Verein vom Typ Verband) stellt keinen Rekord auf, auch keinen ausstehenden.
 *
 * Jugend: Einzeln Wettkampfjahr − Geburtsjahr ≤ 18 |
 *         Staffeln: alle Mitglieder mit Geburtsdatum ≤ 18
 */
readonly class RecordCheckerService
{
    /** Grund eines ausstehenden Rekords: Start außer Konkurrenz (Ergebnisstatus EXH). */
    public const string PENDING_EXHIBITION = 'AK – Bestätigung durch den Verband nötig';

    /** Grund eines ausstehenden Staffelrekords: Vereinszugehörigkeit eines Mitglieds am Starttag nicht belegt. */
    public const string PENDING_CLUB = 'Vereinszugehörigkeit zum Startzeitpunkt nicht belegt';

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
            // Reguläre Ergebnisse und Starts außer Konkurrenz (EXH, werden als ausstehend angelegt).
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'EXH'))
            ->whereNotNull('swim_time')
            ->orderBy('swim_time')
            ->orderBy('id')
            ->get();

        $relayResults = $meet->relayResults()
            ->with([
                'club',
                'swimEvent.strokeType',
                'splits',
                'members.athlete.nation',
                'members.athlete.sportClasses',
            ])
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'EXH'))
            ->whereNotNull('swim_time')
            ->orderBy('swim_time')
            ->orderBy('id')
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

        foreach ($relayResults as $relayResult) {
            ['new' => $new, 'pending' => $pending] = $this->checkRelayResult($relayResult, $meet);
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
     * Prüft ein Staffelergebnis auf neue Rekorde.
     *
     * @return array{new: array, pending: array}
     *
     * @throws Throwable
     */
    public function checkRelayResult(RelayResult $relayResult, Meet $meet): array
    {
        $new = [];
        $pending = [];

        $event = $relayResult->swimEvent;
        $members = $relayResult->members;
        if (! $event || ! $relayResult->swim_time || ! $relayResult->club_id || $members->isEmpty()) {
            return ['new' => $new, 'pending' => $pending];
        }

        // Herrenstaffel mit Damenbeteiligung, unvollständige Besetzung: gültiges Ergebnis, aber kein Rekord.
        if (! $relayResult->hasRecordComposition()) {
            return ['new' => $new, 'pending' => $pending];
        }

        // Staffelklasse aus den Mitgliedern — eine Eigenschaft des Ergebnisses, daher vor Nationalität und
        // Vereinszugehörigkeit gesetzt (auch eine Landesauswahl, die keinen Rekord aufstellen kann, hat eine Klasse).
        $memberClasses = $this->relayValidator->extractMemberClasses($members, $event);
        $resolvedClass = $this->relayValidator->resolveRelayClass($memberClasses);

        if ($resolvedClass === null) {
            return ['new' => $new, 'pending' => $pending];
        }

        if ($relayResult->relay_class !== $resolvedClass) {
            $relayResult->update(['relay_class' => $resolvedClass]);
        }

        // Landesauswahl bzw. Nationalteam (Verein vom Typ Verband): stellt keinen Rekord auf, auch keinen ausstehenden.
        if ($relayResult->club?->type === Club::TYPE_VERBAND) {
            return ['new' => $new, 'pending' => $pending];
        }

        // Alle Mitglieder mit österreichischer Nationalität und am Starttag beim Staffelverein.
        // Ist die Zugehörigkeit eines Mitglieds nicht belegt, wird der Rekord nur ausstehend angelegt.
        $clubUnproven = false;
        $evidence = $this->clubEvidence($members, $meet);
        foreach ($members as $member) {
            $athlete = $member->athlete;
            if (! $athlete) {
                return ['new' => $new, 'pending' => $pending];
            }
            if ($athlete->nation?->code !== 'AUT') {
                return ['new' => $new, 'pending' => $pending];
            }
            $belongs = $this->memberBelongsToClub($athlete->id, $athlete->club_id, $relayResult->club_id, $evidence);
            if ($belongs === false) {
                return ['new' => $new, 'pending' => $pending];
            }
            $clubUnproven = $clubUnproven || $belongs === null;
        }

        $strokeTypeId = $event->stroke_type_id;
        $course = $meet->course;
        $distance = $event->distance;
        $relayCount = $event->relay_count;
        $gender = $relayResult->gender;
        $meetYear = (int) $meet->start_date->format('Y');
        $isJunior = $this->relayValidator->isJuniorRelay($members, $meetYear);

        // Außer Konkurrenz (EXH) oder Vereinszugehörigkeit nicht belegt: alle Rekordtypen prüfen, neue Rekorde aber
        // nur als ausstehend anlegen.
        $isExhibition = $relayResult->status === 'EXH';
        $isPending = $isExhibition || $clubUnproven;
        $reason = $isExhibition ? self::PENDING_EXHIBITION : self::PENDING_CLUB;
        $recordStatus = $isPending ? 'PENDING' : 'APPROVED';
        $relayName = $relayResult->display_name;

        $regionalBase = $relayResult->club?->regional_record_type;
        $types = array_filter([
            'AUT' => true,
            'AUT.JR' => $isJunior,
        ]);
        if ($regionalBase) {
            $types += array_filter([$regionalBase => true, $regionalBase.'.JR' => $isJunior]);
        }

        $broken = [];
        foreach (array_keys($types) as $type) {
            [$isRecord, $newRecord] = $this->checkRecordType(
                $type, $strokeTypeId, $resolvedClass, $gender,
                $course, $distance, $relayCount, $relayResult, null, $recordStatus
            );
            $broken[$type] = $isRecord;

            if ($newRecord) {
                $this->saveRelayMembers($newRecord, $members);
                $this->collect($new, $pending, $newRecord, $type, $isPending, $relayName, $reason);
            }
        }

        // Rekord-Flags nur für anerkannte Rekorde — ausstehende werden erst mit der Bestätigung zum Rekord.
        if (! $isPending) {
            $this->updateResultFlags($relayResult, $broken, $regionalBase);
        }

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

        // Unbekannte oder andere Nationalität: kein Rekord.
        if ($result->athlete?->nation?->code !== 'AUT') {
            return ['new' => $new, 'pending' => $pending];
        }

        // Start außer Konkurrenz (EXH): alle Rekordtypen wie ein regulärer, aber nur ausstehend — der Verband bestätigt.
        $isPending = $result->status === 'EXH';
        $reason = self::PENDING_EXHIBITION;
        $strokeTypeId = $event->stroke_type_id;
        $course = $meet->course;
        $distance = $event->distance;
        $relayCount = $event->relay_count;
        $sportClass = $result->sport_class;
        $gender = $result->athlete?->gender === 'F' ? 'F' : 'M';
        $nationId = $result->athlete?->nation?->id;
        $isJunior = $this->isJunior($result, $meet);
        $recordStatus = $isPending ? 'PENDING' : 'APPROVED';

        // Landesverband aus dem Verein im Ergebnis (Verein zum Zeitpunkt des Starts), nicht aus dem aktuellen Verein
        // des Athleten — sonst landet nach einem Vereinswechsel ein Rekord im falschen Bundesland. Nur ohne Verein im
        // Ergebnis der Verein des Athleten. Ein Verein ohne Landesverband (z. B. ÖBSV als Nationalteam): kein
        // Regionalrekord.
        $recordClub = $result->club_id !== null ? $result->club : $result->athlete?->club;
        $regionalBase = $recordClub?->regional_record_type;
        $types = ['AUT' => true, 'AUT.JR' => $isJunior];
        if ($regionalBase) {
            $types[$regionalBase] = true;
            $types[$regionalBase.'.JR'] = $isJunior;
        }

        $broken = [];
        foreach (array_keys(array_filter($types)) as $type) {
            [$isRecord, $newRecord] = $this->checkRecordType(
                $type, $strokeTypeId, $sportClass, $gender,
                $course, $distance, $relayCount, $result, $nationId, $recordStatus, $result->athlete_id
            );
            $broken[$type] = $isRecord;

            if ($newRecord) {
                $this->collect($new, $pending, $newRecord, $type, $isPending, $result->athlete?->display_name ?? '–', $reason);
            }
        }

        // Result-Flags nur für anerkannte Rekorde — ausstehende werden erst mit der Bestätigung zum Rekord.
        if (! $isPending) {
            $this->updateResultFlags($result, $broken, $regionalBase);
        }

        return ['new' => $new, 'pending' => $pending];
    }

    /**
     * Belege für die Vereinszugehörigkeit der Staffelmitglieder am Starttag: die Vereine ihrer Einzelergebnisse im
     * selben Wettkampf und ihre Vereins-History-Einträge, die das Wettkampfdatum abdecken.
     *
     * @param  Collection<int, RelayResultMember>  $members
     * @return array{results: Collection<int, Collection<int, int>>, history: Collection<int, Collection<int, int>>}
     */
    private function clubEvidence(Collection $members, Meet $meet): array
    {
        $athleteIds = $members->pluck('athlete_id')->filter()->unique()->values();
        $date = $meet->start_date->toDateString();

        $results = Result::where('meet_id', $meet->id)
            ->whereIn('athlete_id', $athleteIds)
            ->whereNotNull('club_id')
            ->get(['athlete_id', 'club_id'])
            ->groupBy('athlete_id')
            ->map(fn (Collection $rows) => $rows->pluck('club_id'));

        $history = AthleteClubHistory::whereIn('athlete_id', $athleteIds)
            ->whereDate('joined_at', '<=', $date)
            ->where(fn ($q) => $q->whereNull('left_at')->orWhereDate('left_at', '>=', $date))
            ->get(['athlete_id', 'club_id'])
            ->groupBy('athlete_id')
            ->map(fn (Collection $rows) => $rows->pluck('club_id'));

        return ['results' => $results, 'history' => $history];
    }

    /**
     * War das Mitglied am Starttag beim Staffelverein? Die erste vorhandene Quelle entscheidet:
     * 1. Einzelergebnisse im selben Wettkampf, 2. Vereins-History zum Wettkampfdatum, 3. heutiger Verein — stimmt er
     * nicht mit dem Staffelverein überein, ist die Zugehörigkeit unbelegt (null, Rekord wird ausstehend angelegt).
     *
     * @param  array{results: Collection<int, Collection<int, int>>, history: Collection<int, Collection<int, int>>}  $evidence
     */
    private function memberBelongsToClub(int $athleteId, ?int $currentClubId, int $relayClubId, array $evidence): ?bool
    {
        foreach (['results', 'history'] as $source) {
            $clubIds = $evidence[$source]->get($athleteId);
            if ($clubIds !== null && $clubIds->isNotEmpty()) {
                return $clubIds->contains($relayClubId);
            }
        }

        return $currentClubId === $relayClubId ? true : null;
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
        Result|RelayResult $result,
        ?int $nationId,
        string $recordStatus = 'APPROVED',
        ?int $athleteId = null,
    ): array {
        // Erneuter Rekord-Check derselben Veranstaltung: aus diesem Ergebnis gibt es den Rekord schon. Ein anerkannter
        // zählt weiter als gebrochen — sonst setzte updateResultFlags() z. B. das ÖR-Flag zurück, sobald beim erneuten
        // Prüfen nur ein Landesrekord neu hinzukommt (nach einer Vereinskorrektur, ResultClubChangeService).
        $sourceColumn = $result instanceof RelayResult ? 'relay_result_id' : 'result_id';
        $existing = SwimRecord::where($sourceColumn, $result->id)->where('record_type', $recordType)->first();
        if ($existing !== null) {
            return [in_array($existing->record_status, ['APPROVED', 'APPROVED.HISTORY'], true), null];
        }

        // Maßstab ist der geltende Rekord — ein noch ausstehender (PENDING) ist (noch) keiner.
        $current = SwimRecord::where('record_type', $recordType)
            ->where('stroke_type_id', $strokeTypeId)
            ->where('sport_class', $sportClass)
            ->where('gender', $gender)
            ->where('course', $course)
            ->where('distance', $distance)
            ->where('relay_count', $relayCount)
            ->where('is_current', true)
            ->where('record_status', '!=', 'PENDING')
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
                    'meet_nation_id' => $result->meet?->nation_id,
                    'athlete_id' => $athleteId,
                    'club_id' => $result->club_id,
                    'result_id' => $result instanceof Result ? $result->id : null,
                    'relay_result_id' => $result instanceof RelayResult ? $result->id : null,
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
     * Ordnet einen neu angelegten Rekord den neuen bzw. den ausstehenden Rekorden des Prüfergebnisses zu.
     *
     * @param  array<int, array{record: SwimRecord, types: string[]}>  $new
     * @param  array<int, array{record: SwimRecord, athlete_name: string, type: string, reason: string}>  $pending
     */
    private function collect(
        array &$new,
        array &$pending,
        SwimRecord $record,
        string $type,
        bool $isPending,
        string $name,
        string $reason,
    ): void {
        if ($isPending) {
            $pending[] = ['record' => $record, 'athlete_name' => $name, 'type' => $type, 'reason' => $reason];
        } else {
            $new[] = ['record' => $record, 'types' => [$type]];
        }
    }

    /**
     * Speichert die Staffelmitglieder eines Staffelergebnisses für einen neuen SwimRecord.
     *
     * @param  Collection<int, RelayResultMember>  $members
     */
    private function saveRelayMembers(SwimRecord $record, Collection $members): void
    {
        foreach ($members as $member) {
            $athlete = $member->athlete;
            RelayTeamMember::create([
                'swim_record_id' => $record->id,
                'position' => $member->position,
                'first_name' => $athlete?->first_name ?? $member->first_name ?? '',
                'last_name' => $athlete?->last_name ?? $member->last_name ?? '',
                'birth_date' => $athlete?->birth_date,
                'gender' => $member->memberGender(),
                'athlete_id' => $athlete?->id,
            ]);
        }
    }

    /**
     * Setzt die Rekord-Flags am Result aus den gebrochenen Rekordtypen (Typ => gebrochen) — gemeinsam für Einzel-
     * und Staffelprüfung.
     *
     * @param  array<string, bool>  $broken
     */
    private function updateResultFlags(Result|RelayResult $result, array $broken, ?string $regionalBase): void
    {
        $flags = [
            'is_national_record' => $broken['AUT'] ?? false,
            'is_junior_record' => $broken['AUT.JR'] ?? false,
            'is_regional_record' => $regionalBase !== null && ($broken[$regionalBase] ?? false),
            'is_regional_junior_record' => $regionalBase !== null && ($broken[$regionalBase.'.JR'] ?? false),
        ];

        if (in_array(true, $flags, true)) {
            $result->update($flags);
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
