<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\SwimRecord;
use App\Support\ReportConfiguration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use stdClass;

/**
 * RecordStatisticsService
 *
 * Rekordauswertung für den Statistik- und Jahresbericht (Spec Phase 9).
 *
 * Der Service liest ausschließlich die bestehende Rekordstruktur (swim_records)
 * und legt KEINE eigene Rekordlogik an: Das Anlegen, Prüfen und Historisieren
 * von Rekorden bleibt vollständig bei RecordCheckerService und
 * RecordImportService. Hier wird nur gezählt.
 *
 * Maßgeblich ist das Datum, an dem der Rekord aufgestellt wurde (set_date) —
 * NICHT das Flag is_current. Ein Rekord, der im Berichtsjahr geschwommen und
 * später überboten wurde, war in diesem Jahr ein neuer Rekord und zählt
 * deshalb weiterhin mit.
 */
final readonly class RecordStatisticsService
{
    /**
     * Status, die keinen tatsächlich aufgestellten Rekord darstellen und
     * deshalb nicht gezählt werden:
     *   INVALID    = Ratifizierung fehlgeschlagen
     *   TARGETTIME = Zielzeit, laut Datenmodell ausdrücklich "noch kein Rekord"
     *
     * Alles andere zählt, insbesondere auch APPROVED.HISTORY und
     * PENDING.HISTORY (inzwischen überboten) sowie PENDING (noch nicht
     * ratifiziert).
     *
     * @var list<string>
     */
    private const array NON_RECORD_STATUSES = ['INVALID', 'TARGETTIME'];

    /** Nationaler österreichischer Rekord (Konvention aus RecordCheckerService). */
    private const string TYPE_AUSTRIAN = 'AUT';

    /** Österreichischer Jugendrekord (Konvention aus RecordCheckerService). */
    private const string TYPE_AUSTRIAN_JUNIOR = 'AUT.JR';

    /**
     * Kennzahlen zu den im Berichtszeitraum aufgestellten Rekorden.
     *
     * 'without_athlete' weist Rekorde ohne zugeordneten Athleten aus. Bei
     * Staffelrekorden ist das normal (die Mannschaft steht in relayTeam);
     * bei Einzelrekorden ist es eine Datenlücke, die so sichtbar bleibt.
     *
     * @return array{total: int, austrian: int, austrian_junior: int, relay: int, without_athlete: int}
     */
    public function overview(ReportConfiguration $config): array
    {
        $base = $this->recordsQuery($config);

        return [
            'total' => (clone $base)->count(),
            'austrian' => (clone $base)->where('record_type', self::TYPE_AUSTRIAN)->count(),
            'austrian_junior' => (clone $base)->where('record_type', self::TYPE_AUSTRIAN_JUNIOR)->count(),
            'relay' => (clone $base)->where('relay_count', '>', 1)->count(),
            'without_athlete' => (clone $base)->whereNull('athlete_id')->count(),
        ];
    }

    /**
     * Rekorde je Rekordart (z.B. AUT, AUT.JR, AUT.WBSV, WR), absteigend
     * gereiht. Die Arten werden nicht fest vorgegeben, sondern aus den Daten
     * gelesen — neue Rekordarten erscheinen dadurch automatisch.
     *
     * @return Collection<int, array{record_type: string, records: int}>
     */
    public function byRecordType(ReportConfiguration $config): Collection
    {
        return $this->recordsQuery($config)
            ->toBase()
            ->selectRaw('record_type, COUNT(*) as records')
            ->groupBy('record_type')
            ->get()
            ->map(fn (stdClass $row): array => [
                'record_type' => (string) $row->record_type,
                'records' => (int) $row->records,
            ])
            ->sort(fn (array $a, array $b): int => $b['records'] <=> $a['records']
                ?: strcmp($a['record_type'], $b['record_type']))
            ->values();
    }

    /**
     * Rekorde pro Athlet im Berichtszeitraum, absteigend gereiht.
     *
     * Rekorde ohne zugeordneten Athleten (typischerweise Staffelrekorde)
     * erscheinen hier nicht; ihre Anzahl steht in overview()['without_athlete'].
     * Gereiht nach Anzahl (desc), dann Name (asc).
     *
     * @return Collection<int, array{rank: int, athlete_id: int, athlete: string, nation: ?string, records: int}>
     */
    public function byAthlete(ReportConfiguration $config): Collection
    {
        $counts = $this->recordsQuery($config)
            ->whereNotNull('athlete_id')
            ->toBase()
            ->selectRaw('athlete_id, COUNT(*) as records')
            ->groupBy('athlete_id')
            ->get()
            ->mapWithKeys(fn (stdClass $row): array => [(int) $row->athlete_id => (int) $row->records]);

        if ($counts->isEmpty()) {
            return collect();
        }

        return Athlete::query()
            ->with('nation:id,code')
            ->whereIn('id', $counts->keys())
            ->get()
            ->map(fn (Athlete $athlete): array => [
                'athlete_id' => $athlete->id,
                'athlete' => $athlete->display_name,
                'nation' => $athlete->nation?->code,
                'records' => $counts[$athlete->id],
            ])
            ->sort(fn (array $a, array $b): int => $b['records'] <=> $a['records']
                ?: strcmp($a['athlete'], $b['athlete']))
            ->values()
            ->map(fn (array $row, int $index): array => ['rank' => $index + 1] + $row);
    }

    /**
     * Grundgesamtheit: alle im Berichtszeitraum aufgestellten Rekorde.
     *
     * Abgegrenzt wird über set_date; Rekorde ohne Datum lassen sich keinem
     * Zeitraum zuordnen und bleiben unberücksichtigt. Eine Einschränkung auf
     * ausgewählte Veranstaltungen (meet_ids) findet bewusst NICHT statt: Ein
     * Rekord ist nicht zwingend mit einem importierten Ergebnis verknüpft
     * (result_id ist optional), sodass eine Meet-Filterung importierte
     * Rekorde stillschweigend verschlucken würde.
     *
     * Der Datumsvergleich läuft über whereDate() statt über einen einfachen
     * Bereichsvergleich: Eloquent schreibt date-Felder mit Uhrzeit
     * ("2024-12-31 00:00:00"). Auf SQLite (Testsuite) ist das ein reiner
     * String-Vergleich, wodurch der letzte Tag des Zeitraums sonst
     * herausfiele. whereDate() normalisiert beide Seiten auf das Datum und
     * verhält sich damit auf MySQL und SQLite gleich.
     */
    private function recordsQuery(ReportConfiguration $config): Builder
    {
        return SwimRecord::query()
            ->whereNotNull('set_date')
            ->whereDate('set_date', '>=', $config->dateFrom->toDateString())
            ->whereDate('set_date', '<=', $config->dateTo->toDateString())
            ->whereNotIn('record_status', self::NON_RECORD_STATUSES);
    }
}
