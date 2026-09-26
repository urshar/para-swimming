<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Jahresvergleich — ÖBSV Para Swimming</title>
    <style>
        /*
         * PDF-Fassung des Jahresvergleichs. Eigenständiges HTML/CSS für dompdf —
         * kein Tailwind, kein Flux. Die Diagramme kommen als statisches SVG aus
         * der geteilten Komponente x-trend-chart (forPdf), die Zahlen als Tabelle.
         */
        @page { margin: 70px 25px 55px 25px; }

        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1a1a1a; margin: 0; }

        .page-header {
            position: fixed; top: -55px; left: 0; right: 0; height: 40px;
            border-bottom: 1px solid #ddd; padding-bottom: 4px;
        }
        .page-header .title { font-size: 13px; font-weight: bold; }
        .page-header .sub { font-size: 8px; color: #666; margin-top: 2px; }

        .page-footer {
            position: fixed; bottom: -40px; left: 0; right: 0; height: 30px;
            border-top: 1px solid #ddd; padding-top: 4px; font-size: 8px; color: #666;
        }
        /*
         * Fußzeile über eine Tabelle statt float: In dompdf verschiebt ein float
         * innerhalb eines position:fixed-Elements umgebrochene Tabellenzellen an
         * anderer Stelle im Dokument nach rechts. Siehe pdf/statistics-report.
         */
        .page-footer table { width: 100%; border-collapse: collapse; margin: 0; }
        .page-footer td { border: 0; padding: 0; font-size: 8px; color: #666; }
        .page-footer .left { text-align: left; }
        .page-footer .right { text-align: right; }
        .page-numbering:before { content: "Seite " counter(page); }

        h2 { font-size: 12px; margin: 14px 0 4px; }
        .chart { page-break-inside: avoid; margin-bottom: 18px; }
        .empty { color: #999; font-style: italic; }

        table { border-collapse: collapse; width: 100%; margin-top: 6px; }
        th, td { border: 1px solid #ddd; padding: 3px 5px; font-size: 9px; }
        th { background: #efefef; text-align: right; }
        th.lbl, td.lbl { text-align: left; }
        td.num { text-align: right; }
    </style>
</head>
<body>

<div class="page-header">
    <div class="title">Jahresvergleich — ÖBSV Para Swimming</div>
    <div class="sub">{{ $year - $span + 1 }}–{{ $year }} ({{ $span }} Jahre) · erzeugt am {{ now()->format('d.m.Y H:i') }} Uhr</div>
</div>

<div class="page-footer">
    <table>
        <tr>
            <td class="left">Para Swimming NatDB</td>
            <td class="right page-numbering"></td>
        </tr>
    </table>
</div>

@php
    // Farb-Slot → Hex fürs SVG (Pendant zur Tailwind-Zuordnung im Bildschirm-Partial).
    $hex = [
        'blue' => '#3b82f6', 'pink' => '#ec4899', 'amber' => '#f59e0b', 'violet' => '#8b5cf6',
        'emerald' => '#10b981', 'sky' => '#0ea5e9', 'zinc' => '#71717a', 'red' => '#ef4444',
        'orange' => '#f97316', 'rose' => '#f43f5e',
    ];
@endphp

@foreach($charts as $chart)
    <div class="chart">
        <h2>{{ $chart['title'] }}</h2>

        @if(! $chart['hasData'])
            <p class="empty">Für diesen Zeitraum liegen keine Daten vor.</p>
        @else
            @php
                $svgSeries = array_map(fn (array $s): array => [
                    'label' => $s['label'],
                    'color' => $hex[$s['color']] ?? '#71717a',
                    'values' => $s['values'],
                ], $chart['series']);
                $svg = \App\Support\TrendChart::fromSeries($chart['years'], $svgSeries);
            @endphp

            <x-trend-chart :chart="$svg" :for-pdf="true" title="{{ $chart['title'] }}"/>

            <table>
                <thead>
                    <tr>
                        <th class="lbl">Serie</th>
                        @foreach($chart['years'] as $y)
                            <th>{{ $y }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($chart['series'] as $s)
                        <tr>
                            <td class="lbl">{{ $s['label'] }}</td>
                            @foreach($s['values'] as $v)
                                <td class="num">{{ $v }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endforeach

</body>
</html>
