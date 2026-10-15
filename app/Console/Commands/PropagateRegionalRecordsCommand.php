<?php

namespace App\Console\Commands;

use App\Models\SwimRecord;
use App\Services\RegionalRecordService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Trägt fehlende Landesrekorde aus den österreichischen Rekorden (AUT, AUT.JR) nach — z. B. für Staffelrekorde, die
 * ohne Verein importiert wurden und den Verein erst danach bekamen. Geht alle bestätigten nationalen Rekorde samt
 * Historie chronologisch durch (älteste zuerst), damit die Landes-Kette in der richtigen Reihenfolge entsteht;
 * vorhandene Landesrekorde mit gleicher oder besserer Zeit bleiben (RegionalRecordService::propagate()). Mit --dry-run
 * wird alles in einer Transaktion ausgeführt und wieder zurückgerollt.
 *
 *   php artisan records:propagate-regional --dry-run
 *   php artisan records:propagate-regional
 */
class PropagateRegionalRecordsCommand extends Command
{
    protected $signature = 'records:propagate-regional
        {--dry-run : nur anzeigen, nichts ändern}';

    protected $description = 'Trägt fehlende Landesrekorde aus den österreichischen Rekorden nach';

    /** @throws Throwable */
    public function handle(RegionalRecordService $regional): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $this->confirm('Fehlende Landesrekorde jetzt eintragen?')) {
            return self::FAILURE;
        }

        $rows = [];

        DB::beginTransaction();

        try {
            $records = SwimRecord::query()
                ->whereIn('record_type', ['AUT', 'AUT.JR'])
                ->whereIn('record_status', ['APPROVED', 'APPROVED.HISTORY'])
                ->with(['strokeType:id,name_de', 'athlete', 'club'])
                ->get()
                // Ohne Datum zuerst, dann nach Datum; am selben Tag die langsamere zuerst.
                ->sortBy(fn (SwimRecord $r) => sprintf('%s|%08d|%010d', $r->set_date?->toDateString() ?? '0000-00-00',
                    99999999 - $r->swim_time, $r->id));

            foreach ($records as $record) {
                foreach ($regional->propagate($record) as $type) {
                    $rows[] = [
                        $type,
                        $record->relay_count > 1 ? $record->relay_count.'×'.$record->distance : $record->distance,
                        $record->strokeType?->name_de,
                        $record->sport_class,
                        $record->gender,
                        $record->course,
                        $record->formatted_swim_time,
                        $record->set_date?->format('d.m.Y'),
                        $record->athlete?->display_name ?? $record->club?->display_name,
                    ];
                }
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        if ($rows === []) {
            $this->info('Keine fehlenden Landesrekorde.');

            return self::SUCCESS;
        }

        $this->table(['Typ', 'Strecke', 'Lage', 'Klasse', 'G', 'Bahn', 'Zeit', 'Datum', 'Inhaber'], $rows);
        $this->info($dryRun
            ? 'Probelauf — '.count($rows).' Landesrekord(e) würden eingetragen, nichts geändert.'
            : count($rows).' Landesrekord(e) eingetragen.');

        return self::SUCCESS;
    }
}
