<?php

namespace Database\Seeders;

use App\Models\SportClassGroup;
use App\Models\SportClassGroupMember;
use Illuminate\Database\Seeder;

class SportClassGroupsSeeder extends Seeder
{
    /**
     * Startbestand der Sportklassengruppen für Einzelbewerbe (Punkt 7 der
     * Spec) sowie die virtuelle Top-Gruppe (Punkt 8). Die Staffel-Gruppierung
     * (R20/R34/R49 etc.) folgt mit dem später geplanten Staffelcup und wird
     * hier bewusst noch nicht angelegt.
     */
    public function run(): void
    {
        $groups = [
            [
                'code' => 'PI', 'name_de' => 'Körperliche Behinderung (PI)', 'sort_order' => 10,
                'classes' => array_merge(
                    $this->range('S', 1, 10),
                    $this->range('SB', 1, 9),
                    $this->range('SM', 1, 10)
                ),
            ],
            [
                'code' => 'VI', 'name_de' => 'Sehbehinderung (VI)', 'sort_order' => 20,
                'classes' => array_merge(
                    $this->range('S', 11, 13),
                    $this->range('SB', 11, 13),
                    $this->range('SM', 11, 13)
                ),
            ],
            [
                'code' => 'II', 'name_de' => 'Mentale Behinderung (II)', 'sort_order' => 30,
                'classes' => ['S14', 'SB14', 'SM14'],
            ],
            [
                'code' => 'T21', 'name_de' => 'Trisomie 21 (T21)', 'sort_order' => 40,
                'classes' => ['S21', 'SB21', 'SM21'],
            ],
            [
                'code' => 'HI', 'name_de' => 'Hörbehinderung (HI)', 'sort_order' => 50,
                'classes' => ['S15'],
            ],
        ];

        foreach ($groups as $groupData) {
            $group = SportClassGroup::updateOrCreate(
                ['code' => $groupData['code']],
                [
                    'name_de' => $groupData['name_de'],
                    'is_virtual' => false,
                    'sort_order' => $groupData['sort_order'],
                    'is_active' => true,
                ]
            );

            foreach ($groupData['classes'] as $sportClass) {
                SportClassGroupMember::firstOrCreate(
                    ['sport_class' => $sportClass],
                    ['sport_class_group_id' => $group->id]
                );
            }
        }

        // Virtuelle Top-Gruppe (Punkt 8) — keine festen Sportklassen-Mitglieder,
        // ergibt sich aus Kader / Punktgrenze / Ausland (siehe GroupResolverService, Phase 2).
        SportClassGroup::updateOrCreate(
            ['code' => 'TOP'],
            ['name_de' => 'Top-Gruppe', 'is_virtual' => true, 'sort_order' => 0, 'is_active' => true]
        );

        $this->command->info('SportClassGroups: '.(count($groups) + 1).' Gruppen angelegt/aktualisiert.');
    }

    /** @return array<string> z.B. range('S', 1, 3) → ['S1', 'S2', 'S3'] */
    private function range(string $prefix, int $from, int $to): array
    {
        return array_map(fn (int $n) => $prefix.$n, range($from, $to));
    }
}
