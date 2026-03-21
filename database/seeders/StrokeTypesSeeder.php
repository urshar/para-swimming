<?php

namespace Database\Seeders;

use App\Models\StrokeType;
use Illuminate\Database\Seeder;

class StrokeTypesSeeder extends Seeder
{
    public function run(): void
    {
        $strokes = [

            // ── Standard-Schwimmstile ─────────────────────────────────────────
            // LENEX 3.0 SWIMSTYLE.stroke Standardwerte
            [
                'code' => 'FREE',
                'lenex_code' => 'FREE',
                'name_de' => 'Freistil',
                'name_en' => 'Freestyle',
                'category' => 'standard',
                'is_relay_stroke' => false,
                'is_active' => true,
            ],
            [
                'code' => 'BACK',
                'lenex_code' => 'BACK',
                'name_de' => 'Rücken',
                'name_en' => 'Backstroke',
                'category' => 'standard',
                'is_relay_stroke' => false,
                'is_active' => true,
            ],
            [
                'code' => 'BREAST',
                'lenex_code' => 'BREAST',
                'name_de' => 'Brust',
                'name_en' => 'Breaststroke',
                'category' => 'standard',
                'is_relay_stroke' => false,
                'is_active' => true,
            ],
            [
                'code' => 'FLY',
                'lenex_code' => 'FLY',
                'name_de' => 'Schmetterling',
                'name_en' => 'Butterfly',
                'category' => 'standard',
                'is_relay_stroke' => false,
                'is_active' => true,
            ],
            [
                'code' => 'MEDLEY',
                'lenex_code' => 'MEDLEY',
                'name_de' => 'Lagen',
                'name_en' => 'Individual Medley',
                'category' => 'standard',
                'is_relay_stroke' => false,
                'is_active' => true,
            ],
            [
                // Staffel wo jeder Schwimmer alle Stile schwimmt (wie Einzel-Lagen)
                'code' => 'IMRELAY',
                'lenex_code' => 'IMRELAY',
                'name_de' => 'Lagen-Staffel (jeder alle Stile)',
                'name_en' => 'IM Relay',
                'category' => 'standard',
                'is_relay_stroke' => true,
                'is_active' => true,
            ],
            [
                // Unbekannter/Sonderstil — name Attribut in SWIMSTYLE ist dann Pflicht
                'code' => 'UNKNOWN',
                'lenex_code' => 'UNKNOWN',
                'name_de' => 'Sonderstil',
                'name_en' => 'Unknown / Special',
                'category' => 'special',
                'is_relay_stroke' => false,
                'is_active' => true,
            ],

            // ── Flossenschwimmen (Fin Swimming) ───────────────────────────────
            // LENEX 3.0 — alle Fin-Swimming Werte (ab Oktober 2024 vollständig)
            [
                'code' => 'APNEA',
                'lenex_code' => 'APNEA',
                'name_de' => 'Apnoe',
                'name_en' => 'Apnea',
                'category' => 'fin',
                'is_relay_stroke' => false,
                'is_active' => true,
            ],
            [
                'code' => 'BIFINS',
                'lenex_code' => 'BIFINS',
                'name_de' => 'Bifinnen',
                'name_en' => 'Bi-Fins',
                'category' => 'fin',
                'is_relay_stroke' => false,
                'is_active' => true,
            ],
            [
                'code' => 'DYNAMIC',
                'lenex_code' => 'DYNAMIC',
                'name_de' => 'Dynamisch',
                'name_en' => 'Dynamic',
                'category' => 'fin',
                'is_relay_stroke' => false,
                'is_active' => true,
            ],
            [
                'code' => 'DYNAMIC_BIFINS',
                'lenex_code' => 'DYNAMIC_BIFINS',
                'name_de' => 'Dynamisch mit BiFins',
                'name_en' => 'Dynamic with Bi-Fins',
                'category' => 'fin',
                'is_relay_stroke' => false,
                'is_active' => true,
            ],
            [
                'code' => 'DYNAMIC_NOFINS',
                'lenex_code' => 'DYNAMIC_NOFINS',
                'name_de' => 'Dynamisch ohne Flossen',
                'name_en' => 'Dynamic without Fins',
                'category' => 'fin',
                'is_relay_stroke' => false,
                'is_active' => true,
            ],
            [
                'code' => 'IMMERSION',
                'lenex_code' => 'IMMERSION',
                'name_de' => 'Tauchen',
                'name_en' => 'Immersion',
                'category' => 'fin',
                'is_relay_stroke' => false,
                'is_active' => true,
            ],
            [
                // Staffel nach CMAS-Regeln: Bifinnen, Bifinnen, Oberfläche, Oberfläche
                'code' => 'MULTIPLE',
                'lenex_code' => 'MULTIPLE',
                'name_de' => 'Mehrfach-Staffel (CMAS)',
                'name_en' => 'Multiple Relay (CMAS)',
                'category' => 'fin',
                'is_relay_stroke' => true,
                'is_active' => true,
            ],
            [
                'code' => 'SPEED_APNEA',
                'lenex_code' => 'SPEED_APNEA',
                'name_de' => 'Schnell-Apnoe',
                'name_en' => 'Speed Apnea',
                'category' => 'fin',
                'is_relay_stroke' => false,
                'is_active' => true,
            ],
            [
                'code' => 'SPEED_ENDURANCE',
                'lenex_code' => 'SPEED_ENDURANCE',
                'name_de' => 'Schnell-Ausdauer',
                'name_en' => 'Speed Endurance',
                'category' => 'fin',
                'is_relay_stroke' => false,
                'is_active' => true,
            ],
            [
                'code' => 'STATIC',
                'lenex_code' => 'STATIC',
                'name_de' => 'Statisch',
                'name_en' => 'Static',
                'category' => 'fin',
                'is_relay_stroke' => false,
                'is_active' => true,
            ],
            [
                'code' => 'SURFACE',
                'lenex_code' => 'SURFACE',
                'name_de' => 'Surface',
                'name_en' => 'Surface',
                'category' => 'fin',
                'is_relay_stroke' => false,
                'is_active' => true,
            ],

            // ── Deutschland-spezifisch (LENEX 3.0 Sektion 7.1) ───────────────
            [
                'code' => 'GER_APH',
                'lenex_code' => 'GER.APH',
                'name_de' => 'Apnoe mit Hebeboje (GER)',
                'name_en' => 'Apnea with Buoy (GER)',
                'category' => 'special',
                'is_relay_stroke' => false,
                'is_active' => true,
            ],
        ];

        foreach ($strokes as $stroke) {
            StrokeType::updateOrCreate(
                ['code' => $stroke['code']],
                $stroke
            );
        }

        $this->command->info('StrokeTypes: '.count($strokes).' Einträge angelegt/aktualisiert.');
    }
}
