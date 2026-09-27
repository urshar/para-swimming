<?php

use App\Support\TrendChart;

uses()->group('statistics-multi-year-chart');

it('ist zeichenbar mit Jahren und Serien und erkennt vorhandene Daten', function () {
    $chart = TrendChart::fromSeries(
        [2022, 2023, 2024],
        [['label' => 'Herren', 'color' => '#3b82f6', 'values' => [10, 20, 15]]],
    );

    expect($chart->isDrawable())->toBeTrue()
        ->and($chart->hasData)->toBeTrue()
        ->and($chart->lines)->toHaveCount(1)
        ->and($chart->lines[0]['dots'])->toHaveCount(3)
        ->and($chart->lines[0]['color'])->toBe('#3b82f6')
        ->and($chart->legend)->toHaveCount(1)
        ->and($chart->legend[0]['label'])->toBe('Herren');
});

it('ist nicht zeichenbar ohne Serien', function () {
    $chart = TrendChart::fromSeries([2022, 2023], []);

    expect($chart->isDrawable())->toBeFalse();
});

it('meldet keine Daten, wenn alle Werte 0 sind, bleibt aber zeichenbar', function () {
    $chart = TrendChart::fromSeries(
        [2022, 2023],
        [['label' => 'Damen', 'color' => '#ec4899', 'values' => [0, 0]]],
    );

    expect($chart->isDrawable())->toBeTrue()
        ->and($chart->hasData)->toBeFalse();
});

it('beschriftet die x-Achse mit den Jahren', function () {
    $chart = TrendChart::fromSeries(
        [2020, 2021, 2022],
        [['label' => 'A', 'color' => '#000000', 'values' => [1, 2, 3]]],
    );

    expect(array_column($chart->xLabels, 'label'))->toBe(['2020', '2021', '2022']);
});

it('wählt eine ganzzahlige, den Maximalwert abdeckende Werteskala', function () {
    $chart = TrendChart::fromSeries(
        [2023, 2024],
        [['label' => 'A', 'color' => '#000000', 'values' => [0, 12600]]],
    );

    $labels = array_map('intval', array_column($chart->gridLines, 'label'));

    expect($labels[0])->toBe(0)                    // Skala beginnt bei 0
        ->and(max($labels))->toBeGreaterThanOrEqual(12600); // und deckt das Maximum ab
});

it('zentriert einen einzelnen Jahres-Datenpunkt', function () {
    $chart = TrendChart::fromSeries(
        [2024],
        [['label' => 'A', 'color' => '#000000', 'values' => [5]]],
    );

    // Bei nur einem Jahr liegt der Punkt mittig zwischen linkem und rechtem Rand.
    $frame = $chart->frame();
    $expected = $frame['left'] + intdiv($frame['right'] - $frame['left'], 2);

    expect($chart->lines[0]['dots'][0]['x'])->toBe($expected)
        ->and($chart->xLabels[0]['x'])->toBe($expected);
});
