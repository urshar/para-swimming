<?php

namespace App\Console\Commands;

use App\Services\DatabaseSnapshotService;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

/**
 * Exportiert den kompletten Datenbestand für den ProductionDataSeeder (Go-live Dev → Prod). Die Dateien enthalten
 * Personen- und Gesundheitsdaten sowie Passwort-Hashes und sind von Git ausgeschlossen.
 *
 *   php artisan db:export-seed
 *   php artisan db:seed --class=ProductionDataSeeder   (auf dem Ziel, nach "php artisan migrate")
 */
class ExportDatabaseSnapshotCommand extends Command
{
    protected $signature = 'db:export-seed
        {--path= : Zielordner (Standard: database/seeders/data/snapshot)}';

    protected $description = 'Exportiert alle Daten der Datenbank für den ProductionDataSeeder';

    public function handle(DatabaseSnapshotService $snapshot): int
    {
        $directory = $this->option('path') ?: DatabaseSnapshotService::defaultDirectory();

        try {
            $counts = $snapshot->export($directory);
        } catch (RuntimeException|JsonException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Tabelle', 'Zeilen'], array_map(null, array_keys($counts), array_values($counts)));
        $this->info(count($counts).' Tabellen, '.array_sum($counts)." Zeilen nach $directory geschrieben.");
        $this->warn('Die Dateien enthalten Personen- und Gesundheitsdaten sowie Passwort-Hashes — nur auf sicherem Weg übertragen und danach löschen.');

        return self::SUCCESS;
    }
}
