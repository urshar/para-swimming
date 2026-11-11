<?php

namespace App\Services;

use App\Models\Club;
use App\Models\Meet;
use App\Models\RelayResult;
use App\Models\Result;
use App\Models\SwimRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Rekorde einer Veranstaltung: alle Rekorde der Rekordhistorie, die aus einem Einzel- oder Staffelergebnis dieser
 * Veranstaltung stammen (auch aus Zwischenzeiten — die hängen ebenfalls am Ergebnis). Ungültige Rekorde und Zielzeiten
 * fehlen; anerkannte, überbotene und ausstehende stehen mit ihrem Status in der Liste (Entscheidung Erik, 11.11.2026).
 */
final readonly class MeetRecordsService
{
    /** Status, die in der Liste erscheinen, mit Anzeigetext. */
    public const array STATUS_LABELS = [
        'APPROVED' => 'Bestätigt',
        'APPROVED.HISTORY' => 'Überboten',
        'PENDING' => 'Ausstehend',
        'PENDING.HISTORY' => 'Ausstehend, überboten',
    ];

    /** Badge-Farbe je Status. */
    public const array STATUS_COLORS = [
        'APPROVED' => 'emerald',
        'APPROVED.HISTORY' => 'zinc',
        'PENDING' => 'amber',
        'PENDING.HISTORY' => 'zinc',
    ];

    /** Anzahl der Rekorde dieser Veranstaltung (für den Knopf auf der Detailseite). */
    public function count(Meet $meet): int
    {
        return $this->query($meet)->count();
    }

    /**
     * Anzahl der Rekorde je Veranstaltung für mehrere Veranstaltungen auf einmal (Veranstaltungsliste, eine Abfrage
     * je Ergebnisart statt einer je Zeile). Veranstaltungen ohne Rekorde fehlen im Ergebnis.
     *
     * @param  list<int>  $meetIds
     * @return array<int, int> meet_id => Anzahl
     */
    public function countsFor(array $meetIds): array
    {
        if ($meetIds === []) {
            return [];
        }

        $counts = [];
        foreach (['results' => 'result_id', 'relay_results' => 'relay_result_id'] as $table => $foreignKey) {
            $rows = SwimRecord::query()
                ->join($table, "$table.id", '=', "swim_records.$foreignKey")
                ->whereIn("$table.meet_id", $meetIds)
                ->whereIn('swim_records.record_status', array_keys(self::STATUS_LABELS))
                ->groupBy("$table.meet_id")
                ->selectRaw("$table.meet_id as meet_id, count(*) as aggregate")
                ->toBase()
                ->get();

            foreach ($rows as $row) {
                $counts[(int) $row->meet_id] = ($counts[(int) $row->meet_id] ?? 0) + (int) $row->aggregate;
            }
        }

        return $counts;
    }

    /**
     * Rekorde je Rekordart: Österreich, Österreich Jugend, dann die Landesverbände (je mit Jugend), danach alle
     * übrigen Rekordarten alphabetisch. Innerhalb einer Rekordart Einzel vor Staffel, dann Lage, Strecke,
     * Sportklasse und Wertung.
     *
     * @return Collection<int, array{type: string, label: string, records: Collection<int, SwimRecord>}>
     */
    public function grouped(Meet $meet): Collection
    {
        $records = $this->query($meet)
            ->with(['strokeType', 'athlete.nation', 'athlete.club', 'club', 'relayTeam.athlete', 'supersedes'])
            ->get();

        $typeOrder = $this->typeOrder();

        return $records
            ->groupBy('record_type')
            ->map(fn (Collection $group, string $type) => [
                'type' => $type,
                'label' => $this->typeLabel($type),
                'records' => $group->sortBy(fn (SwimRecord $record) => $this->recordSortKey($record))->values(),
            ])
            ->sortBy(fn (array $group) => sprintf('%03d|%s', $typeOrder[$group['type']] ?? 999, $group['type']))
            ->values();
    }

    /** Anzeigename einer Rekordart, z. B. "Österreich – Jugend" oder "WBSV Wien". */
    public function typeLabel(string $type): string
    {
        $isJunior = str_ends_with($type, '.JR');
        $base = $isJunior ? substr($type, 0, -3) : $type;

        $label = match (true) {
            $base === 'AUT' => 'Österreich',
            str_starts_with($base, 'AUT.') => $this->regionalLabel(substr($base, 4)),
            default => $base,
        };

        return $isJunior ? "$label – Jugend" : $label;
    }

    /** @return Builder<SwimRecord> */
    private function query(Meet $meet): Builder
    {
        return SwimRecord::query()
            ->whereIn('record_status', array_keys(self::STATUS_LABELS))
            ->where(fn (Builder $q) => $q
                ->whereIn('result_id', Result::query()->select('id')->where('meet_id', $meet->id))
                ->orWhereIn('relay_result_id', RelayResult::query()->select('id')->where('meet_id', $meet->id)));
    }

    private function regionalLabel(string $association): string
    {
        $state = Club::REGIONAL_ASSOCIATION_STATES[$association] ?? null;

        return $state !== null ? "$association $state" : $association;
    }

    /**
     * Reihenfolge der bekannten Rekordarten (AUT, AUT.JR, dann je Landesverband Basis und Jugend).
     *
     * @return array<string, int>
     */
    private function typeOrder(): array
    {
        $types = ['AUT', 'AUT.JR'];
        foreach (array_keys(Club::REGIONAL_ASSOCIATIONS) as $association) {
            $types[] = "AUT.$association";
            $types[] = "AUT.$association.JR";
        }

        return array_flip($types);
    }

    private function recordSortKey(SwimRecord $record): string
    {
        preg_match('/\d+/', $record->sport_class, $match);

        return sprintf('%d|%02d|%05d|%03d|%s|%d',
            $record->relay_count > 1 ? 1 : 0,
            PointConversionService::STROKE_ORDER[$record->strokeType?->lenex_code] ?? 99,
            $record->distance,
            (int) ($match[0] ?? 999),
            $record->sport_class,
            match ($record->gender) {
                'F' => 1,
                'M' => 2,
                default => 3,
            },
        );
    }
}
