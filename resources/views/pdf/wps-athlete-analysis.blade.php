@php
    use App\Support\TimeParser;
    use Illuminate\Support\Carbon;

    $athlet = $profile->athlete;
@endphp
    <!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Athletenanalyse — {{ $athlet->full_name }}</title>
    <style>
        * { box-sizing: border-box; }
        @page { margin: 20px 25px; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 10px; color: #1a1a1a; }
        h1 { font-size: 18px; margin: 0 0 4px 0; }
        .meta { font-size: 9px; color: #777; margin: 0 0 14px 0; }
        h2 { width: 100%; font-size: 12px; margin: 14px 0 5px 0; padding: 5px 8px;
             background-color: #f0f0f0; border: 1px solid #ddd; }
        table { width: 100%; table-layout: fixed; border-collapse: collapse; }
        th { text-align: left; font-size: 9px; text-transform: uppercase; color: #555;
             border-bottom: 1px solid #bbb; padding: 4px 5px; }
        td { padding: 3px 5px; border-bottom: 1px solid #eee; }
        .num { font-family: 'DejaVu Sans Mono', monospace; }
        .right { text-align: right; }
        .muted { color: #888; }
        .up { color: #1f7a34; }
        .est { color: #a06a10; }
        .note { margin: 0 0 14px 0; padding: 8px 10px; border: 1px solid #d9a441;
                background-color: #fdf6e6; font-size: 9px; line-height: 1.4; }
        .chart { margin: 4px 0 8px 0; width: 100%; }
        .note-row td { background-color: #f7f7f7; font-size: 9px; color: #555; }
        .foot { margin-top: 14px; font-size: 8px; color: #999; }
    </style>
</head>
<body>

<h1>{{ $athlet->full_name }}</h1>
<p class="meta">
    @if($athlet->birth_date)
        Jg. {{ $athlet->birth_date->format('Y') }} ·
    @endif
    {{ $athlet->club?->display_name }}
    @if($profile->firstYear !== null)
        · Zeitraum {{ $profile->firstYear }}–{{ $profile->lastYear }}
    @endif
    · Beste Punktzahl {{ $profile->bestPoints() }}
    · Stand {{ $generatedAt->format('d.m.Y H:i') }}
</p>

@if($profile->hasClassChange())
    <div class="note">
        <strong>Sportklasse gewechselt.</strong>
        @foreach($profile->changedCategories() as $kategorie => $klassen)
            {{ $kategorie }}: {{ implode(' → ', $klassen) }}.
        @endforeach
        Punkte aus verschiedenen Klassen sind nur eingeschränkt vergleichbar; die Differenz zur
        Vorsaison entfällt an der Stelle des Wechsels.
    </div>
@endif

@foreach($profile->byEvent as $bewerb => $zeilen)
    <h2>{{ $bewerb }} — beste Punktzahl {{ $zeilen->max(fn ($z) => $z->points) }}</h2>

    {{-- Reines SVG, deshalb auch im PDF darstellbar: dompdf führt keine Skripte aus. --}}
    @if(isset($charts[$bewerb]) && $charts[$bewerb]->isDrawable())
        <div class="chart">
            <x-wps-chart :series="$charts[$bewerb]"/>
        </div>
    @endif

    <table>
        <thead>
        <tr>
            <th style="width: 8%;">Saison</th>
            <th style="width: 8%;">Klasse</th>
            <th style="width: 14%;">Zeit</th>
            <th style="width: 13%;">gesch. LCM</th>
            <th style="width: 9%;" class="right">Punkte</th>
            <th style="width: 18%;" class="right">Δ Vorsaison</th>
            <th style="width: 30%;">Wettkampf</th>
        </tr>
        </thead>
        <tbody>
        @foreach($zeilen as $zeile)
            <tr>
                <td class="num">{{ $zeile->year }}</td>
                <td class="num">{{ $zeile->sportClass }}</td>
                <td class="num">{{ TimeParser::display($zeile->swimTime) }} {{ $zeile->course }}</td>
                <td class="num est">
                    @if($zeile->estimatedLcmTime !== null)
                        {{ TimeParser::display($zeile->estimatedLcmTime) }}
                    @endif
                </td>
                <td class="num right">{{ $zeile->points }}</td>
                <td class="num right">
                    @if($zeile->classChanged)
                        <span class="est">Klassenwechsel</span>
                    @elseif($zeile->hasComparison())
                        @if($zeile->improved())
                            <span class="up">{{ $zeile->formattedPointsDelta() }}</span>
                        @else
                            <span class="muted">{{ $zeile->formattedPointsDelta() }}</span>
                        @endif
                        <span class="muted">({{ $zeile->formattedTimeDelta() }})</span>
                    @else
                        <span class="muted">–</span>
                    @endif
                </td>
                <td>
                    {{ $zeile->meetName }}
                    @if($zeile->meetDate)
                        ({{ Carbon::parse($zeile->meetDate)->format('d.m.Y') }})
                    @endif
                </td>
            </tr>

            @foreach($notesByResult[$zeile->resultId] ?? [] as $notiz)
                <tr class="note-row">
                    <td colspan="7">
                        <strong>{{ $notiz->categoryLabel() }}:</strong> {{ $notiz->note }}
                    </td>
                </tr>
            @endforeach
        @endforeach
        </tbody>
    </table>
@endforeach

@if($profile->isEmpty())
    <p class="muted">Für diesen Athleten liegen im gewählten Zeitraum keine gewerteten Leistungen vor.</p>
@endif

@php($allgemein = $notes->filter(fn ($n): bool => $n->getAttribute('result_id') === null))

@if($allgemein->isNotEmpty())
    <h2>Notizen ohne Startbezug</h2>
    <table>
        <tbody>
        @foreach($allgemein as $notiz)
            <tr>
                <td style="width: 15%;" class="num">{{ $notiz->noted_on->format('d.m.Y') }}</td>
                <td style="width: 20%;">{{ $notiz->categoryLabel() }}</td>
                <td style="width: 65%;">{{ $notiz->note }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

<p class="foot">Para Swimming NatDB · erzeugt am {{ $generatedAt->format('d.m.Y H:i') }}</p>

</body>
</html>
