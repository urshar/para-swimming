<?php

namespace App\Console\Commands;

use App\Services\TeamDataTransferService;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

/**
 * Exportiert Vereine, Athleten samt Unterlisten, Klassifizierer, Klassifizierungshistorie und Rekorde als JSON für den
 * TeamDataSeeder (Umzug Dev → Prod). Die Dateien enthalten Personen- und Gesundheitsdaten und sind von Git
 * ausgeschlossen.
 *
 *   php artisan team-data:export
 *   php artisan db:seed --class=TeamDataSeeder   (auf dem Ziel)
 */
class ExportTeamDataCommand extends Command
{
    protected $signature = 'team-data:export
        {--path= : Zielordner (Standard: database/seeders/data/team)}';

    protected $description = 'Exportiert Vereine, Athleten, Klassifizierungen und Rekorde für den TeamDataSeeder';

    public function handle(TeamDataTransferService $transfer): int
    {
        $directory = $this->option('path') ?: TeamDataTransferService::defaultDirectory();

        try {
            $counts = $transfer->export($directory);
        } catch (RuntimeException|JsonException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Tabelle', 'Zeilen'], array_map(null, array_keys($counts), array_values($counts)));
        $this->info("Export nach $directory geschrieben.");
        $this->warn('Die Dateien enthalten Personen- und Gesundheitsdaten — nur auf sicherem Weg übertragen und danach löschen.');

        return self::SUCCESS;
    }
}
