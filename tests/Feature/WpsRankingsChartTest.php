<?php

use App\Models\AthletePerformanceNote;
use App\Support\WpsAthleteSeasonEntry;
use App\Support\WpsChartPoint;
use App\Support\WpsChartSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('wps-rankings-chart');

// ── Helper (Suffix _wc gegen Namenskollisionen) ──────────────────────────────

function zeile_wc(string $datum, int $punkte, string $sportClass, bool $klassenwechsel): WpsAthleteSeasonEntry
{
    return new WpsAthleteSeasonEntry(
        (int) substr($datum, 0, 4),
        '100 m Freistil',
        $sportClass,
        7000,
        'SCM',
        null,
        $punkte,
        'estimated',
        'Meeting',
        $datum,
        null,
        null,
        $klassenwechsel,
        null,
    );
}

beforeEach(function () {
    $this->service = app(App\Services\WpsChartService::class);
});

// ── Zeichenbarkeit ───────────────────────────────────────────────────────────

it('zeichnet erst ab zwei Datenpunkten', function () {
    $einer = $this->service->series('100 m Freistil', collect([
        zeile_wc('2025-03-01', 700, 'S9', false),
    ]), collect());

    $zwei = $this->service->series('100 m Freistil', collect([
        zeile_wc('2025-03-01', 700, 'S9', false),
        zeile_wc('2025-06-01', 720, 'S9', false),
    ]), collect());

    // Aus einem einzelnen Wert lässt sich keine Entwicklung ablesen, und eine Linie mit einem
    // Punkt sähe nach einem Fehler aus.
    expect($einer->isDrawable())->toBeFalse()
        ->and($zwei->isDrawable())->toBeTrue()
        ->and($zwei->points)->toHaveCount(2);
});

it('übergeht Zeilen ohne Wettkampfdatum', function () {
    $ohneDatum = new WpsAthleteSeasonEntry(
        2025, '100 m Freistil', 'S9', 7000, 'SCM', null, 700, 'estimated', 'Meeting',
        null, null, null, false, null,
    );

    $serie = $this->service->series('100 m Freistil', collect([
        zeile_wc('2025-03-01', 700, 'S9', false),
        $ohneDatum,
    ]), collect());

    expect($serie->isDrawable())->toBeFalse();
});

// ── Achsen ───────────────────────────────────────────────────────────────────

it('legt mehr Punkte weiter oben ab', function () {
    $serie = $this->service->series('100 m Freistil', collect([
        zeile_wc('2025-03-01', 600, 'S9', false),
        zeile_wc('2025-06-01', 800, 'S9', false),
    ]), collect());

    $schwach = $serie->points->first();
    $stark = $serie->points->last();

    // In SVG wächst y nach unten; mehr Punkte müssen deshalb einen kleineren Wert ergeben.
    expect($stark->y)->toBeLessThan($schwach->y)
        ->and($stark->x)->toBeGreaterThan($schwach->x);
});

it('spreizt eine kleine Schwankung nicht über die volle Höhe', function () {
    $serie = $this->service->series('100 m Freistil', collect([
        zeile_wc('2025-03-01', 700, 'S9', false),
        zeile_wc('2025-06-01', 703, 'S9', false),
    ]), collect());

    // Ohne Mindestspanne sähe ein Unterschied von drei Punkten nach einem dramatischen
    // Verlauf aus.
    expect($serie->maxPoints - $serie->minPoints)->toBeGreaterThanOrEqual(50);
});

it('rundet die Achsengrenzen auf glatte Werte und nie unter null', function () {
    $serie = $this->service->series('100 m Freistil', collect([
        zeile_wc('2025-03-01', 5, 'S9', false),
        zeile_wc('2025-06-01', 12, 'S9', false),
    ]), collect());

    expect($serie->minPoints)->toBe(0)
        ->and($serie->maxPoints % 10)->toBe(0);
});

it('beschriftet die Zeitachse und dünnt bei vielen Starts aus', function () {
    $zeilen = collect();

    foreach (range(1, 20) as $nummer) {
        $monat = str_pad((string) (($nummer % 12) + 1), 2, '0', STR_PAD_LEFT);
        $jahr = 2024 + intdiv($nummer, 12);
        $zeilen->push(zeile_wc("$jahr-$monat-01", 700 + $nummer, 'S9', false));
    }

    $serie = $this->service->series('100 m Freistil', $zeilen, collect());

    // Überlappende Beschriftungen sind unlesbar und dann schlechter als keine.
    expect(count($serie->xLabels))->toBeLessThanOrEqual(9)
        ->and(count($serie->gridLines))->toBe(5);
});

// ── Markierungen ─────────────────────────────────────────────────────────────

it('markiert einen Klassenwechsel', function () {
    $serie = $this->service->series('100 m Freistil', collect([
        zeile_wc('2025-03-01', 700, 'S9', false),
        zeile_wc('2025-06-01', 850, 'S8', true),
    ]), collect());

    // Die Kurve macht dort einen Sprung, der keine Leistungsentwicklung ist.
    expect($serie->markers)->toHaveCount(1)
        ->and($serie->markers[0]['label'])->toContain('Klassenwechsel')
        ->and($serie->points->last()->classChanged)->toBeTrue();
});

it('markiert Notizen an ihrem Datum', function () {
    $notiz = new AthletePerformanceNote([
        'noted_on' => '2025-04-15',
        'category' => AthletePerformanceNote::CATEGORY_INJURY,
        'note' => 'Schulter',
    ]);

    $serie = $this->service->series('100 m Freistil', collect([
        zeile_wc('2025-03-01', 700, 'S9', false),
        zeile_wc('2025-06-01', 650, 'S9', false),
    ]), collect([$notiz]));

    expect($serie->markers)->toHaveCount(1)
        ->and($serie->markers[0]['label'])->toBe('Verletzung');
});

it('lässt Notizen außerhalb des Zeitraums weg', function () {
    $davor = new AthletePerformanceNote([
        'noted_on' => '2020-01-01',
        'category' => AthletePerformanceNote::CATEGORY_INJURY,
        'note' => 'Lange her',
    ]);

    $serie = $this->service->series('100 m Freistil', collect([
        zeile_wc('2025-03-01', 700, 'S9', false),
        zeile_wc('2025-06-01', 720, 'S9', false),
    ]), collect([$davor]));

    // Eine Markierung am Rand behauptete einen Bezug, den es nicht gibt.
    expect($serie->markers)->toBeEmpty();
});

// ── Ausgabe ──────────────────────────────────────────────────────────────────

it('setzt die Polylinie aus den Punkten zusammen', function () {
    $serie = $this->service->series('100 m Freistil', collect([
        zeile_wc('2025-03-01', 700, 'S9', false),
        zeile_wc('2025-06-01', 720, 'S9', false),
    ]), collect());

    expect($serie->polyline())->toMatch('/^[\d.]+,[\d.]+ [\d.]+,[\d.]+$/')
        ->and($serie->viewBox())->toBe('0 0 '.WpsChartSeries::WIDTH.' '.WpsChartSeries::HEIGHT);
});

it('liefert die Zeichenfläche als einfache Werte fürs Markup', function () {
    $serie = $this->service->series('100 m Freistil', collect([
        zeile_wc('2025-03-01', 700, 'S9', false),
        zeile_wc('2025-06-01', 720, 'S9', false),
    ]), collect());

    $rahmen = $serie->frame();

    // Damit im Blade keine vollqualifizierten Klassennamen für Konstanten stehen müssen.
    expect($rahmen)->toHaveKeys(['left', 'right', 'top', 'bottom', 'labelX', 'axisY'])
        ->and($rahmen['right'])->toBeGreaterThan($rahmen['left'])
        ->and($rahmen['bottom'])->toBeGreaterThan($rahmen['top']);
});

it('beschreibt jeden Punkt für den Hinweistext', function () {
    $serie = $this->service->series('100 m Freistil', collect([
        zeile_wc('2025-03-01', 700, 'S9', false),
        zeile_wc('2025-06-01', 720, 'S9', false),
    ]), collect());

    $punkt = $serie->points->first();

    expect($punkt)->toBeInstanceOf(WpsChartPoint::class)
        ->and($punkt->tooltip())->toContain('01.03.2025')
        ->and($punkt->tooltip())->toContain('700 Punkte')
        ->and($punkt->tooltip())->toContain('S9');
});
