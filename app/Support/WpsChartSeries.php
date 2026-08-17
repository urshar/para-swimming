<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Datenreihe für die Verlaufsgrafik eines Bewerbs (Spec "WPS Rankings" §7.6).
 *
 * Der Service liefert fertig gerechnete Bildkoordinaten, Blade zeichnet nur noch. Kein
 * JavaScript: Die Grafik soll auch im PDF erscheinen, und dompdf führt keine Skripte aus.
 *
 * Die Rechnung gehört ohnehin nicht ins Markup — eine Achsenskalierung in Blade wäre weder
 * lesbar noch prüfbar.
 */
final readonly class WpsChartSeries
{
    /** Bildfläche in Nutzerkoordinaten; die Anzeige skaliert über viewBox. */
    public const int WIDTH = 720;

    public const int HEIGHT = 240;

    /** Ränder für Achsenbeschriftungen. */
    public const int PADDING_LEFT = 44;

    public const int PADDING_RIGHT = 12;

    public const int PADDING_TOP = 12;

    public const int PADDING_BOTTOM = 28;

    /**
     * @param  Collection<int, WpsChartPoint>  $points  chronologisch
     * @param  list<array{y: float, label: int}>  $gridLines  waagrechte Hilfslinien mit Punktwert
     * @param  list<array{x: float, label: string}>  $xLabels  Beschriftungen der Zeitachse
     * @param  list<array{x: float, label: string}>  $markers  senkrechte Markierungen
     *                                                         (Klassenwechsel, Notizen)
     */
    public function __construct(
        public string $eventLabel,
        public Collection $points,
        public array $gridLines,
        public array $xLabels,
        public array $markers,
        public int $minPoints,
        public int $maxPoints,
    ) {}

    /**
     * Eine Grafik lohnt erst ab zwei Punkten.
     *
     * Aus einem einzelnen Wert lässt sich keine Entwicklung ablesen, und eine Linie mit einem
     * Punkt sähe nach einem Fehler aus.
     */
    public function isDrawable(): bool
    {
        return $this->points->count() >= 2;
    }

    /**
     * Die Punkte als Polylinie, z.B. "44,120 180,96 316,72".
     *
     * Fertig zusammengesetzt, damit das Markup keine Schleife mit Zwischenraum-Logik braucht.
     */
    public function polyline(): string
    {
        return $this->points
            ->map(static fn (WpsChartPoint $p): string => round($p->x, 1).','.round($p->y, 1))
            ->implode(' ');
    }

    public function viewBox(): string
    {
        return sprintf('0 0 %d %d', self::WIDTH, self::HEIGHT);
    }

    /**
     * Zeichenfläche als einfache Werte fürs Markup.
     *
     * Damit im Blade keine vollqualifizierten Klassennamen für Konstanten stehen müssen —
     * die machen ein SVG-Gerüst unlesbar.
     *
     * @return array{left: int, right: int, top: int, bottom: int, labelX: int, axisY: int}
     */
    public function frame(): array
    {
        return [
            'left' => self::PADDING_LEFT,
            'right' => self::WIDTH - self::PADDING_RIGHT,
            'top' => self::PADDING_TOP,
            'bottom' => self::HEIGHT - self::PADDING_BOTTOM,
            // Beschriftungen der Punkteachse enden knapp links der Zeichenfläche.
            'labelX' => self::PADDING_LEFT - 6,
            // Die Zeitachse steht unterhalb der Zeichenfläche.
            'axisY' => self::HEIGHT - 10,
        ];
    }
}
