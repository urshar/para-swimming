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
     * Quelle: World Aquatics Points — Base Times, LCM (50 m) 2026 und SCM (25 m) 2025.
     * Der Faktor ist das Verhältnis der beiden Basiszeiten desselben Bewerbs:
     *
     *     factor = Basiszeit LCM / Basiszeit SCM
     *
     * Warum diese Quelle
     * ------------------
     * Para-spezifische Umrechnungsfaktoren existieren nicht (Spec §9.8). Zwei naheliegende
     * Quellen wurden geprüft und verworfen — die australischen Multi-Class-Basiszeiten und
     * die eigene ÖBSV-Tabelle. Beide folgen dem MCPS-Protokoll und setzen die Basiszeit auf
     * den Weltrekord. Da Para-Kurzbahn international kaum ausgetragen wird, sind die
     * Kurzbahnrekorde nicht ausgeschwommen: 75 % der Verhältnisse lagen dort UNTER 1, was
     * behaupten würde, auf der Kurzbahn werde langsamer geschwommen.
     *
     * Die World-Aquatics-Basiszeiten haben dieses Problem nicht — beide Bahnlängen werden
     * bei Weltmeisterschaften voll ausgeschwommen. Alle 34 Faktoren liegen zwischen 1,0135
     * und 1,0677, keiner unter 1. Die Struktur ist fachlich stimmig: Rücken hat die höchsten
     * Werte (längste Unterwasserphase nach der Wende), und die Faktoren fallen mit
     * zunehmender Streckenlänge, weil der Wendenvorteil relativ an Gewicht verliert.
     *
     * Grenzen dieser Werte
     * --------------------
     * Sie stammen von nicht behinderten Weltrekordhaltern mit vollem Wendenvorteil. Für die
     * Klassen S1–S4, wo eine Wende physiologisch weniger einbringt, sind sie vermutlich zu
     * hoch. Sie greifen deshalb nur als RÜCKFALL: Wo eine ausreichende eigene Stichprobe
     * vorliegt, überschreibt die Kalibrierung sie mit einem klassenspezifischen Wert
     * (Spec §9.3).
     *
     * Jährlich zu aktualisieren, sobald World Aquatics neue Basiszeiten veröffentlicht.
     */
    private const string SOURCE = 'World Aquatics Base Times LCM 2026 / SCM 2025';

    /**
     * Faktoren je Schwimmstil, Strecke und Geschlecht: [Stil, Strecke, Männer, Frauen].
     *
     * Nach Geschlecht getrennt, weil die Unterschiede bis zu zwei Prozentpunkte betragen
     * (Freistil 50 m: 1,0508 gegen 1,0342) — in derselben Größenordnung wie der Effekt selbst.
     *
     * @var list<array{0: string, 1: int, 2: float, 3: float}>
     */
    private const array FACTORS = [
        ['BACK', 50, 1.06513, 1.06461],
        ['BACK', 100, 1.06766, 1.05757],
        ['BACK', 200, 1.05955, 1.04321],
        ['BREAST', 50, 1.04008, 1.02785],
        ['BREAST', 100, 1.02894, 1.02838],
        ['BREAST', 200, 1.04427, 1.03811],
        ['FLY', 50, 1.04456, 1.02047],
        ['FLY', 100, 1.03647, 1.03586],
        ['FLY', 200, 1.03266, 1.02087],
        ['FREE', 50, 1.05075, 1.03417],
        ['FREE', 100, 1.03479, 1.02905],
        ['FREE', 200, 1.03438, 1.01741],
        ['FREE', 400, 1.03633, 1.01707],
        ['FREE', 800, 1.02647, 1.01403],
        ['FREE', 1500, 1.02809, 1.01348],
        ['MEDLEY', 200, 1.03499, 1.03346],
        ['MEDLEY', 400, 1.03275, 1.03198],
    ];

    /**
     * Sammelwerte je Stil und Geschlecht — Median über die Strecken.
     *
     * Rückfall für Bewerbe ohne World-Aquatics-Entsprechung. Betrifft vor allem 150 m Lagen
     * (nur im Para-Schwimmsport ausgetragen) und 100 m Lagen, das World Aquatics nur auf der
     * Kurzbahn führt.
     *
     * @var array<string, array{M: float, F: float}>
     */
    private const array STROKE_FALLBACKS = [
        'FREE' => ['M' => 1.03459, 'F' => 1.01724],
        'BACK' => ['M' => 1.06513, 'F' => 1.05757],
        'BREAST' => ['M' => 1.04008, 'F' => 1.02838],
        'FLY' => ['M' => 1.03647, 'F' => 1.02087],
        'MEDLEY' => ['M' => 1.03387, 'F' => 1.03272],
    ];

    public function run(): void
    {
        $strokeTypes = StrokeType::whereIn('lenex_code', array_keys(self::STROKE_FALLBACKS))
            ->pluck('id', 'lenex_code');

        foreach (self::FACTORS as [$lenexCode, $distance, $male, $female]) {
            if (! isset($strokeTypes[$lenexCode])) {
                continue;
            }

            $this->store($strokeTypes[$lenexCode], $distance, 'M', $male);
            $this->store($strokeTypes[$lenexCode], $distance, 'F', $female);
        }

        foreach (self::STROKE_FALLBACKS as $lenexCode => $werte) {
            if (! isset($strokeTypes[$lenexCode])) {
                continue;
            }

            $this->store($strokeTypes[$lenexCode], null, 'M', $werte['M']);
            $this->store($strokeTypes[$lenexCode], null, 'F', $werte['F']);
        }
    }

    /**
     * updateOrCreate: wiederholt ausführbar. Von Hand gesetzte Faktoren bleiben unangetastet
     * — sonst ginge eine bewusste Korrektur beim jährlichen Aktualisieren verloren.
     */
    private function store(int $strokeTypeId, ?int $distance, string $gender, float $factor): void
    {
        $merkmale = [
            'stroke_type_id' => $strokeTypeId,
            'distance' => $distance,
            'sport_class' => null,
            'gender' => $gender,
        ];

        $vorhanden = WpsScmConversionFactor::where($merkmale)->first();

        if ($vorhanden !== null && $vorhanden->source === WpsScmConversionFactor::SOURCE_MANUAL) {
            return;
        }

        WpsScmConversionFactor::updateOrCreate($merkmale, [
            'factor' => $factor,
            'source' => WpsScmConversionFactor::SOURCE_LITERATURE,
            'sample_size' => null,
            // medium statt low: aus voll ausgeschwommenen Rekorden beider Bahnlängen
            // ermittelt, aber nicht para-spezifisch.
            'confidence_level' => WpsScmConversionFactor::CONFIDENCE_MEDIUM,
            'notes' => self::SOURCE.' — nicht para-spezifisch, beruht auf nichtbehinderten '
                .'Weltrekordhaltern mit vollem Wendenvorteil. Für die Klassen S1–S4 '
                .'vermutlich zu hoch. Wird von eigenen Daten überschrieben, wo vorhanden.',
            'active' => true,
        ]);
    }
}
