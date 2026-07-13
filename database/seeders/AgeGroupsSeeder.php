<?php

namespace Database\Seeders;

use App\Models\AgeGroup;
use Illuminate\Database\Seeder;

class AgeGroupsSeeder extends Seeder
{
    /**
     * Startbestand der Altersgruppen für die Cupwertung (Punkt 5 der Spec).
     * Administrierbar unter /age-groups — weitere Gruppen (z.B. Senioren)
     * können jederzeit ergänzt werden, ohne die Berechnungslogik zu ändern.
     */
    public function run(): void
    {
        $groups = [
            ['code' => 'JUGEND', 'name_de' => 'Jugend', 'min_age' => null, 'max_age' => 18, 'sort_order' => 10],
            ['code' => 'OFFEN', 'name_de' => 'Offen', 'min_age' => 19, 'max_age' => null, 'sort_order' => 20],
        ];

        foreach ($groups as $group) {
            AgeGroup::updateOrCreate(
                ['code' => $group['code']],
                array_merge($group, ['is_active' => true])
            );
        }

        $this->command->info('AgeGroups: '.count($groups).' Einträge angelegt/aktualisiert.');
    }
}
