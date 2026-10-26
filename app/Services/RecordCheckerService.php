<?php

namespace App\Services;

use App\Models\AthleteClubHistory;
use App\Models\Club;
use App\Models\Meet;
use App\Models\RecordSplit;
use App\Models\RelayResult;
use App\Models\RelayResultMember;
use App\Models\RelayResultSplit;
use App\Models\RelayTeamMember;
use App\Models\Result;
use App\Models\ResultSplit;
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
 * Chronologische Kette (RecordChainService): Maßstab ist der Rekord, der am Wettkampftag galt. Ein Rekord aus einem
 * nachträglich geprüften älteren Wettkampf kommt an seinem Datum in die Kette; spätere, gleich schnelle oder
 * langsamere Rekorde werden INVALID und im Prüfergebnis gemeldet (invalid_records).
 *
 * Zwischenzeiten (docs/specs/split-records.md): Nach allen Endzeiten prüft checkMeet() die rekordfähigen
 * Zwischenzeiten der Einzelergebnisse (recordSplits(): Standardstrecke kürzer als das Rennen, Freistil/Brust/
 * Schmetterling, Zeitnahme AUTOMATIC) als eigene Kandidaten mit Strecke = Split-Distanz, dazu die volle erste
 * Teilstrecke einer Freistilstaffel als Einzelrekord des Startschwimmers (leadOffSplit()). Neue Rekorde daraus sind
 * immer PENDING (PENDING_SPLIT), je Kategorie und Wettkampf nur aus der schnellsten Zwischenzeit, tragen
 * from_split = true und setzen kein Rekord-Flag am Ergebnis.
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

    /** Grund eines ausstehenden Rekords aus einer Zwischenzeit (auch bei einem Start außer Konkurrenz). */
    public const string PENDING_SPLIT = 'Zwischenzeit – Bestätigung durch den Verband nötig';

    /** Rekordfähige Zwischenzeit-Strecken je Stil (LENEX-Code); Rücken und Lagen nie (Entscheidung Erik, 25.10.2026). */
    private const array SPLIT_RECORD_DISTANCES = [
        'FREE' => [50, 100, 200, 400, 800, 1500],
        'BREAST' => [50, 100, 200],
        'FLY' => [50, 100, 200],
    ];

    public function __construct(
        private RelayClassValidator $relayValidator,
        private RecordChainService $chain,
    ) {}

    /**
     * Prüft alle gültigen Results eines Meets auf neue Rekorde.
     *
     * @return array{
     *     new_records: array<int, array{record: SwimRecord, types: string[]}>,
     *     pending_records: array<int, array{record: SwimRecord, athlete_name: string}>,
     *     invalid_records: list<SwimRecord>,
     *     checked: int,
     * }
     *
     * @throws Throwable
     */
    public function checkMeet(Meet $meet): array
    {
        $results = $this->checkableResults($meet);
        $relayResults = $this->checkableRelayResults($meet);

        $newRecords = [];
        $pendingRecords = [];
        $invalidRecords = [];
        $checked = 0;

        foreach ($results as $result) {
            $outcome = $this->checkResult($result, $meet);
            $newRecords = array_merge($newRecords, $outcome['new']);
            $pendingRecords = array_merge($pendingRecords, $outcome['pending']);
            $invalidRecords = array_merge($invalidRecords, $outcome['invalidated'] ?? []);
            $checked++;
        }

        foreach ($relayResults as $relayResult) {
            $outcome = $this->checkRelayResult($relayResult, $meet);
            $newRecords = array_merge($newRecords, $outcome['new']);
            $pendingRecords = array_merge($pendingRecords, $outcome['pending']);
            $invalidRecords = array_merge($invalidRecords, $outcome['invalidated'] ?? []);
            $checked++;
        }

        // Zwischenzeiten erst nach allen Endzeiten — Maßstab für eine 50-m-Zwischenzeit ist dann schon ein neuer
        // 50-m-Rekord aus diesem Wettkampf.
        $pendingRecords = array_merge($pendingRecords, $this->checkSplits($meet, $results, $relayResults));

        return [
            'new_records' => $newRecords,
            'pending_records' => $pendingRecords,
            'invalid_records' => $invalidRecords,
            'checked' => $checked,
        ];
    }

    /**
     * Prüft nur die Zwischenzeiten eines Meets (Einzelrennen und Staffel-Startschwimmer) — für die Nachprüfung bereits
     * geprüfter Wettkämpfe (records:check-splits). Die Endzeiten bleiben unberührt.
     *
     * @return array<int, array{record: SwimRecord, athlete_name: string, type: string, reason: string}> die neuen,
     *                                                                                                   ausstehenden Rekorde
     *
     * @throws Throwable
     */
    public function checkMeetSplits(Meet $meet): array
    {
        return $this->checkSplits($meet, $this->checkableResults($meet), $this->checkableRelayResults($meet));
    }

    // ── Einzelrekord-Prüfung ──────────────────────────────────────────────────

    /**
     * Prüft ein Staffelergebnis auf neue Rekorde.
     *
     * @return array{new: array, pending: array, invalidated?: list<SwimRecord>}
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
        $invalidated = [];
        foreach (array_keys($types) as $type) {
            [$isRecord, $newRecord, $invalid] = $this->checkRecordType(
                $type, $strokeTypeId, $resolvedClass, $gender,
                $course, $distance, $relayCount, $relayResult, null, $recordStatus
            );
            $broken[$type] = $isRecord;
            $invalidated = [...$invalidated, ...$invalid];

            if ($newRecord) {
                $this->saveRelayMembers($newRecord, $members);
                $this->collect($new, $pending, $newRecord, $type, $isPending, $relayName, $reason);
            }
        }

        // Rekord-Flags nur für anerkannte Rekorde — ausstehende werden erst mit der Bestätigung zum Rekord.
        if (! $isPending) {
            $this->updateResultFlags($relayResult, $broken, $regionalBase);
        }

        return ['new' => $new, 'pending' => $pending, 'invalidated' => $invalidated];
    }

    /**
     * Prüft ein einzelnes (Nicht-Staffel) Result auf alle relevanten Rekord-Typen.
     *
     * @return array{new: array, pending: array, invalidated?: list<SwimRecord>}
     *
     * @throws Throwable
     */
    public function checkResult(Result $result, Meet $meet): array
    {
        $new = [];
        $pending = [];

        if (! $this->isEligible($result)) {
            return ['new' => $new, 'pending' => $pending];
        }

        // Start außer Konkurrenz (EXH): alle Rekordtypen wie ein regulärer, aber nur ausstehend — der Verband bestätigt.
        $event = $result->swimEvent;
        $isPending = $result->status === 'EXH';
        $reason = self::PENDING_EXHIBITION;
        $recordStatus = $isPending ? 'PENDING' : 'APPROVED';
        [$types, $regionalBase] = $this->individualTypes($result, $meet);

        $broken = [];
        $invalidated = [];
        foreach ($types as $type) {
            [$isRecord, $newRecord, $invalid] = $this->checkRecordType(
                $type, $event->stroke_type_id, $result->sport_class, $this->gender($result),
                $meet->course, $event->distance, $event->relay_count, $result, $result->athlete?->nation?->id,
                $recordStatus, $result->athlete_id
            );
            $broken[$type] = $isRecord;
            $invalidated = [...$invalidated, ...$invalid];

            if ($newRecord) {
                $this->collect($new, $pending, $newRecord, $type, $isPending, $result->athlete?->display_name ?? '–', $reason);
            }
        }

        // Result-Flags nur für anerkannte Rekorde — ausstehende werden erst mit der Bestätigung zum Rekord.
        if (! $isPending) {
            $this->updateResultFlags($result, $broken, $regionalBase);
        }

        return ['new' => $new, 'pending' => $pending, 'invalidated' => $invalidated];
    }

    // ── Zwischenzeit-Prüfung ──────────────────────────────────────────────────

    /**
     * Rekordfähige Zwischenzeiten eines Einzelergebnisses (docs/specs/split-records.md): Ergebnis wie bei der Endzeit
     * prüfbar, Zeitnahme AUTOMATIC, Stil Freistil/Brust/Schmetterling, Distanz eine Standardstrecke kürzer als das
     * Rennen. Rücken und Lagen nie.
     *
     * @return Collection<int, ResultSplit>
     */
    public function recordSplits(Result $result, Meet $meet): Collection
    {
        $event = $result->swimEvent;

        if (! $this->isEligible($result) || $event->relay_count > 1
            || ($event->timing ?? $meet->timing) !== 'AUTOMATIC') {
            return collect();
        }

        $distances = self::SPLIT_RECORD_DISTANCES[$event->strokeType?->lenex_code ?? ''] ?? [];

        return $result->splits
            ->filter(fn (ResultSplit $split): bool => $split->distance < $event->distance
                && in_array($split->distance, $distances, true))
            ->values();
    }

    /**
     * Prüft eine Zwischenzeit eines Einzelergebnisses auf alle Rekordtypen wie die Endzeit — neue Rekorde immer
     * ausstehend (Grund PENDING_SPLIT), ohne Rekord-Flag am Ergebnis.
     *
     * @return array{new: array, pending: array}
     *
     * @throws Throwable
     */
    public function checkSplit(Result $result, ResultSplit $split, Meet $meet): array
    {
        $new = [];
        $pending = [];
        $event = $result->swimEvent;
        [$types] = $this->individualTypes($result, $meet);

        foreach ($types as $type) {
            [, $newRecord] = $this->checkRecordType(
                $type, $event->stroke_type_id, $result->sport_class, $this->gender($result),
                $meet->course, $split->distance, $event->relay_count, $result, $result->athlete?->nation?->id,
                'PENDING', $result->athlete_id, $split
            );

            if ($newRecord) {
                $this->collect($new, $pending, $newRecord, $type, true, $result->athlete?->display_name ?? '–',
                    self::PENDING_SPLIT);
            }
        }

        return ['new' => $new, 'pending' => $pending];
    }

    /**
     * Rekordfähige erste Teilstrecke einer Staffel als Einzelzeit des Startschwimmers (docs/specs/split-records.md):
     * nur Freistilstaffeln, Teilstrecke eine Freistil-Standardstrecke, Zeitnahme AUTOMATIC, Staffelverein kein Verband,
     * Startschwimmer zugeordnet und Nation AUT. Zählt nur die volle erste Teilstrecke, keine Zwischenzeit darin.
     */
    public function leadOffSplit(RelayResult $relayResult, Meet $meet): ?RelayResultSplit
    {
        $event = $relayResult->swimEvent;

        if ($event === null || ! $relayResult->swim_time || $relayResult->club_id === null
            || $event->strokeType?->lenex_code !== 'FREE'
            || ! in_array($event->distance, self::SPLIT_RECORD_DISTANCES['FREE'], true)
            || ($event->timing ?? $meet->timing) !== 'AUTOMATIC'
            || $relayResult->club?->type === Club::TYPE_VERBAND
            || $this->leadOff($relayResult)?->athlete?->nation?->code !== 'AUT') {
            return null;
        }

        return $relayResult->splits->firstWhere('distance', $event->distance);
    }

    /**
     * Prüft die erste Teilstrecke einer Freistilstaffel als Einzelrekord des Startschwimmers: Sportklasse, Geschlecht
     * und Jugend aus dem Startschwimmer, Verein und Landesverband aus der Staffel. Gehörte er am Starttag nach einem
     * Beleg zu einem anderen Verein, entsteht kein Rekord. Neue Rekorde immer ausstehend (PENDING_SPLIT).
     *
     * @return array{new: array, pending: array}
     *
     * @throws Throwable
     */
    public function checkLeadOff(RelayResult $relayResult, RelayResultSplit $split, Meet $meet): array
    {
        $new = [];
        $pending = [];
        $event = $relayResult->swimEvent;
        $member = $this->leadOff($relayResult);
        $athlete = $member?->athlete;
        if ($athlete === null) {
            return ['new' => $new, 'pending' => $pending];
        }

        $evidence = $this->clubEvidence(collect([$member]), $meet);
        if ($this->memberBelongsToClub($athlete->id, $athlete->club_id, $relayResult->club_id, $evidence) === false) {
            return ['new' => $new, 'pending' => $pending];
        }

        $sportClass = $this->relayValidator->extractMemberClasses(collect([$member]), $event)[0] ?? null;
        if ($sportClass === null) {
            return ['new' => $new, 'pending' => $pending];
        }

        $birthDate = $athlete->birth_date;
        $isJunior = $birthDate !== null && (int) $meet->start_date->format('Y') - (int) $birthDate->format('Y') <= 18;
        $regionalBase = $relayResult->club?->regional_record_type;
        $types = ['AUT' => true, 'AUT.JR' => $isJunior];
        if ($regionalBase) {
            $types[$regionalBase] = true;
            $types[$regionalBase.'.JR'] = $isJunior;
        }

        foreach (array_keys(array_filter($types)) as $type) {
            [, $newRecord] = $this->checkRecordType(
                $type, $event->stroke_type_id, $sportClass, $athlete->gender === 'F' ? 'F' : 'M',
                $meet->course, $split->distance, 1, $relayResult, $athlete->nation?->id,
                'PENDING', $athlete->id, $split
            );

            if ($newRecord) {
                $this->collect($new, $pending, $newRecord, $type, true, $athlete->display_name, self::PENDING_SPLIT);
            }
        }

        return ['new' => $new, 'pending' => $pending];
    }

    /**
     * Schnellste Zwischenzeit zuerst: je Kategorie entsteht nur aus der schnellsten Zwischenzeit des Wettkampfs ein
     * Rekord (fasterSplitRecordInMeet()). Dazu die erste Teilstrecke der Freistilstaffeln als Einzelzeit des
     * Startschwimmers (leadOffSplit()).
     *
     * @param  Collection<int, Result>  $results
     * @param  Collection<int, RelayResult>  $relayResults
     * @return array<int, array{record: SwimRecord, athlete_name: string, type: string, reason: string}>
     *
     * @throws Throwable
     */
    private function checkSplits(Meet $meet, Collection $results, Collection $relayResults): array
    {
        $splits = $results
            ->flatMap(fn (Result $result) => $this->recordSplits($result, $meet)
                ->map(fn (ResultSplit $split): array => [$result, $split]))
            ->merge($relayResults
                ->map(fn (RelayResult $relayResult): array => [$relayResult, $this->leadOffSplit($relayResult, $meet)])
                ->filter(fn (array $candidate): bool => $candidate[1] !== null))
            ->sortBy(fn (array $candidate): string => sprintf('%010d-%d-%010d', $candidate[1]->split_time,
                $candidate[0] instanceof RelayResult ? 1 : 0, $candidate[1]->id));

        $pending = [];
        foreach ($splits as [$result, $split]) {
            $outcome = $result instanceof RelayResult
                ? $this->checkLeadOff($result, $split, $meet)
                : $this->checkSplit($result, $split, $meet);
            $pending = array_merge($pending, $outcome['pending']);
        }

        return $pending;
    }

    /**
     * Prüfbare Einzelergebnisse eines Meets: reguläre und Starts außer Konkurrenz (EXH, werden als ausstehend
     * angelegt), schnellste zuerst.
     *
     * @return Collection<int, Result>
     */
    private function checkableResults(Meet $meet): Collection
    {
        return $meet->results()
            ->with([
                'athlete.nation',
                'athlete.club',
                'club',
                'swimEvent.strokeType',
                'splits',
            ])
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'EXH'))
            ->whereNotNull('swim_time')
            ->orderBy('swim_time')
            ->orderBy('id')
            ->get();
    }

    /**
     * Prüfbare Staffelergebnisse eines Meets, schnellste zuerst.
     *
     * @return Collection<int, RelayResult>
     */
    private function checkableRelayResults(Meet $meet): Collection
    {
        return $meet->relayResults()
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
    }

    /** Startschwimmer (Position 1) einer Staffel. */
    private function leadOff(RelayResult $relayResult): ?RelayResultMember
    {
        return $relayResult->members->firstWhere('position', 1);
    }

    /**
     * Ein Einzelergebnis kann einen Rekord aufstellen: Bewerb, Zeit und Sportklasse vorhanden, Nation AUT (eine
     * unbekannte oder andere Nationalität ist kein Rekord).
     */
    private function isEligible(Result $result): bool
    {
        return $result->swimEvent !== null
            && $result->swim_time
            && $result->sport_class
            && $result->athlete?->nation?->code === 'AUT';
    }

    /**
     * Zu prüfende Rekordtypen eines Einzelergebnisses und sein Landesverband-Typ.
     *
     * Landesverband aus dem Verein im Ergebnis (Verein zum Zeitpunkt des Starts), nicht aus dem aktuellen Verein des
     * Athleten — sonst landet nach einem Vereinswechsel ein Rekord im falschen Bundesland. Nur ohne Verein im Ergebnis
     * der Verein des Athleten. Ein Verein ohne Landesverband (z. B. ÖBSV als Nationalteam): kein Regionalrekord.
     *
     * @return array{0: list<string>, 1: string|null}
     */
    private function individualTypes(Result $result, Meet $meet): array
    {
        $isJunior = $this->isJunior($result, $meet);
        $recordClub = $result->club_id !== null ? $result->club : $result->athlete?->club;
        $regionalBase = $recordClub?->regional_record_type;

        $types = ['AUT' => true, 'AUT.JR' => $isJunior];
        if ($regionalBase) {
            $types[$regionalBase] = true;
            $types[$regionalBase.'.JR'] = $isJunior;
        }

        return [array_keys(array_filter($types)), $regionalBase];
    }

    private function gender(Result $result): string
    {
        return $result->athlete?->gender === 'F' ? 'F' : 'M';
    }

    /**
     * Gibt es in der Kategorie schon einen gleich schnellen oder schnelleren Zwischenzeit-Rekord aus demselben
     * Wettkampf (ausstehend oder bestätigt)?
     *
     * @param  array<string, int|string>  $category
     */
    private function fasterSplitRecordInMeet(array $category, Result|RelayResult $result, int $swimTime): bool
    {
        $meetResults = Result::query()->select('id')->where('meet_id', $result->meet_id);
        $meetRelayResults = RelayResult::query()->select('id')->where('meet_id', $result->meet_id);

        return SwimRecord::query()
            ->where($category)
            ->where('from_split', true)
            ->whereIn('record_status', ['PENDING', 'APPROVED', 'APPROVED.HISTORY'])
            ->where('swim_time', '<=', $swimTime)
            ->where(fn ($q) => $q->whereIn('result_id', $meetResults)->orWhereIn('relay_result_id', $meetRelayResults))
            ->exists();
    }

    /**
     * Lesbare Herkunft eines Rekords aus einer Zwischenzeit, z. B. "Zwischenzeit aus 100 m Freistil" bzw.
     * "Startschwimmer 4×50 m Freistil".
     */
    private function splitComment(Result|RelayResult $result): string
    {
        $event = $result->swimEvent;

        return $result instanceof RelayResult
            ? 'Startschwimmer '.$event->relay_count.'×'.$event->distance.' m '.$event->strokeType?->name_de
            : 'Zwischenzeit aus '.$event->distance.' m '.$event->strokeType?->name_de;
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
     * @return array{0: bool, 1: SwimRecord|null, 2: list<SwimRecord>} gebrochen, neuer Rekord, dabei ungültig
     *                                                                 gewordene spätere Rekorde
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
        ResultSplit|RelayResultSplit|null $split = null,
    ): array {
        // Erneuter Rekord-Check derselben Veranstaltung: aus diesem Ergebnis gibt es den Rekord schon. Ein anerkannter
        // zählt weiter als gebrochen — sonst setzte updateResultFlags() z. B. das ÖR-Flag zurück, sobald beim erneuten
        // Prüfen nur ein Landesrekord neu hinzukommt (nach einer Vereinskorrektur, ResultClubChangeService). Strecke
        // und Staffelgröße gehören dazu: Ein 100-m-Ergebnis kann zusätzlich einen 50-m-Rekord aus seiner Zwischenzeit
        // haben.
        $sourceColumn = $result instanceof RelayResult ? 'relay_result_id' : 'result_id';
        $existing = SwimRecord::where($sourceColumn, $result->id)
            ->where('record_type', $recordType)
            ->where('distance', $distance)
            ->where('relay_count', $relayCount)
            ->first();
        if ($existing !== null) {
            return [in_array($existing->record_status, ['APPROVED', 'APPROVED.HISTORY'], true), null, []];
        }

        // Maßstab ist der Rekord, der am Wettkampftag galt (RecordChainService) — ein noch ausstehender (PENDING) ist
        // (noch) keiner. So kommt ein Rekord aus einem nachträglich geprüften älteren Wettkampf an seine Stelle in der
        // Kette, statt einen jüngeren Rekord abzulösen.
        $category = [
            'record_type' => $recordType,
            'stroke_type_id' => $strokeTypeId,
            'sport_class' => $sportClass,
            'gender' => $gender,
            'course' => $course,
            'distance' => $distance,
            'relay_count' => $relayCount,
        ];
        $setDate = $result->meet?->start_date;
        $swimTime = $split !== null ? $split->split_time : $result->swim_time;
        $valid = $this->chain->validOn(new SwimRecord($category), $setDate);

        if ($valid !== null && $swimTime >= $valid->swim_time) {
            return [false, null, []];
        }

        // Zwischenzeit: je Kategorie und Wettkampf nur die schnellste (Entscheidung Erik, 25.10.2026). Die Splits
        // laufen schnellste zuerst; ein schon ausstehender Zwischenzeit-Rekord desselben Wettkampfs, auch aus einem
        // früheren Rekord-Check, ist gleich schnell oder schneller.
        if ($split !== null && $this->fasterSplitRecordInMeet($category, $result, $swimTime)) {
            return [false, null, []];
        }

        $createdRecord = null;
        $invalidated = [];

        DB::transaction(function () use (
            $category,
            $result,
            $nationId,
            $valid,
            $recordStatus,
            $athleteId,
            $setDate,
            $swimTime,
            $split,
            &$createdRecord,
            &$invalidated
        ) {
            $createdRecord = SwimRecord::create([
                ...$category,
                'from_split' => $split !== null,
                'comment' => $split !== null ? $this->splitComment($result) : null,
                'nation_id' => $nationId,
                'meet_nation_id' => $result->meet?->nation_id,
                'athlete_id' => $athleteId,
                'club_id' => $result->club_id,
                'result_id' => $result instanceof Result ? $result->id : null,
                'relay_result_id' => $result instanceof RelayResult ? $result->id : null,
                'supersedes_id' => $valid?->id,
                'swim_time' => $swimTime,
                'record_status' => $recordStatus,
                'is_current' => true,
                'set_date' => $setDate,
                'meet_name' => $result->meet?->name,
                'meet_city' => $result->meet?->city,
                'meet_course' => $result->meet?->course,
            ]);

            // Aus einer Zwischenzeit: nur die Zwischenzeiten davor (beim 800-m-Rekord aus 1500 m die bis 800 m).
            $recordSplits = $split !== null
                ? $result->splits->filter(fn (ResultSplit|RelayResultSplit $s): bool => $s->distance < $split->distance)
                : $result->splits;

            foreach ($recordSplits as $recordSplit) {
                RecordSplit::create([
                    'swim_record_id' => $createdRecord->id,
                    'distance' => $recordSplit->distance,
                    'split_time' => $recordSplit->split_time,
                ]);
            }

            // Anerkannt: an seinem Datum in die Kette einfügen. Ausstehend: wartet auf die Bestätigung, erst
            // SwimRecord::approve() fügt ihn ein.
            if ($recordStatus === 'APPROVED') {
                $invalidated = $this->chain->insert($createdRecord);
            }
        });

        return [true, $createdRecord, $invalidated];
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
