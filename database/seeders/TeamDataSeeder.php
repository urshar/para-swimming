<?php

namespace Database\Seeders;

use App\Services\TeamDataTransferService;
use Illuminate\Database\Seeder;
use Throwable;

/**
 * Spielt den Export von "php artisan team-data:export" (Vereine, Athleten, Klassifizierer, Klassifizierungshistorie,
 * Rekorde) in eine leere Datenbank ein. Nicht Teil des DatabaseSeeders — gezielt aufrufen, nachdem die übrigen Seeder
 * gelaufen sind:
 *
 *   php artisan db:seed --class=TeamDataSeeder
 */
class TeamDataSeeder extends Seeder
{
    /** @throws Throwable */
    public function run(TeamDataTransferService $transfer): void
    {
        $directory = TeamDataTransferService::defaultDirectory();

        if (! $transfer->hasExport($directory)) {
            $this->command->warn("Kein Export in $directory gefunden — nichts eingespielt.");

            return;
        }

        $counts = $transfer->import($directory);

        $this->command->table(['Tabelle', 'Zeilen'], array_map(null, array_keys($counts), array_values($counts)));
        $this->command->info('Team-Daten eingespielt. Die Export-Dateien bitte vom Server löschen.');
    }
}
