<?php

namespace App\Support;

/**
 * TrendChart
 *
 * Reines-SVG-Mehrlinien-Diagramm für Jahresvergleiche (Statistik-Modul). Ein
 * Value Object, das aus Jahren + Serien die fertige Zeichengeometrie berechnet
 * (Rahmen, Hilfslinien, Achsenbeschriftung, je Serie Polyline + Punkte,
 * Legende). Die Blade-Komponente components/trend-chart.blade.php stellt das nur
 * noch dar.
 *
 * Bewusst wie x-wps-chart als reines SVG ohne JavaScript, damit dieselbe Grafik
 * am Bildschirm UND im PDF erscheint (dompdf führt kein JS aus). Alle Koordinaten
 * sind ganzzahlige Nutzerkoordinaten — das vermeidet Locale-Probleme beim
 * Float-zu-String und hält das Markup lesbar.
 */
final readonly class TrendChart
{
    private const int WIDTH = 640;

    private const int HEIGHT = 340;

    private const int PLOT_LEFT = 48;

    private const int PLOT_RIGHT = 624;

    private const int PLOT_TOP = 40;

    private const int PLOT_BOTTOM = 300;

    private const int LEGEND_SWATCH_Y = 8;

    private const int LEGEND_TEXT_Y = 17;

    private const int X_LABEL_Y = 318;

    /** Zielanzahl der y-Hilfslinien (die tatsächliche Zahl richtet sich nach dem Wertebereich). */
    private const int TARGET_TICKS = 4;

    /**
     * @param  list<int>  $years
     * @param  list<array{color: string, label: string, polyline: string, dots: list<array{x: int, y: int}>}>  $lines
     * @param  list<array{y: int, label: string}>  $gridLines
     * @param  list<array{x: int, label: string}>  $xLabels
     * @param  list<array{color: string, label: string, x: int}>  $legend
     */
    private function __construct(
        public array $years,
        public array $lines,
        public array $gridLines,
        public array $xLabels,
        public array $legend,
        public bool $hasData,
    ) {}

    /**
     * Baut die Geometrie aus den Jahren (x-Achse) und den Serien.
     *
     * @param  list<int>  $years  aufsteigend
     * @param  list<array{label: string, color: string, values: list<int>}>  $series  values je Jahr, gleiche Reihenfolge wie $years
     */
    public static function fromSeries(array $years, array $series): self
    {
        $years = array_values($years);
        $count = count($years);

        $max = 0;
        foreach ($series as $serie) {
            foreach ($serie['values'] as $value) {
                $max = max($max, (int) $value);
            }
        }

        [$niceMax, $step, $ticks] = self::niceScale(max(1, $max));

        $innerWidth = self::PLOT_RIGHT - self::PLOT_LEFT;
        $innerHeight = self::PLOT_BOTTOM - self::PLOT_TOP;

        $xAt = static fn (int $index): int => $count <= 1
            ? self::PLOT_LEFT + intdiv($innerWidth, 2)
            : self::PLOT_LEFT + (int) round($innerWidth * $index / ($count - 1));

        $yAt = static fn (int $value): int => self::PLOT_BOTTOM - (int) round($innerHeight * $value / $niceMax);

        $gridLines = [];
        for ($i = 0; $i <= $ticks; $i++) {
            $value = $step * $i;
            $gridLines[] = ['y' => $yAt($value), 'label' => (string) $value];
        }

        $xLabels = [];
        foreach ($years as $index => $year) {
            $xLabels[] = ['x' => $xAt($index), 'label' => (string) $year];
        }

        $lines = [];
        foreach ($series as $serie) {
            $dots = [];
            $points = [];
            foreach ($years as $index => $ignored) {
                $value = (int) ($serie['values'][$index] ?? 0);
                $x = $xAt($index);
                $y = $yAt($value);
                $dots[] = ['x' => $x, 'y' => $y];
                $points[] = "$x,$y";
            }
            $lines[] = [
                'color' => $serie['color'],
                'label' => $serie['label'],
                'polyline' => implode(' ', $points),
                'dots' => $dots,
            ];
        }

        // Legenden-Positionen grob aus der Labellänge (SVG kennt keine
        // Textmessung); die Schätzung reicht für eine Handvoll Einträge.
        $legend = [];
        $x = self::PLOT_LEFT;
        foreach ($series as $serie) {
            $legend[] = ['color' => $serie['color'], 'label' => $serie['label'], 'x' => $x];
            $x += 22 + mb_strlen($serie['label']) * 7;
        }

        return new self($years, $lines, $gridLines, $xLabels, $legend, $max > 0);
    }

    /** Zeichenbar, sobald es mindestens ein Jahr und eine Serie gibt. */
    public function isDrawable(): bool
    {
        return $this->years !== [] && $this->lines !== [];
    }

    public function viewBox(): string
    {
        return '0 0 '.self::WIDTH.' '.self::HEIGHT;
    }

    public function width(): int
    {
        return self::WIDTH;
    }

    public function height(): int
    {
        return self::HEIGHT;
    }

    /**
     * Feste Layout-Koordinaten für die Blade-Komponente (damit dort keine
     * Zahlenliterale stehen müssen).
     *
     * @return array{left: int, right: int, legendSwatchY: int, legendTextY: int, xLabelY: int}
     */
    public function frame(): array
    {
        return [
            'left' => self::PLOT_LEFT,
            'right' => self::PLOT_RIGHT,
            'legendSwatchY' => self::LEGEND_SWATCH_Y,
            'legendTextY' => self::LEGEND_TEXT_Y,
            'xLabelY' => self::X_LABEL_Y,
        ];
    }

    /**
     * Ganzzahlige "schöne" Achsenskala: Schrittweite und Obergrenze so, dass
     * die Beschriftungen glatte Zahlen sind. Zählwerte sind ganzzahlig, daher
     * ist die Schrittweite mindestens 1.
     *
     * @return array{0: int, 1: int, 2: int} [niceMax, step, ticks]
     */
    private static function niceScale(int $max): array
    {
        $rough = $max / self::TARGET_TICKS;
        $magnitude = 10 ** (int) max(0, floor(log10(max(1, $rough))));
        $fraction = $rough / $magnitude;
        $niceFraction = match (true) {
            $fraction <= 1 => 1,
            $fraction <= 2 => 2,
            $fraction <= 5 => 5,
            default => 10,
        };

        $step = max(1, (int) round($niceFraction * $magnitude));
        $ticks = max(1, (int) ceil($max / $step));

        return [$step * $ticks, $step, $ticks];
    }
}
