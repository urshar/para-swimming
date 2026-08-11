@php
    use App\Support\TimeParser;
    use Illuminate\Support\Carbon;
@endphp
    <!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Qualifikanten — {{ $championship->display_name }}</title>
    <style>
        * { box-sizing: border-box; }
        @page { margin: 20px 25px; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 10px; color: #1a1a1a; }
        h1 { font-size: 18px; margin: 0 0 4px 0; }
        .subtitle { font-size: 11px; color: #555; margin: 0 0 4px 0; }
        .meta { font-size: 9px; color: #777; margin: 0 0 18px 0; }
        h2 { width: 100%; font-size: 13px; margin: 18px 0 6px 0; padding: 6px 8px;
             background-color: #f0f0f0; border: 1px solid #ddd; }
        h3 { font-size: 11px; margin: 12px 0 3px 0; color: #333; }
        .athlete-meta { font-size: 9px; color: #777; margin: 0 0 4px 0; }
        table { width: 100%; table-layout: fixed; border-collapse: collapse; }
        th { text-align: left; font-size: 9px; text-transform: uppercase; color: #555;
             border-bottom: 1px solid #bbb; padding: 4px 5px; }
        td { padding: 3px 5px; border-bottom: 1px solid #eee; }
        .num { font-family: 'DejaVu Sans Mono', monospace; }
        .muted { color: #888; }
        .miss { color: #b03030; }
        .filter { margin: 0 0 14px 0; padding: 5px 8px; border: 1px solid #ccc;
                  background-color: #f7f7f7; font-size: 9px; color: #444; }
        .foot { margin-top: 14px; font-size: 8px; color: #999; }
    </style>
</head>
<body>

<h1>Qualifikanten</h1>
<p class="subtitle">{{ $championship->display_name }}</p>
<p class="meta">
    Qualifikationszeitraum {{ $championship->qualification_start->format('d.m.Y') }}
    bis {{ $championship->qualification_end->format('d.m.Y') }} ·
    Normen auf {{ $championship->course }} ·
    Kaderzuordnung zum Stichtag {{ Carbon::parse($kaderReferenceDate)->format('d.m.Y') }} ·
    Stand {{ $generatedAt->format('d.m.Y H:i') }}
</p>
<p class="meta">
    Ausschließlich reale Zeiten auf {{ $championship->course }} aus WPS-anerkannten Wettkämpfen.
    Bewerbe ohne ausgeschriebene Norm sind nicht enthalten.
</p>

@if($filter->describe())
    {{-- Ohne diesen Satz sähe ein gefiltertes Blatt aus wie der vollständige Stand. --}}
    <p class="filter">Gefiltert: {{ $filter->describe() }}</p>
@endif

@foreach($groups as $kaderName => $athleten)
    <h2>{{ $kaderName }}</h2>

    @foreach($athleten as $eintrag)
        <h3>{{ $eintrag->athlete->full_name }}</h3>
        <p class="athlete-meta">
            {{ $eintrag->athlete->gender === 'M' ? 'männlich' : 'weiblich' }}
            · {{ $eintrag->displaySportClass() }}
            @if($eintrag->ageLabel($championship->year)) · {{ $eintrag->ageLabel($championship->year) }} @endif
            · {{ $eintrag->athlete->club?->display_name }}
            · {{ $eintrag->mqsCount() }} × MQS, {{ $eintrag->metCount() }} × MET,
            {{ $eintrag->openCount() }} offen
        </p>

        <table>
            <thead>
            <tr>
                <th style="width: 24%;">Bewerb</th>
                <th style="width: 7%;">Platz</th>
                <th style="width: 11%;">Zeit</th>
                <th style="width: 8%;">Punkte</th>
                <th style="width: 28%;">Wettkampf</th>
                <th style="width: 22%;">Norm</th>
            </tr>
            </thead>
            <tbody>
            @foreach($filter->visibleRows($eintrag) as $zeile)
                <tr>
                    <td>{{ $zeile->eventLabel }} {{ $zeile->sportClass }}</td>
                    <td class="num">{{ $zeile->bestEntry()?->place ?? '–' }}</td>
                    <td class="num">{{ TimeParser::display($zeile->status->swimTime) }}</td>
                    <td class="num">{{ $zeile->points() ?? '' }}</td>
                    <td>
                        {{ $zeile->status->meetName }}
                        @if($zeile->status->meetDate)
                            ({{ Carbon::parse($zeile->status->meetDate)->format('d.m.Y') }})
                        @endif
                    </td>
                    <td>
                        @if($zeile->status->isProof())
                            {{ $zeile->status->label() }}
                        @else
                            <span class="miss">{{ $zeile->status->label() }}</span>
                        @endif
                        @if($zeile->status->formattedGap())
                            <span class="num">{{ $zeile->status->formattedGap() }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endforeach
@endforeach

@if($groups->isEmpty())
    <p class="muted">Keine Athleten mit Ergebnissen in Bewerben, für die eine Norm ausgeschrieben ist.</p>
@endif

<p class="foot">Para Swimming NatDB · erzeugt am {{ $generatedAt->format('d.m.Y H:i') }}</p>

</body>
</html>
