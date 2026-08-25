<?php

namespace App\Services\Public;

use App\Models\StrokeType;
use App\Models\SwimRecord;
use App\Support\PublicRecordFilter;
use App\Support\SportClassSorter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * PublicRecordService — aktuelle, genehmigte österreichische Rekorde für den öffentlichen
 * Bereich (Spec public-frontend §5.2, Phase 5). Rein lesend.
 *
 * Bewusst nur record_status = APPROVED: PENDING (Nationalität unklar), INVALID und
 * TARGETTIME sind nicht öffentlich reif — anders als der interne RecordController, der PENDING
 * zur Bearbeitung mit anzeigt.
 */
final readonly class PublicRecordService
{
    /**
     * Verbandsübliche Lagenreihenfolge (Frei/Rücken/Brust/Fly/Lagen), keyed auf
     * StrokeType.lenex_code — dieselbe Reihenfolge wie
     * QualifyingTimeListController::groupByStroke(). Eine flache, alphabetisch sortierte Liste
     * (vorheriger Stand) mischte die Lagen durcheinander und war laut Rückmeldung unübersichtlich.
     */
    private const array STROKE_ORDER = [
        'FREE' => 1, 'BACK' => 2, 'BREAST' => 3, 'FLY' => 4, 'MEDLEY' => 5, 'IMRELAY' => 6,
    ];

    /**
     * @return Collection<int, SwimRecord>
     */
    public function forFilter(PublicRecordFilter $filter): Collection
    {
        $query = $this->baseQuery($filter->recordType())
            ->with(['strokeType', 'athlete.club', 'club', 'relayTeam', 'meetNation']);

        // sport_class trägt die Lage mit ("S9", "SB9", "SM9") — der Filter kennt nur die
        // Klassifizierungsnummer (§Fund aus der Praxis: Nutzer denken in "Klasse 9", nicht in
        // "S9 vs. SB9 vs. SM9"), deshalb hier gegen alle drei Varianten dieser Nummer geprüft.
        if ($filter->sportClass !== '') {
            $query->whereIn('sport_class', self::sportClassCodes($filter->sportClass));
        }
        if ($filter->gender !== '') {
            $query->where('gender', $filter->gender);
        }
        if ($filter->course !== '') {
            $query->where('course', $filter->course);
        }

        return $query->get()
            ->sortBy(fn (SwimRecord $record): string => $this->sortKey($record))
            ->values();
    }

    /**
     * Gruppiert ein bereits sortiertes forFilter()-Ergebnis nach Schwimmart. groupBy() erhält
     * die Eingabereihenfolge der Gruppen — die Verbandsreihenfolge aus dem Sortierschlüssel
     * bleibt damit auch für die Gruppenüberschriften erhalten, ohne hier ein zweites Mal
     * einsortiert zu werden.
     *
     * @param  Collection<int, SwimRecord>  $records
     * @return Collection<int, object{stroke: ?StrokeType, records: Collection<int, SwimRecord>}>
     */
    public function groupByStroke(Collection $records): Collection
    {
        return collect($records
            ->groupBy(fn (SwimRecord $record): int => $record->stroke_type_id)
            ->map(fn (Collection $group): object => (object) [
                'stroke' => $group->first()->strokeType,
                'records' => $group->values(),
            ])
            ->values());
    }

    /**
     * Klassifizierungsnummern, die im gewählten record_type tatsächlich vorkommen — Grundlage
     * für das Klassen-Dropdown (kein Freitextfeld, keine erratenen Werte). Liefert "9" statt
     * "S9"/"SB9"/"SM9" einzeln, siehe forFilter().
     *
     * @return list<string>
     */
    public function availableSportClasses(string $recordType): array
    {
        return $this->baseQuery($recordType)
            ->pluck('sport_class')
            ->map(static fn (string $class): string => self::classNumber($class))
            ->unique()
            ->sortBy(static fn (string $number): int => (int) $number)
            ->values()
            ->all();
    }

    /**
     * Die zuletzt aufgestellten nationalen Rekorde (record_type = 'AUT', keine Landesverbands-/
     * Jugendrekorde) — Grundlage für die Startseiten-Kachel "Neue Rekorde" (Phase 9). Bewusst
     * nicht über forFilter()/PublicRecordFilter, da die Startseite keinen Filterzustand hat und
     * immer dieselbe, nationale Ebene zeigen soll statt eines zuletzt gewählten Filters.
     *
     * @return Collection<int, SwimRecord>
     */
    public function recent(int $limit = 5): Collection
    {
        return $this->baseQuery('AUT')
            ->with(['strokeType', 'athlete', 'club', 'relayTeam.athlete'])
            ->latest('set_date')
            ->limit($limit)
            ->get();
    }

    private function baseQuery(string $recordType): Builder
    {
        return SwimRecord::query()
            ->where('is_current', true)
            ->where('record_status', 'APPROVED')
            ->where('record_type', $recordType);
    }

    /** "9" → ["S9", "SB9", "SM9"] — alle Lagen derselben Klassifizierungsnummer. */
    private static function sportClassCodes(string $classNumber): array
    {
        return ['S'.$classNumber, 'SB'.$classNumber, 'SM'.$classNumber];
    }

    /** "SB9" → "9". Unbekanntes Format (sollte laut Domain-Glossar nicht vorkommen) bleibt unverändert. */
    private static function classNumber(string $sportClass): string
    {
        return preg_match('/^(?:S|SB|SM)(\d+)$/', $sportClass, $matches) === 1 ? $matches[1] : $sportClass;
    }

    /**
     * Zusammengesetzter Sortierschlüssel statt sortBy() mit Closure-Array (CLAUDE.md):
     * Schwimmart zuerst (Verbandsreihenfolge, siehe STROKE_ORDER — Rückmeldung: alphabetisch
     * nach Klasse sortiert war unübersichtlich), dann Distanz, dann Sportklasse (SportClassSorter,
     * damit S9 vor S10 einsortiert), dann Geschlecht.
     */
    private function sortKey(SwimRecord $record): string
    {
        return sprintf(
            '%02d|%010d|%s|%s',
            self::STROKE_ORDER[$record->strokeType?->lenex_code] ?? 99,
            $record->distance,
            SportClassSorter::key($record->sport_class),
            $record->gender
        );
    }
}
