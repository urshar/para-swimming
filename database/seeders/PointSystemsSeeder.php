<?php

namespace Database\Seeders;

use App\Models\PointSystem;
use Illuminate\Database\Seeder;

class PointSystemsSeeder extends Seeder
{
    /**
     * Registry der Punktesysteme.
     *
     * WA ist bereits vollständig implementiert (WorldAquaticsPointsService, base_time_*).
     * WPS wird mit diesem Modul aufgebaut. OBSV1000 ist als Richtzeiten-Grundlage vorhanden,
     * wird aber nicht je Veranstaltung aktiviert und ist daher inaktiv vorbelegt.
     *
     * updateOrCreate statt create: der Seeder muss auf einer bereits befüllten Datenbank
     * wiederholt ausführbar sein.
     */
    public function run(): void
    {
        $systems = [
            [
                'code' => PointSystem::CODE_WORLD_AQUATICS,
                'name' => 'World Aquatics Punkte',
                'description' => 'Punkte nach der Formel P = 1000 × (B/T)³ auf Basis der '.
                    'World-Aquatics-Basiszeiten. Gespeichert in results.points.',
                'active' => true,
            ],
            [
                'code' => PointSystem::CODE_WPS,
                'name' => 'World Para Swimming Points',
                'description' => 'Punkte nach der Gompertz-Funktion q = a × e^(-e^(b - c/p)) '.
                    'auf Basis der WPS Point Scores. Gespeichert in results.wps_points.',
                'active' => true,
            ],
            [
                'code' => PointSystem::CODE_OBSV_1000,
                'name' => 'ÖBSV 1000 Punkte',
                'description' => 'Umkehrung der World-Aquatics-Formel zur Ermittlung der '.
                    'Richtzeiten. Wird nicht je Veranstaltung berechnet.',
                'active' => false,
            ],
        ];

        foreach ($systems as $system) {
            PointSystem::updateOrCreate(['code' => $system['code']], $system);
        }
    }
}
