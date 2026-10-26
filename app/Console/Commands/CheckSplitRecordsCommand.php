<?php

namespace App\Console\Commands;

use App\Models\Meet;
use App\Services\RecordCheckerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Prüft die Zwischenzeiten bereits geprüfter Wettkämpfe nachträglich auf Rekorde (docs/specs/split-records.md):
 * Einzel-Zwischenzeiten und die erste Teilstrecke von Freistilstaffeln. Geht alle Wettkämpfe nach Datum durch (älteste
 * zuerst); die Endzeiten bleiben unberührt. Neue Rekorde sind ausstehend und werden erst mit der Bestätigung an ihrem
 * Datum in die Kette eingeordnet. Ein zweiter Lauf legt nichts doppelt an. Mit --dry-run wird alles in einer
 * Transaktion ausgeführt und wieder zurückgerollt.
 *
 *   php artisan records:check-splits --dry-run
 *   php artisan records:check-splits --meet=26
 */
class CheckSplitRecordsCommand extends Command
{
    protected $signature = 'records:check-splits
        {--dry-run : nur anzeigen, nichts ändern}
        {--meet= : nur diesen Wettkampf (ID) prüfen}';

    protected $description = 'Prüft die Zwischenzeiten der Wettkämpfe nachträglich auf Rekorde';

    /** @throws Throwable */
    public function handle(RecordCheckerService $checker): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $meetId = $this->option('meet');

        $meets = Meet::query()
            ->when($meetId !== null, fn ($q) => $q->whereKey((int) $meetId))
            ->oldest('start_date')
            ->orderBy('id')
            ->get();

        if ($meets->isEmpty()) {
            $this->error($meetId !== null ? 'Wettkampf '.$meetId.' nicht gefunden.' : 'Keine Wettkämpfe vorhanden.');

            return self::FAILURE;
        }

        if (! $dryRun && ! $this->confirm('Zwischenzeiten von '.$meets->count().' Wettkampf/Wettkämpfen jetzt prüfen?')) {
            return self::FAILURE;
        }

        $rows = [];

        DB::beginTransaction();

        try {
            foreach ($meets as $meet) {
                foreach ($checker->checkMeetSplits($meet) as $item) {
                    $record = $item['record'];
                    $rows[] = [
                        $meet->id,
                        $meet->start_date?->format('d.m.Y'),
                        $item['type'],
                        $record->distance,
                        $record->strokeType?->name_de,
                        $record->sport_class,
                        $record->gender,
                        $record->course,
                        $record->formatted_swim_time,
                        $item['athlete_name'],
                        $record->comment,
                    ];
                }
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        if ($rows === []) {
            $this->info('Keine neuen Rekorde aus Zwischenzeiten ('.$meets->count().' Wettkampf/Wettkämpfe geprüft).');

            return self::SUCCESS;
        }

        $this->table(['Wk', 'Datum', 'Typ', 'Strecke', 'Lage', 'Klasse', 'G', 'Bahn', 'Zeit', 'Athlet', 'Herkunft'], $rows);
        $this->info($dryRun
            ? 'Probelauf — '.count($rows).' ausstehende(r) Rekord(e) aus Zwischenzeiten würden angelegt, nichts geändert.'
            : count($rows).' ausstehende(r) Rekord(e) aus Zwischenzeiten angelegt — bitte prüfen und bestätigen.');

        return self::SUCCESS;
    }
}
