<?php

namespace App\Services;

use App\Models\Result;
use App\Models\SwimRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Führt die Rekorde aus dem ÖBSV-Rekordimport (Typen AUT.IND, AUT.IND.JG, AUT.REL, AUT.REL.JG) in die nationalen
 * Ketten AUT bzw. AUT.JR zusammen (docs/specs/records.md "Zusammenführung der ÖBSV-Typen").
 *
 * Je Kategorie (Zieltyp, Schwimmart, Sportklasse, Geschlecht, Bahn, Strecke, Staffelgröße):
 * - nur Import-Rekorde: Typ umbenennen, Kette bleibt;
 * - beide Quellen: doppelte Import-Rekorde (gleicher Athlet bzw. Staffelverein, gleiche Zeit) entfallen zugunsten des
 *   Rekords aus dem Ergebnis; dann nach Datum (gleicher Tag: langsamere zuerst) durchgehen — ein Rekord bleibt nur,
 *   wenn er schneller ist als alles davor (Gleichstand zählt nicht, wie bei Rekordprüfung und Import). Bis zum Stand
 *   der ÖBSV-Liste ($cutoff, jüngstes Datum der Import-Rekorde) ist die Liste maßgeblich: Ein Ergebnis-Rekord aus dieser
 *   Zeit, der schneller ist als der beste offizielle Rekord der Kategorie, wird entfernt (von der Liste nicht
 *   anerkannt). Die Kette wird neu verknüpft (genau ein aktueller Rekord), entfernte Rekorde gelöscht und ihr
 *   Rekord-Flag am Ergebnis zurückgesetzt.
 *
 * Kategorien ohne Import-Rekord und Regionalrekorde bleiben unverändert.
 */
final readonly class NationalRecordMergeService
{
    /** Import-Typ → nationaler Zieltyp (gleich wie RecordImportService::TYPE_MAP). */
    public const array TYPE_MAP = [
        'AUT.IND' => 'AUT',
        'AUT.IND.JG' => 'AUT.JR',
        'AUT.REL' => 'AUT',
        'AUT.REL.JG' => 'AUT.JR',
    ];

    public const string ACTION_KEEP = 'behalten';

    public const string ACTION_RENAME = 'umbenannt';

    public const string ACTION_REMOVE = 'entfernt';

    /**
     * Plant die Zusammenführung und führt sie aus, wenn $dryRun false ist (in einer Transaktion).
     *
     * @return array{cutoff: ?string, rows: list<array<string, mixed>>, summary: array<string, int>}
     *
     * @throws Throwable
     */
    public function merge(bool $dryRun): array
    {
        $cutoff = SwimRecord::whereIn('record_type', array_keys(self::TYPE_MAP))->max('set_date');
        $cutoff = $cutoff !== null ? substr((string) $cutoff, 0, 10) : null;

        $records = SwimRecord::query()
            ->whereIn('record_type', [...array_keys(self::TYPE_MAP), 'AUT', 'AUT.JR'])
            ->with(['athlete:id,first_name,last_name', 'club:id,name,short_name'])
            ->get();

        $plans = $records
            ->groupBy(fn (SwimRecord $r) => $this->categoryKey($r))
            ->filter(fn (Collection $group) => $group->contains(fn (SwimRecord $r) => $this->isImported($r)))
            ->map(fn (Collection $group, string $key) => $this->planCategory($key, $group, $cutoff));

        if (! $dryRun) {
            DB::transaction(function () use ($plans) {
                foreach ($plans as $plan) {
                    $this->apply($plan);
                }
            });
        }

        $rows = $plans->flatMap(fn (array $plan) => $plan['rows'])->values()->all();

        return [
            'cutoff' => $cutoff,
            'rows' => $rows,
            'summary' => [
                'kategorien' => $plans->count(),
                'behalten' => count(array_filter($rows, fn ($r) => $r['aktion'] !== self::ACTION_REMOVE)),
                'umbenannt' => count(array_filter($rows, fn ($r) => $r['typ_alt'] !== $r['typ_neu']
                    && $r['aktion'] !== self::ACTION_REMOVE)),
                'entfernt' => count(array_filter($rows, fn ($r) => $r['aktion'] === self::ACTION_REMOVE)),
                'aktueller_wechselt' => $plans->filter(fn (array $p) => $p['current_changes'])->count(),
            ],
        ];
    }

    /**
     * @return array{keep: list<SwimRecord>, remove: list<SwimRecord>, target: string, rename_only: bool,
     *     rows: list<array>, current_changes: bool}
     */
    private function planCategory(string $key, Collection $group, ?string $cutoff): array
    {
        $target = self::TYPE_MAP[$group->first()->record_type] ?? $group->first()->record_type;
        $imported = $group->filter(fn (SwimRecord $r) => $this->isImported($r));
        $fromResults = $group->reject(fn (SwimRecord $r) => $this->isImported($r));
        $previousCurrent = $fromResults->firstWhere('is_current', true) ?? $imported->firstWhere('is_current', true);
        $bestOfficial = $imported->min('swim_time');

        $reasons = [];
        $keep = [];
        $remove = [];

        // Nur ÖBSV-Import: Typ umbenennen, die Kette aus der Datei bleibt unverändert.
        if ($fromResults->isEmpty()) {
            return [
                'keep' => $imported->values()->all(),
                'remove' => [],
                'target' => $target,
                'rename_only' => true,
                'rows' => $this->rows($key, $group, $target, [], [], null),
                'current_changes' => false,
            ];
        }

        // Doppelte: derselbe Start aus beiden Quellen — der Rekord aus dem Ergebnis bleibt.
        foreach ($imported as $record) {
            $duplicate = $fromResults->first(fn (SwimRecord $r) => $r->swim_time === $record->swim_time
                && ($record->athlete_id !== null
                    ? $r->athlete_id === $record->athlete_id
                    : $r->club_id === $record->club_id));
            if ($duplicate !== null) {
                $remove[] = $record;
                $reasons[$record->id] = 'Doppelt zu Rekord #'.$duplicate->id.' aus dem Ergebnis';
            }
        }

        $candidates = $group
            ->reject(fn (SwimRecord $r) => isset($reasons[$r->id]))
            ->sortBy(fn (SwimRecord $r) => sprintf('%s|%08d', $r->set_date?->toDateString() ?? '0000-00-00',
                99999999 - $r->swim_time))
            ->values();

        $best = null;
        foreach ($candidates as $record) {
            $date = $record->set_date?->toDateString();
            if (! $this->isImported($record) && $cutoff !== null
                && $date !== null && $date <= $cutoff && $record->swim_time < $bestOfficial) {
                $remove[] = $record;
                $reasons[$record->id] = 'Schneller als der offizielle Rekord, aber nicht in der ÖBSV-Liste (Stand '
                    .$cutoff.')';
            } elseif ($best === null || $record->swim_time < $best->swim_time) {
                $keep[] = $record;
                $reasons[$record->id] = $best === null ? 'Erster Rekord der Kategorie'
                    : 'Schneller als Rekord #'.$best->id;
                $best = $record;
            } else {
                $remove[] = $record;
                $reasons[$record->id] = 'Kein Rekord: nicht schneller als Rekord #'.$best->id;
            }
        }

        $current = $keep === [] ? null : end($keep);

        return [
            'keep' => $keep,
            'remove' => $remove,
            'target' => $target,
            'rename_only' => false,
            'rows' => $this->rows($key, $group, $target, $remove, $reasons, $current),
            'current_changes' => $current?->id !== $previousCurrent?->id,
        ];
    }

    /**
     * Berichtszeilen einer Kategorie. $current null = Kette bleibt (nur umbenannt), "aktuell" wie bisher.
     *
     * @param  list<SwimRecord>  $remove
     * @param  array<int, string>  $reasons
     * @return list<array<string, mixed>>
     */
    private function rows(
        string $key,
        Collection $group,
        string $target,
        array $remove,
        array $reasons,
        ?SwimRecord $current,
    ): array {
        $removedIds = array_map(fn (SwimRecord $r) => $r->id, $remove);
        $rows = [];
        foreach ($group->sortBy(fn (SwimRecord $r) => $r->set_date?->toDateString().'|'.$r->id) as $record) {
            $removed = in_array($record->id, $removedIds, true);
            $currentAfter = ! $removed && ($current !== null ? $record->id === $current->id : $record->is_current);
            $rows[] = [
                'kategorie' => $key,
                'aktion' => $removed ? self::ACTION_REMOVE
                    : ($this->isImported($record) ? self::ACTION_RENAME : self::ACTION_KEEP),
                'grund' => $reasons[$record->id] ?? 'Nur ÖBSV-Import in dieser Kategorie',
                'rekord_id' => $record->id,
                'quelle' => $this->isImported($record) ? 'ÖBSV-Import' : 'Ergebnis',
                'typ_alt' => $record->record_type,
                'typ_neu' => $removed ? '' : $target,
                'datum' => $record->set_date?->toDateString(),
                'zeit' => $record->swim_time,
                'athlet' => $record->athlete?->display_name,
                'verein' => $record->club?->display_name,
                'aktuell_vorher' => $record->is_current ? 'ja' : 'nein',
                'aktuell_nachher' => $currentAfter ? 'ja' : 'nein',
            ];
        }

        return $rows;
    }

    private function apply(array $plan): void
    {
        if ($plan['rename_only']) {
            foreach ($plan['keep'] as $record) {
                $record->update(['record_type' => $plan['target']]);
            }

            return;
        }

        foreach ($plan['remove'] as $record) {
            $this->resetResultFlag($record);
            // Verweise auf den Rekord (supersedes/superseded_by) setzt die DB auf null, Splits und Staffelmitglieder
            // werden mitgelöscht.
            $record->delete();
        }

        $count = count($plan['keep']);
        foreach ($plan['keep'] as $i => $record) {
            $isCurrent = $i === $count - 1;
            $record->forceFill([
                'record_type' => $plan['target'],
                'supersedes_id' => $i > 0 ? $plan['keep'][$i - 1]->id : null,
                'superseded_by_id' => $isCurrent ? null : $plan['keep'][$i + 1]->id,
                'is_current' => $isCurrent,
                'record_status' => $isCurrent ? 'APPROVED' : 'APPROVED.HISTORY',
            ])->save();
        }
    }

    /**
     * Setzt das Rekord-Flag am Ergebnis zurück, wenn kein anderer Rekord desselben Ergebnisses es trägt. Import-Rekorde
     * haben kein Ergebnis; Ergebnis-Rekorde tragen bereits den Zieltyp (AUT/AUT.JR).
     */
    private function resetResultFlag(SwimRecord $record): void
    {
        $flag = $record->resultFlag();
        if ($record->result_id === null || $flag === null) {
            return;
        }

        $stillFlagged = SwimRecord::where('result_id', $record->result_id)
            ->whereKeyNot($record->id)
            ->get(['id', 'record_type'])
            ->contains(fn (SwimRecord $other) => $other->resultFlag() === $flag);

        if (! $stillFlagged) {
            Result::whereKey($record->result_id)->update([$flag => false]);
        }
    }

    private function isImported(SwimRecord $record): bool
    {
        return isset(self::TYPE_MAP[$record->record_type]);
    }

    private function categoryKey(SwimRecord $record): string
    {
        return implode('|', [
            self::TYPE_MAP[$record->record_type] ?? $record->record_type,
            $record->stroke_type_id, $record->sport_class, $record->gender, $record->course, $record->distance,
            $record->relay_count,
        ]);
    }
}
