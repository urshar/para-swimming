<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Reihenfolge ist wichtig:
     *   1. Nations        → keine FK-Abhängigkeiten
     *   2. StrokeTypes    → keine FK-Abhängigkeiten
     *   3. ExceptionCodes → keine FK-Abhängigkeiten
     *
     * Clubs, Athletes, Meets usw. werden NICHT per Seeder angelegt —
     * diese kommen über CRUD oder LENEX-Import.
     */
    public function run(): void
    {
        $this->command->info('');
        $this->command->info('Para Swimming — Datenbank wird befüllt...');
        $this->command->info('');

        $this->call([
            NationsSeeder::class,
            StrokeTypesSeeder::class,
            ExceptionCodesSeeder::class,
            ClubsSeeder::class,
        ]);

        $this->command->info('');
        $this->command->info('Fertig! Datenbank ist bereit.');
        $this->command->info('');
    }
}
