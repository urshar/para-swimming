<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\Club;
use App\Models\ImportReviewItem;
use App\Models\RelayTeamMember;
use App\Models\Result;
use App\Models\SwimRecord;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Prüfliste nach dem LENEX-Rekordimport (docs/specs/records.md "Prüfliste").
 *
 * Vereinskonflikt: Der Verein laut Rekord weicht vom Stammverein (Athlete::club_id) ab. Maßgeblich ist je Athlet der
 * jüngste Einzelrekord; nur ohne Einzelrekord der jüngste Staffelrekord, und Staffeln zählen nur bei nationalen und
 * regionalen Rekorden ("AUT*"), weil internationale Staffeln als Nationalteam schwimmen. Rekorde für einen Verband
 * (Club::TYPE_VERBAND, z. B. ÖBSV als Nationalteam) zählen gar nicht.
 *
 * Ein Konflikt ist "relevant", wenn der Rekord nicht älter ist als der Eintritt beim aktuellen Verein und es kein
 * Wettkampfergebnis beim aktuellen Verein gibt, das jünger ist als der Rekord — in beiden Fällen ist der alte Rekord
 * beim früheren Verein kein Hinweis auf einen fehlenden Vereinswechsel (die Vereins-History ist meist leer, die
 * Ergebnisse tragen dagegen immer einen Verein). Nur relevante Konflikte werden vorbelegt und in die Liste geschrieben.
 *
 * Athleten mit bekannter, anderer Nationalität als AUT bekommen keinen Vereinskonflikt; ihre AUT-/Regionalrekorde
 * meldet stattdessen logNationalityIssues() als eigenen Befund (Aktion: Rekord entfernen).
 *
 * Eine Beobachtung ist ein Array {athlete_id, club_id, date (Y-m-d|null), relay, label, swim_record_id}.
 */
final readonly class RecordImportReviewService
{
    public const string SOURCE_SCAN = 'Bestandsprüfung';

    public function __construct(
        private AthleteClubTransferService $transfers = new AthleteClubTransferService,
    ) {}

    /**
     * Beobachtung aus einem Rekord, oder null, wenn er nicht zählt (Staffel außerhalb von AUT*).
     *
     * @return array{athlete_id: int, club_id: int, date: ?string, relay: bool, label: string, swim_record_id: ?int}|null
     */
    public function observation(
        int $athleteId,
        int $clubId,
        ?string $date,
        bool $relay,
        string $recordType,
        string $label,
        ?int $swimRecordId,
    ): ?array {
        if ($relay && ! str_starts_with($recordType, 'AUT')) {
            return null;
        }

        return [
            'athlete_id' => $athleteId,
            'club_id' => $clubId,
            'date' => $date !== null && $date !== '' ? Carbon::parse($date)->toDateString() : null,
            'relay' => $relay,
            'label' => $label,
            'swim_record_id' => $swimRecordId,
        ];
    }

    /** "4×50m Freistil S14 SCM (AUT)" — für Vorschau und Liste. */
    public function recordLabel(
        int $distance,
        int $relayCount,
        ?string $strokeName,
        string $sportClass,
        string $course,
        string $recordType,
    ): string {
        $distanceLabel = $relayCount > 1 ? $relayCount.'×'.$distance.'m' : $distance.'m';

        return preg_replace('/\s+/', ' ',
            $distanceLabel.' '.$strokeName.' '.$sportClass.' '.$course.' ('.$recordType.')');
    }

    /**
     * Vereinskonflikte aus den Beobachtungen, je Athlet höchstens einer.
     *
     * @param  list<array>  $observations
     * @return array<int, array{athlete_id: int, athlete_name: string, current_club_id: ?int, current_club_name: ?string,
     *     lenex_club_id: int, lenex_club_name: ?string, date: ?string, relay: bool, label: string, swim_record_id: ?int,
     *     relevant: bool}>
     */
    public function conflicts(array $observations): array
    {
        $clubs = Club::findMany(array_unique(array_column($observations, 'club_id')))->keyBy('id');

        $decisive = [];
        foreach ($observations as $obs) {
            $club = $clubs->get($obs['club_id']);
            if ($club === null || $club->type === Club::TYPE_VERBAND) {
                continue;
            }

            $current = $decisive[$obs['athlete_id']] ?? null;
            if ($current === null || $this->sortKey($obs) > $this->sortKey($current)) {
                $decisive[$obs['athlete_id']] = $obs;
            }
        }

        if ($decisive === []) {
            return [];
        }

        $athletes = Athlete::with(['club', 'activeClubHistory', 'nation'])->findMany(array_keys($decisive))->keyBy('id');
        $latestResults = $this->latestResultDatesAtCurrentClub($athletes->all());

        $conflicts = [];
        foreach ($decisive as $athleteId => $obs) {
            $athlete = $athletes->get($athleteId);
            // Nationale/regionale Rekorde gibt es nur für AUT-Athleten; andere Nationen sind ein eigener Befund
            // ("Nationalität nicht AUT"), kein Vereinskonflikt. Unbekannte Nationalität bleibt drin.
            if ($athlete === null || $athlete->club_id === $obs['club_id']
                || ($athlete->nation !== null && $athlete->nation->code !== 'AUT')) {
                continue;
            }

            $joinedAt = $athlete->activeClubHistory->first()?->joined_at?->toDateString();
            $latestResult = $latestResults[$athleteId] ?? null;

            $conflicts[$athleteId] = [
                'athlete_id' => $athleteId,
                'athlete_name' => $athlete->display_name,
                'current_club_id' => $athlete->club_id,
                'current_club_name' => $athlete->club?->display_name,
                'lenex_club_id' => $obs['club_id'],
                'lenex_club_name' => $clubs->get($obs['club_id'])->display_name,
                'date' => $obs['date'],
                'relay' => $obs['relay'],
                'label' => $obs['label'],
                'swim_record_id' => $obs['swim_record_id'],
                'relevant' => $athlete->club_id === null || $obs['date'] === null
                    || (($joinedAt === null || $obs['date'] >= $joinedAt)
                        && ($latestResult === null || $latestResult <= $obs['date'])),
            ];
        }

        uasort($conflicts, fn (array $a, array $b) => strcmp($a['athlete_name'], $b['athlete_name']));

        return $conflicts;
    }

    /**
     * Schreibt einen Vereinskonflikt in die Liste. Dasselbe Vereinspaar je Athlet wird nur einmal geführt (auch ein
     * ignorierter Eintrag kommt beim nächsten Import nicht wieder); ein offener Eintrag wird beim Übernehmen
     * aktualisiert.
     */
    public function logConflict(array $conflict, string $source, string $status, ?int $userId): ?ImportReviewItem
    {
        $existing = ImportReviewItem::where('type', ImportReviewItem::TYPE_CLUB_CONFLICT)
            ->where('athlete_id', $conflict['athlete_id'])
            ->where('current_club_id', $conflict['current_club_id'])
            ->where('lenex_club_id', $conflict['lenex_club_id'])
            ->first();

        if ($existing !== null) {
            if ($existing->isOpen() && $status !== ImportReviewItem::STATUS_OPEN) {
                $existing->update($this->resolution($status, $userId));
            }

            return null;
        }

        return ImportReviewItem::create([
            'type' => ImportReviewItem::TYPE_CLUB_CONFLICT,
            'athlete_id' => $conflict['athlete_id'],
            'current_club_id' => $conflict['current_club_id'],
            'lenex_club_id' => $conflict['lenex_club_id'],
            'swim_record_id' => $conflict['swim_record_id'],
            'source' => $source,
            'details' => [
                'date' => $conflict['date'],
                'relay' => $conflict['relay'],
                'label' => $conflict['label'],
            ],
            'status' => $status,
            ...($status === ImportReviewItem::STATUS_OPEN ? [] : $this->resolution($status, $userId)),
        ]);
    }

    /**
     * In der Import-Vorschau angehakter Konflikt: Vereinswechsel zum Rekorddatum (sonst heute) und als übernommen in
     * die Liste.
     *
     * @throws Throwable
     */
    public function applyConflict(array $conflict, string $source, ?int $userId): void
    {
        $this->transfers->transfer(
            Athlete::findOrFail($conflict['athlete_id']),
            $conflict['lenex_club_id'],
            $conflict['date'] ?? now()->toDateString(),
            'Beim Rekordimport übernommen'.($source !== '' ? ' ('.$source.')' : ''),
        );

        $this->logConflict($conflict, $source, ImportReviewItem::STATUS_APPLIED, $userId);
    }

    /**
     * Unbekannter Athlet aus der Datei wurde einer bestehenden Person mit abweichendem Geburtsdatum zugeordnet
     * (z. B. Jahres-Fallback bei "JJJJ-01-01"). Je Athlet nur einmal.
     */
    public function logYearMatch(Athlete $athlete, array $fileAthlete, string $source): void
    {
        $fileBirthDate = ($fileAthlete['birth_date'] ?? '') !== '' ? $fileAthlete['birth_date'] : null;
        $dbBirthDate = $athlete->birth_date?->toDateString();

        if ($fileBirthDate === $dbBirthDate) {
            return;
        }

        ImportReviewItem::firstOrCreate(
            ['type' => ImportReviewItem::TYPE_YEAR_MATCH, 'athlete_id' => $athlete->id],
            [
                'current_club_id' => $athlete->club_id,
                'source' => $source,
                'details' => [
                    'file_name' => trim(($fileAthlete['last_name'] ?? '').', '.($fileAthlete['first_name'] ?? ''), ', '),
                    'file_birth_date' => $fileBirthDate,
                    'db_birth_date' => $dbBirthDate,
                ],
                'status' => ImportReviewItem::STATUS_OPEN,
            ],
        );
    }

    /**
     * Vereinskonflikt: Verein übernehmen (Vereinswechsel zum Rekorddatum, sonst heute); Jahres-Treffer: geprüft.
     *
     * @throws Throwable
     */
    public function apply(ImportReviewItem $item, int $userId): bool
    {
        if ($item->type === ImportReviewItem::TYPE_CLUB_CONFLICT) {
            if ($item->lenex_club_id === null || $item->athlete === null) {
                return false;
            }

            if ($item->athlete->club_id !== $item->lenex_club_id) {
                $this->transfers->transfer(
                    $item->athlete,
                    $item->lenex_club_id,
                    $item->details['date'] ?? now()->toDateString(),
                    'Aus der Rekordimport-Prüfliste übernommen',
                );
            }
        }

        // Nationalität: Rekord entfernen (Historie neu verknüpfen). Ist er schon weg, nur als erledigt markieren.
        if ($item->type === ImportReviewItem::TYPE_NATIONALITY) {
            $item->swimRecord?->removeFromHistory();
        }

        $item->update($this->resolution(ImportReviewItem::STATUS_APPLIED, $userId));

        return true;
    }

    public function ignore(ImportReviewItem $item, int $userId): void
    {
        $item->update($this->resolution(ImportReviewItem::STATUS_IGNORED, $userId));
    }

    /**
     * Prüft alle vorhandenen Rekorde: Nationalität (logNationalityIssues) und Vereinskonflikte (Einzel und
     * Staffelmitglieder); neue Befunde kommen offen in die Liste.
     *
     * @return int Anzahl neu aufgenommener Einträge
     */
    public function scanExisting(): int
    {
        $observations = [];

        SwimRecord::query()
            ->where('relay_count', 1)
            ->whereNotNull('athlete_id')
            ->whereNotNull('club_id')
            ->with('strokeType:id,name_de')
            ->get(['id', 'athlete_id', 'club_id', 'set_date', 'record_type', 'distance', 'relay_count',
                'stroke_type_id', 'sport_class', 'course'])
            ->each(function (SwimRecord $record) use (&$observations) {
                $observations[] = $this->observationFromRecord($record, $record->athlete_id);
            });

        RelayTeamMember::query()
            ->whereNotNull('athlete_id')
            ->whereHas('swimRecord', fn ($q) => $q->where('relay_count', '>', 1)->whereNotNull('club_id'))
            ->with(['swimRecord:id,club_id,set_date,record_type,distance,relay_count,stroke_type_id,sport_class,course',
                'swimRecord.strokeType:id,name_de'])
            ->get(['id', 'swim_record_id', 'athlete_id'])
            ->each(function (RelayTeamMember $member) use (&$observations) {
                $observations[] = $this->observationFromRecord($member->swimRecord, $member->athlete_id);
            });

        $created = $this->logNationalityIssues(null, self::SOURCE_SCAN);
        foreach ($this->conflicts(array_values(array_filter($observations))) as $conflict) {
            if ($conflict['relevant']
                && $this->logConflict($conflict, self::SOURCE_SCAN, ImportReviewItem::STATUS_OPEN, null) !== null) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * Nimmt AUT- und Regional-Einzelrekorde (aktuell und historisch) von Athleten auf, deren Nationalität bekannt und
     * nicht AUT ist. Solche Rekorde dürfte es nicht geben — typisch nach einer späteren Korrektur der Nationalität.
     * Je Rekord nur einmal (auch ein ignorierter Eintrag kommt nicht wieder).
     *
     * @param  list<int>|null  $recordIds  null = alle Rekorde (Bestandsprüfung), sonst nur diese (nach einem Import)
     * @return int Anzahl neu aufgenommener Einträge
     */
    public function logNationalityIssues(?array $recordIds, string $source): int
    {
        if ($recordIds === []) {
            return 0;
        }

        $records = SwimRecord::query()
            ->where('record_type', 'like', 'AUT%')
            ->where('relay_count', 1)
            ->whereHas('athlete.nation', fn ($q) => $q->where('code', '!=', 'AUT'))
            ->when($recordIds !== null, fn ($q) => $q->whereKey($recordIds))
            ->with(['athlete.nation', 'strokeType:id,name_de'])
            ->get();

        $created = 0;
        foreach ($records as $record) {
            $item = ImportReviewItem::firstOrCreate(
                ['type' => ImportReviewItem::TYPE_NATIONALITY, 'swim_record_id' => $record->id],
                [
                    'athlete_id' => $record->athlete_id,
                    'current_club_id' => $record->club_id,
                    'source' => $source,
                    'details' => [
                        'nation' => $record->athlete->nation->code,
                        'label' => $this->recordLabel($record->distance, $record->relay_count,
                            $record->strokeType?->name_de, $record->sport_class, $record->course, $record->record_type),
                        'date' => $record->set_date?->toDateString(),
                        'is_current' => $record->is_current,
                    ],
                    'status' => ImportReviewItem::STATUS_OPEN,
                ],
            );
            if ($item->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    private function observationFromRecord(SwimRecord $record, int $athleteId): ?array
    {
        return $this->observation(
            $athleteId,
            $record->club_id,
            $record->set_date?->toDateString(),
            $record->relay_count > 1,
            $record->record_type,
            $this->recordLabel($record->distance, $record->relay_count, $record->strokeType?->name_de,
                $record->sport_class, $record->course, $record->record_type),
            $record->id,
        );
    }

    /**
     * Datum des jüngsten Wettkampfergebnisses je Athlet beim aktuellen Stammverein.
     *
     * @param  array<int, Athlete>  $athletes
     * @return array<int, string> athlete_id → Y-m-d
     */
    private function latestResultDatesAtCurrentClub(array $athletes): array
    {
        $rows = Result::query()
            ->join('meets', 'meets.id', '=', 'results.meet_id')
            ->whereIn('results.athlete_id', array_keys($athletes))
            ->groupBy('results.athlete_id', 'results.club_id')
            ->selectRaw('results.athlete_id, results.club_id, MAX(meets.start_date) as latest')
            ->toBase()
            ->get();

        $dates = [];
        foreach ($rows as $row) {
            $athlete = $athletes[$row->athlete_id] ?? null;
            if ($athlete !== null && $athlete->club_id === (int) $row->club_id && $row->latest !== null) {
                $dates[(int) $row->athlete_id] = substr((string) $row->latest, 0, 10);
            }
        }

        return $dates;
    }

    /** Einzelrekorde vor Staffeln, dann das jüngste Datum (ohne Datum zuletzt). */
    private function sortKey(array $obs): string
    {
        return ($obs['relay'] ? '0' : '1').($obs['date'] ?? '0000-00-00');
    }

    private function resolution(string $status, ?int $userId): array
    {
        return ['status' => $status, 'resolved_at' => now(), 'resolved_by' => $userId];
    }
}
