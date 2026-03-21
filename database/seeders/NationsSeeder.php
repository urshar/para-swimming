<?php

namespace Database\Seeders;

use App\Models\Nation;
use Illuminate\Database\Seeder;

class NationsSeeder extends Seeder
{
    public function run(): void
    {
        $nations = [
            // ── Europa ───────────────────────────────────────────────────────
            ['code' => 'ALB', 'name_de' => 'Albanien',              'name_en' => 'Albania'],
            ['code' => 'AND', 'name_de' => 'Andorra',               'name_en' => 'Andorra'],
            ['code' => 'ARM', 'name_de' => 'Armenien',              'name_en' => 'Armenia'],
            ['code' => 'AUT', 'name_de' => 'Österreich',            'name_en' => 'Austria'],
            ['code' => 'AZE', 'name_de' => 'Aserbaidschan',         'name_en' => 'Azerbaijan'],
            ['code' => 'BEL', 'name_de' => 'Belgien',               'name_en' => 'Belgium'],
            ['code' => 'BIH', 'name_de' => 'Bosnien-Herzegowina',   'name_en' => 'Bosnia and Herzegovina'],
            ['code' => 'BLR', 'name_de' => 'Belarus',               'name_en' => 'Belarus'],
            ['code' => 'BUL', 'name_de' => 'Bulgarien',             'name_en' => 'Bulgaria'],
            ['code' => 'CRO', 'name_de' => 'Kroatien',              'name_en' => 'Croatia'],
            ['code' => 'CYP', 'name_de' => 'Zypern',                'name_en' => 'Cyprus'],
            ['code' => 'CZE', 'name_de' => 'Tschechien',            'name_en' => 'Czech Republic'],
            ['code' => 'DEN', 'name_de' => 'Dänemark',              'name_en' => 'Denmark'],
            ['code' => 'ESP', 'name_de' => 'Spanien',               'name_en' => 'Spain'],
            ['code' => 'EST', 'name_de' => 'Estland',               'name_en' => 'Estonia'],
            ['code' => 'FIN', 'name_de' => 'Finnland',              'name_en' => 'Finland'],
            ['code' => 'FRA', 'name_de' => 'Frankreich',            'name_en' => 'France'],
            ['code' => 'GBR', 'name_de' => 'Großbritannien',        'name_en' => 'Great Britain'],
            ['code' => 'GEO', 'name_de' => 'Georgien',              'name_en' => 'Georgia'],
            ['code' => 'GER', 'name_de' => 'Deutschland',           'name_en' => 'Germany'],
            ['code' => 'GRE', 'name_de' => 'Griechenland',          'name_en' => 'Greece'],
            ['code' => 'HUN', 'name_de' => 'Ungarn',                'name_en' => 'Hungary'],
            ['code' => 'IRL', 'name_de' => 'Irland',                'name_en' => 'Ireland'],
            ['code' => 'ISL', 'name_de' => 'Island',                'name_en' => 'Iceland'],
            ['code' => 'ISR', 'name_de' => 'Israel',                'name_en' => 'Israel'],
            ['code' => 'ITA', 'name_de' => 'Italien',               'name_en' => 'Italy'],
            ['code' => 'KAZ', 'name_de' => 'Kasachstan',            'name_en' => 'Kazakhstan'],
            ['code' => 'KOS', 'name_de' => 'Kosovo',                'name_en' => 'Kosovo'],
            ['code' => 'LAT', 'name_de' => 'Lettland',              'name_en' => 'Latvia'],
            ['code' => 'LIE', 'name_de' => 'Liechtenstein',         'name_en' => 'Liechtenstein'],
            ['code' => 'LTU', 'name_de' => 'Litauen',               'name_en' => 'Lithuania'],
            ['code' => 'LUX', 'name_de' => 'Luxemburg',             'name_en' => 'Luxembourg'],
            ['code' => 'MDA', 'name_de' => 'Moldau',                'name_en' => 'Moldova'],
            ['code' => 'MKD', 'name_de' => 'Nordmazedonien',        'name_en' => 'North Macedonia'],
            ['code' => 'MLT', 'name_de' => 'Malta',                 'name_en' => 'Malta'],
            ['code' => 'MNE', 'name_de' => 'Montenegro',            'name_en' => 'Montenegro'],
            ['code' => 'MON', 'name_de' => 'Monaco',                'name_en' => 'Monaco'],
            ['code' => 'NED', 'name_de' => 'Niederlande',           'name_en' => 'Netherlands'],
            ['code' => 'NOR', 'name_de' => 'Norwegen',              'name_en' => 'Norway'],
            ['code' => 'POL', 'name_de' => 'Polen',                 'name_en' => 'Poland'],
            ['code' => 'POR', 'name_de' => 'Portugal',              'name_en' => 'Portugal'],
            ['code' => 'ROU', 'name_de' => 'Rumänien',              'name_en' => 'Romania'],
            ['code' => 'RUS', 'name_de' => 'Russland',              'name_en' => 'Russia'],
            ['code' => 'SLO', 'name_de' => 'Slowenien',             'name_en' => 'Slovenia'],
            ['code' => 'SMR', 'name_de' => 'San Marino',            'name_en' => 'San Marino'],
            ['code' => 'SRB', 'name_de' => 'Serbien',               'name_en' => 'Serbia'],
            ['code' => 'SVK', 'name_de' => 'Slowakei',              'name_en' => 'Slovakia'],
            ['code' => 'SWE', 'name_de' => 'Schweden',              'name_en' => 'Sweden'],
            ['code' => 'SUI', 'name_de' => 'Schweiz',               'name_en' => 'Switzerland'],
            ['code' => 'TUR', 'name_de' => 'Türkei',                'name_en' => 'Turkey'],
            ['code' => 'UKR', 'name_de' => 'Ukraine',               'name_en' => 'Ukraine'],

            // ── Amerika ──────────────────────────────────────────────────────
            ['code' => 'ARG', 'name_de' => 'Argentinien',           'name_en' => 'Argentina'],
            ['code' => 'BOL', 'name_de' => 'Bolivien',              'name_en' => 'Bolivia'],
            ['code' => 'BRA', 'name_de' => 'Brasilien',             'name_en' => 'Brazil'],
            ['code' => 'CAN', 'name_de' => 'Kanada',                'name_en' => 'Canada'],
            ['code' => 'CHI', 'name_de' => 'Chile',                 'name_en' => 'Chile'],
            ['code' => 'COL', 'name_de' => 'Kolumbien',             'name_en' => 'Colombia'],
            ['code' => 'CRC', 'name_de' => 'Costa Rica',            'name_en' => 'Costa Rica'],
            ['code' => 'CUB', 'name_de' => 'Kuba',                  'name_en' => 'Cuba'],
            ['code' => 'DOM', 'name_de' => 'Dominikanische Republik', 'name_en' => 'Dominican Republic'],
            ['code' => 'ECU', 'name_de' => 'Ecuador',               'name_en' => 'Ecuador'],
            ['code' => 'GUA', 'name_de' => 'Guatemala',             'name_en' => 'Guatemala'],
            ['code' => 'MEX', 'name_de' => 'Mexiko',                'name_en' => 'Mexico'],
            ['code' => 'PAN', 'name_de' => 'Panama',                'name_en' => 'Panama'],
            ['code' => 'PAR', 'name_de' => 'Paraguay',              'name_en' => 'Paraguay'],
            ['code' => 'PER', 'name_de' => 'Peru',                  'name_en' => 'Peru'],
            ['code' => 'PUR', 'name_de' => 'Puerto Rico',           'name_en' => 'Puerto Rico'],
            ['code' => 'URU', 'name_de' => 'Uruguay',               'name_en' => 'Uruguay'],
            ['code' => 'USA', 'name_de' => 'Vereinigte Staaten',    'name_en' => 'United States'],
            ['code' => 'VEN', 'name_de' => 'Venezuela',             'name_en' => 'Venezuela'],

            // ── Asien / Ozeanien ─────────────────────────────────────────────
            ['code' => 'AUS', 'name_de' => 'Australien',            'name_en' => 'Australia'],
            ['code' => 'CHN', 'name_de' => 'China',                 'name_en' => 'China'],
            ['code' => 'HKG', 'name_de' => 'Hongkong',              'name_en' => 'Hong Kong'],
            ['code' => 'INA', 'name_de' => 'Indonesien',            'name_en' => 'Indonesia'],
            ['code' => 'IND', 'name_de' => 'Indien',                'name_en' => 'India'],
            ['code' => 'IRI', 'name_de' => 'Iran',                  'name_en' => 'Iran'],
            ['code' => 'JPN', 'name_de' => 'Japan',                 'name_en' => 'Japan'],
            ['code' => 'KOR', 'name_de' => 'Südkorea',              'name_en' => 'South Korea'],
            ['code' => 'MAS', 'name_de' => 'Malaysia',              'name_en' => 'Malaysia'],
            ['code' => 'NZL', 'name_de' => 'Neuseeland',            'name_en' => 'New Zealand'],
            ['code' => 'PHI', 'name_de' => 'Philippinen',           'name_en' => 'Philippines'],
            ['code' => 'SGP', 'name_de' => 'Singapur',              'name_en' => 'Singapore'],
            ['code' => 'THA', 'name_de' => 'Thailand',              'name_en' => 'Thailand'],
            ['code' => 'TPE', 'name_de' => 'Chinese Taipei',        'name_en' => 'Chinese Taipei'],
            ['code' => 'UZB', 'name_de' => 'Usbekistan',            'name_en' => 'Uzbekistan'],

            // ── Afrika / Naher Osten ──────────────────────────────────────────
            ['code' => 'ALG', 'name_de' => 'Algerien',              'name_en' => 'Algeria'],
            ['code' => 'EGY', 'name_de' => 'Ägypten',               'name_en' => 'Egypt'],
            ['code' => 'ETH', 'name_de' => 'Äthiopien',             'name_en' => 'Ethiopia'],
            ['code' => 'GHA', 'name_de' => 'Ghana',                 'name_en' => 'Ghana'],
            ['code' => 'KEN', 'name_de' => 'Kenia',                 'name_en' => 'Kenya'],
            ['code' => 'MAR', 'name_de' => 'Marokko',               'name_en' => 'Morocco'],
            ['code' => 'NGR', 'name_de' => 'Nigeria',               'name_en' => 'Nigeria'],
            ['code' => 'RSA', 'name_de' => 'Südafrika',             'name_en' => 'South Africa'],
            ['code' => 'TUN', 'name_de' => 'Tunesien',              'name_en' => 'Tunisia'],
            ['code' => 'UAE', 'name_de' => 'Vereinigte Arabische Emirate', 'name_en' => 'United Arab Emirates'],

            // ── Sondereinträge ────────────────────────────────────────────────
            ['code' => 'NPA', 'name_de' => 'Neutral Para Athlet',   'name_en' => 'Neutral Para Athlete'],
        ];

        foreach ($nations as $nation) {
            Nation::updateOrCreate(
                ['code' => $nation['code']],
                array_merge($nation, ['is_active' => true])
            );
        }

        $this->command->info('Nations: '.count($nations).' Einträge angelegt/aktualisiert.');
    }
}
