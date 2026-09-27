<?php

use App\Support\TrendChart;
use Illuminate\Support\Facades\Blade;

uses()->group('statistics-multi-year-chart');

function trendChart_sample(): TrendChart
{
    return TrendChart::fromSeries(
        [2022, 2023, 2024],
        [
            ['label' => 'Herren', 'color' => '#3b82f6', 'values' => [10, 20, 15]],
            ['label' => 'Damen', 'color' => '#ec4899', 'values' => [5, 8, 12]],
        ],
    );
}

it('rendert ein SVG mit Linien, Farben, Legende und Jahresachse', function () {
    $html = Blade::render('<x-trend-chart :chart="$chart" />', ['chart' => trendChart_sample()]);

    expect($html)->toContain('<svg')
        ->toContain('<polyline')
        ->toContain('#3b82f6')     // Herren-Farbe
        ->toContain('#ec4899')     // Damen-Farbe
        ->toContain('Herren')      // Legende
        ->toContain('2024')        // Jahresbeschriftung
        ->toContain('style="width:100%');  // Bildschirm: skaliert über viewBox
});

it('setzt fürs PDF feste width/height statt der Bildschirm-Skalierung', function () {
    $html = Blade::render('<x-trend-chart :chart="$chart" :for-pdf="true" />', ['chart' => trendChart_sample()]);

    expect($html)->toContain('width="640"')
        ->toContain('height="340"')
        ->not->toContain('style="width:100%');
});

it('zeigt statt eines leeren SVG einen Hinweis, wenn nichts zu zeichnen ist', function () {
    $chart = TrendChart::fromSeries([2022, 2023], []);

    $html = Blade::render(
        '<x-trend-chart :chart="$chart" empty-text="Noch keine Daten" />',
        ['chart' => $chart],
    );

    expect($html)->toContain('Noch keine Daten')
        ->not->toContain('<svg');
});
