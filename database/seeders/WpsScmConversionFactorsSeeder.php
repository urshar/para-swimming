<?php

namespace Database\Seeders;

use App\Models\StrokeType;
use App\Models\WpsScmConversionFactor;
use Illuminate\Database\Seeder;

class WpsScmConversionFactorsSeeder extends Seeder
{
    /**
     * Startwerte für die Umrechnung Kurzbahn → Langbahn.
     *
     * ╔══════════════════════════════════════════════════════════════════════════════╗
     * ║  ACHTUNG — DIESE WERTE SIND VOR DEM PRODUKTIVEINSATZ ZU ERSETZEN.            ║
     * ║                                                                              ║
     * ║  Für den Para-Schwimmsport existieren KEINE veröffentlichten                 ║
     * ║  Umrechnungsfaktoren. Die folgenden Werte sind aus den im Allgemeinen        ║
     * ║  Schwimmsport gebräuchlichen Umrechnungen abgeleitet (Größenordnung rund     ║
     * ║  2 % je 100 m, größere Abstände bei Rücken und Schmetterling wegen der       ║
     * ║  längeren Unterwasserphasen) und setzen den vollen Wendenvorteil voraus.     ║
     * ║                                                                              ║
     * ║  Genau der ist im Para-Schwimmsport klassenabhängig sehr unterschiedlich:    ║
     * ║  ein S3-Athlet zieht aus einer zusätzlichen Wende deutlich weniger Nutzen    ║
     * ║  als ein S14-Athlet. Die Werte sind daher ein Platzhalter, bis der ÖBSV      ║
     * ║  fachlich abgestimmte Ausgangswerte festlegt.                                ║
     * ║                                                                              ║
     * ║  Sie greifen ohnehin nur dort, wo keine eigenen Daten vorliegen — der        ║
     * ║  Kalibrierungslauf überschreibt sie für alle ausreichend belegten            ║
     * ║  Kombinationen (Spec §9.3).                                                  ║
     * ╚══════════════════════════════════════════════════════════════════════════════╝
     *
     * Alle Einträge tragen source = literature und confidence_level = low.
     */
    public function run(): void
    {
        $faktoren = [
            'FREE' => 1.020,
            'BREAST' => 1.020,
            'BACK' => 1.025,
            'FLY' => 1.025,
            'MEDLEY' => 1.023,
        ];

        $hinweis = 'Platzhalter aus dem allgemeinen Schwimmsport — nicht para-spezifisch '
            .'erhoben, nicht von World Para Swimming anerkannt. Vor dem Produktiveinsatz '
            .'durch mit dem ÖBSV abgestimmte Werte ersetzen.';

        foreach ($faktoren as $lenexCode => $faktor) {
            $strokeType = StrokeType::where('lenex_code', $lenexCode)->first();

            if ($strokeType === null) {
                continue;
            }

            // updateOrCreate: wiederholt ausführbar. Bereits kalibrierte Faktoren aus
            // eigenen Daten liegen auf spezifischeren Kombinationen (mit Strecke und Klasse)
            // und werden davon nicht berührt.
            WpsScmConversionFactor::updateOrCreate([
                'stroke_type_id' => $strokeType->id,
                'distance' => null,
                'sport_class' => null,
                'gender' => null,
            ], [
                'factor' => $faktor,
                'source' => WpsScmConversionFactor::SOURCE_LITERATURE,
                'sample_size' => null,
                'confidence_level' => WpsScmConversionFactor::CONFIDENCE_LOW,
                'notes' => $hinweis,
                'active' => true,
            ]);
        }
    }
}
