<?php

namespace Database\Seeders;

use App\Services\DatabaseSnapshotService;
use Illuminate\Database\Seeder;
use Throwable;

/**
 * Spielt den Export von "php artisan db:export-seed" (kompletter Datenbestand inkl. Stammdaten und Benutzer) in eine
 * frisch migrierte, leere Datenbank ein. Ersetzt dort den DatabaseSeeder — nicht beide ausführen:
 *
 *   php artisan migrate
 *   php artisan db:seed --class=ProductionDataSeeder
 *
 * Benutzer mit Zwei-Faktor-Anmeldung brauchen auf dem Ziel denselben APP_KEY wie auf der Quelle, sonst lässt sich
 * ihr verschlüsseltes 2FA-Geheimnis nicht mehr lesen.
 */
class ProductionDataSeeder extends Seeder
{
    /** @throws Throwable */
    public function run(DatabaseSnapshotService $snapshot): void
    {
        $directory = DatabaseSnapshotService::defaultDirectory();

        if (! $snapshot->hasExport($directory)) {
            $this->command->warn("Kein Export in $directory gefunden — nichts eingespielt.");

            return;
        }

        $counts = $snapshot->import($directory);

        $this->command->table(['Tabelle', 'Zeilen'], array_map(null, array_keys($counts), array_values($counts)));
        $this->command->info(count($counts).' Tabellen, '.array_sum($counts).' Zeilen eingespielt. Die Export-Dateien bitte vom Server löschen.');
    }
}
