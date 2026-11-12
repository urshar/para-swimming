<?php

namespace App\Console\Commands;

use App\Models\SwimRecord;
use App\Services\RecordCheckerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Zieht das Rekorddatum bestehender Rekorde aus dem Rekord-Check auf den Wettkampftag nach (Datum des Abschnitts, siehe
 * RecordCheckerService::swimDate()). Bis 12.11.2026 speicherte der Rekord-Check immer den Veranstaltungsbeginn.
 * Betroffen sind nur Rekorde mit Ergebnis (result_id bzw. relay_result_id), deren Datum noch der Veranstaltungsbeginn
 * ist und deren Abschnitt ein anderes Datum hat. Importierte oder von Hand gepflegte Rekorde bleiben unverändert.
 *
 *   php artisan records:fix-set-dates --dry-run
 */
class FixRecordSetDatesCommand extends Command
{
    protected $signature = 'records:fix-set-dates
        {--dry-run : nur anzeigen, nichts ändern}';

    protected $description = 'Setzt das Rekorddatum von Rekorden aus dem Rekord-Check auf den Wettkampftag';

    /** @throws Throwable */
    public function handle(RecordCheckerService $checker): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $changes = SwimRecord::query()
            ->where(fn ($q) => $q->whereNotNull('result_id')->orWhereNotNull('relay_result_id'))
            ->whereNotNull('set_date')
            ->with(['strokeType', 'result.swimEvent', 'result.meet', 'relayResult.swimEvent', 'relayResult.meet'])
            ->orderBy('set_date')
            ->orderBy('id')
            ->get()
            ->map(function (SwimRecord $record) use ($checker): ?array {
                $result = $record->result ?? $record->relayResult;
                $start = $result?->meet?->start_date;
                if ($start === null || ! $record->set_date->isSameDay($start)) {
                    return null;
                }

                $swimDate = $checker->swimDate($result);

                return $swimDate === null || $swimDate->isSameDay($start)
                    ? null
                    : ['record' => $record, 'date' => $swimDate];
            })
            ->filter()
            ->values();

        if ($changes->isEmpty()) {
            $this->info('Keine Rekorde zu ändern.');

            return self::SUCCESS;
        }

        $this->table(['Rekord', 'Typ', 'Disziplin', 'Klasse', 'Zeit', 'Bisher', 'Neu', 'Wettkampf'],
            $changes->map(fn (array $change): array => [
                $change['record']->id,
                $change['record']->record_type,
                $change['record']->discipline_label,
                $change['record']->sport_class.' '.SwimRecord::genderLabel($change['record']->gender),
                $change['record']->formatted_swim_time,
                $change['record']->set_date->format('d.m.Y'),
                $change['date']->format('d.m.Y'),
                $change['record']->meet_name,
            ])->all());

        if ($dryRun) {
            $this->info('Probelauf — '.$changes->count().' Rekord(e) würden geändert. Nichts geändert.');

            return self::SUCCESS;
        }

        if (! $this->confirm($changes->count().' Rekorddatum/-daten jetzt auf den Wettkampftag setzen?')) {
            return self::FAILURE;
        }

        DB::transaction(fn () => $changes->each(
            fn (array $change) => SwimRecord::query()->whereKey($change['record']->id)->update(['set_date' => $change['date']->toDateString()])
        ));

        $this->info($changes->count().' Rekorddatum/-daten auf den Wettkampftag gesetzt.');

        return self::SUCCESS;
    }
}
