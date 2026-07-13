<?php

namespace Database\Seeders;

use App\Models\KaderType;
use Illuminate\Database\Seeder;

class KaderTypesSeeder extends Seeder
{
    /**
     * Standard-Kaderarten des ÖBSV-Nationalkaders. Administrierbar unter
     * /kader-types — diese Liste ist nur der Startbestand, kein Code-Fixum.
     */
    public function run(): void
    {
        $types = [
            ['code' => 'WELTKLASSE', 'name_de' => 'Weltklasse', 'sort_order' => 10],
            ['code' => 'INTERNATIONALE_KLASSE', 'name_de' => 'Internationale Klasse', 'sort_order' => 20],
            ['code' => 'SICHTUNGSPOOL', 'name_de' => 'Sichtungspool', 'sort_order' => 30],
            ['code' => 'NACHWUCHSPOOL', 'name_de' => 'Nachwuchspool', 'sort_order' => 40],
        ];

        foreach ($types as $type) {
            KaderType::updateOrCreate(
                ['code' => $type['code']],
                array_merge($type, ['is_active' => true])
            );
        }

        $this->command->info('KaderTypes: '.count($types).' Einträge angelegt/aktualisiert.');
    }
}
