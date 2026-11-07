<?php

namespace App\Support;

/**
 * ClubForecastComparison
 *
 * Vergleich eines Vereins zwischen aktueller Vereinswertung und Prognose
 * (docs/specs/cup-club-ranking-forecast.md §6).
 *
 * - currentRank:   Rang in der aktuellen Wertung; null = bisher nicht gewertet
 * - currentValue:  Wert in der aktuellen Wertung (Starts bzw. Gesamtpunkte); 0 = bisher nicht gewertet
 * - forecastRank:  Rang in der Prognose
 * - forecastValue: Wert in der Prognose
 * - rankChange:    currentRank − forecastRank (positiv = Verbesserung); null = bisher nicht gewertet
 * - valueDelta:    forecastValue − currentValue
 */
final readonly class ClubForecastComparison
{
    public function __construct(
        public int $clubId,
        public ?int $currentRank,
        public float $currentValue,
        public int $forecastRank,
        public float $forecastValue,
        public ?int $rankChange,
        public float $valueDelta,
    ) {}

    /**
     * Differenz mit Vorzeichen im deutschen Zahlenformat, z. B. "+ 1.800,00", "- 12" oder "± 0".
     * Bewusst ASCII-Minus statt "−" (U+2212): Die Standardschrift des PDF-Exports (Helvetica in
     * dompdf) kennt das typografische Minus nicht.
     */
    public function formattedValueDelta(int $decimals): string
    {
        $sign = $this->valueDelta > 0 ? '+' : ($this->valueDelta < 0 ? '-' : '±');

        return $sign.' '.number_format(abs($this->valueDelta), $decimals, ',', '.');
    }

    /**
     * Rangänderung als Kurztext ohne Pfeilsymbole (für den PDF-Export): "+2", "-1", "±0" oder
     * "neu" für einen bisher nicht gewerteten Verein.
     */
    public function rankChangeShort(): string
    {
        return match (true) {
            $this->rankChange === null => 'neu',
            $this->rankChange > 0 => '+'.$this->rankChange,
            $this->rankChange < 0 => (string) $this->rankChange,
            default => '±0',
        };
    }
}
