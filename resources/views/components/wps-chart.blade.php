@props(['series'])

{{--
    Verlaufsgrafik eines Bewerbs (Spec "WPS Rankings" §7.6).

    Reines SVG ohne JavaScript: Die Grafik erscheint so auch im PDF, denn dompdf führt keine
    Skripte aus. Sämtliche Koordinaten kommen fertig gerechnet aus WpsChartService — eine
    Achsenskalierung im Markup wäre weder lesbar noch prüfbar.
--}}
@if($series->isDrawable())
    @php($rahmen = $series->frame())
    <svg viewBox="{{ $series->viewBox() }}" class="w-full h-auto" role="img"
         aria-label="{{ $series->axisLabel() }}verlauf {{ $series->eventLabel }}">

        {{-- Waagrechte Hilfslinien mit Punktwerten --}}
        @foreach($series->gridLines as $linie)
            <line x1="{{ $rahmen['left'] }}" y1="{{ $linie['y'] }}"
                  x2="{{ $rahmen['right'] }}"
                  y2="{{ $linie['y'] }}"
                  stroke="#d4d4d8" stroke-width="0.5"/>
            <text x="{{ $rahmen['labelX'] }}" y="{{ $linie['y'] + 3 }}"
                  text-anchor="end" font-size="9" fill="#71717a">{{ $linie['label'] }}</text>
        @endforeach

        {{-- Senkrechte Markierungen: Klassenwechsel und Notizen --}}
        @foreach($series->markers as $markierung)
            <line x1="{{ $markierung['x'] }}" y1="{{ $rahmen['top'] }}"
                  x2="{{ $markierung['x'] }}"
                  y2="{{ $rahmen['bottom'] }}"
                  stroke="#d9a441" stroke-width="1" stroke-dasharray="3 3"/>
        @endforeach

        {{-- Die Verlaufslinie --}}
        <polyline points="{{ $series->polyline() }}" fill="none" stroke="#2563eb" stroke-width="2"/>

        {{-- Datenpunkte; ein Klassenwechsel wird hervorgehoben, weil die Kurve dort einen
             Sprung macht, der keine Leistungsentwicklung ist. --}}
        @foreach($series->points as $punkt)
            @php($farbe = $punkt->classChanged ? '#d9a441' : '#2563eb')
            @php($radius = $punkt->classChanged ? 4 : 3)

            <circle cx="{{ $punkt->x }}" cy="{{ $punkt->y }}" r="{{ $radius }}"
                    fill="{{ $farbe }}" stroke="#ffffff" stroke-width="1">
                <title>{{ $punkt->tooltip() }}</title>
            </circle>
        @endforeach

        {{-- Zeitachse --}}
        @foreach($series->xLabels as $beschriftung)
            <text x="{{ $beschriftung['x'] }}"
                  y="{{ $rahmen['axisY'] }}"
                  text-anchor="middle" font-size="9" fill="#71717a">{{ $beschriftung['label'] }}</text>
        @endforeach
    </svg>
@endif
