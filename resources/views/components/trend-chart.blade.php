@props([
    'chart',        // App\Support\TrendChart
    'forPdf' => false,
    'title' => null,
    'emptyText' => 'Keine Daten im gewählten Zeitraum.',
])

{{--
    Mehrlinien-Jahresvergleich als reines SVG (Statistik-Modul). Ohne JavaScript,
    damit die Grafik am Bildschirm UND im PDF erscheint (dompdf führt kein JS aus).
    Sämtliche Koordinaten kommen fertig gerechnet aus App\Support\TrendChart — hier
    wird nur gezeichnet.

    dompdf kann ein SVG ohne ausdrückliche width/height nicht einordnen und rendert
    dann nichts; fürs PDF stehen deshalb feste Maße, am Bildschirm skaliert die
    viewBox.
--}}
@if(! $chart->isDrawable())
    <p class="text-sm text-zinc-400 dark:text-zinc-500 py-6 text-center">{{ $emptyText }}</p>
@else
    @php($frame = $chart->frame())
    @php($masse = $forPdf
        ? 'width="'.$chart->width().'" height="'.$chart->height().'"'
        : 'style="width:100%;height:auto"')

    <svg viewBox="{{ $chart->viewBox() }}" {!! $masse !!} role="img"
         aria-label="{{ $title ?? 'Jahresvergleich' }}">

        {{-- Legende --}}
        @foreach($chart->legend as $entry)
            <rect x="{{ $entry['x'] }}" y="{{ $frame['legendSwatchY'] }}" width="10" height="10"
                  rx="2" fill="{{ $entry['color'] }}"/>
            <text x="{{ $entry['x'] + 14 }}" y="{{ $frame['legendTextY'] }}"
                  font-size="10" fill="#3f3f46">{{ $entry['label'] }}</text>
        @endforeach

        {{-- Waagrechte Hilfslinien mit Werteskala --}}
        @foreach($chart->gridLines as $line)
            <line x1="{{ $frame['left'] }}" y1="{{ $line['y'] }}" x2="{{ $frame['right'] }}" y2="{{ $line['y'] }}"
                  stroke="#e4e4e7" stroke-width="1"/>
            <text x="{{ $frame['left'] - 6 }}" y="{{ $line['y'] + 3 }}"
                  text-anchor="end" font-size="9" fill="#71717a">{{ $line['label'] }}</text>
        @endforeach

        {{-- Verlaufslinien je Serie --}}
        @foreach($chart->lines as $serie)
            <polyline points="{{ $serie['polyline'] }}" fill="none"
                      stroke="{{ $serie['color'] }}" stroke-width="2"/>
            @foreach($serie['dots'] as $dot)
                <circle cx="{{ $dot['x'] }}" cy="{{ $dot['y'] }}" r="3"
                        fill="{{ $serie['color'] }}" stroke="#ffffff" stroke-width="1"/>
            @endforeach
        @endforeach

        {{-- Jahresachse --}}
        @foreach($chart->xLabels as $label)
            <text x="{{ $label['x'] }}" y="{{ $frame['xLabelY'] }}"
                  text-anchor="middle" font-size="9" fill="#71717a">{{ $label['label'] }}</text>
        @endforeach
    </svg>
@endif
