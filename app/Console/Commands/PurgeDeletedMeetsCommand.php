<?php

namespace App\Console\Commands;

use App\Models\Meet;
use App\Services\MeetDeletionService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Löscht Veranstaltungen endgültig, die vor dem Umstieg auf endgültiges Löschen nur als gelöscht markiert wurden
 * (Soft-Delete) — samt allem, was daran hängt (MeetDeletionService). Mit --dry-run wird nur angezeigt.
 *
 *   php artisan meets:purge-deleted --dry-run
 *   php artisan meets:purge-deleted
 */
class PurgeDeletedMeetsCommand extends Command
{
    protected $signature = 'meets:purge-deleted
        {--dry-run : nur anzeigen, nichts löschen}';

    protected $description = 'Löscht als gelöscht markierte Wettkämpfe endgültig samt Ergebnissen, Meldungen und Rekorden';

    public function handle(MeetDeletionService $deletion): int
    {
        $meets = Meet::onlyTrashed()->oldest('start_date')->get();

        if ($meets->isEmpty()) {
            $this->info('Keine als gelöscht markierten Wettkämpfe vorhanden.');

            return self::SUCCESS;
        }

        $rows = $meets->map(fn (Meet $meet): array => [
            $meet->id,
            $meet->name,
            $meet->start_date?->format('d.m.Y'),
            ...array_values($deletion->summary($meet)),
        ])->all();

        $this->table(['ID', 'Wettkampf', 'Datum', 'Bewerbe', 'Meldungen', 'Staffelmeld.', 'Ergebnisse', 'Staffelerg.',
            'Gaststarts', 'Qualifikationen', 'Rekorde'], $rows);

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('Probelauf — nichts gelöscht.');

            return self::SUCCESS;
        }

        if (! $this->confirm($meets->count().' Wettkampf/Wettkämpfe endgültig löschen?')) {
            return self::FAILURE;
        }

        try {
            foreach ($meets as $meet) {
                $deletion->delete($meet);
            }
        } catch (Throwable $e) {
            $this->error('Löschen fehlgeschlagen: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info($meets->count().' Wettkampf/Wettkämpfe endgültig gelöscht.');

        return self::SUCCESS;
    }
}
