@php
    $systemLabel = $system === 'start' ? 'Startwertung' : 'Leistungswertung';
    $fmt = fn ($v) => number_format((float) $v, 2, ',', '.');
    $foreignLabel = $includeForeign ? 'einbezogen' : 'ausgeschlossen';
@endphp
    <!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Vereinswertung — {{ $cup->name }}</title>
    <style>
        * {
            box-sizing: border-box;
        }

        @page {
            margin: 22px 26px;
        }

        body {
            font-family: Helvetica, Arial, sans-serif;
            font-size: 10px;
            color: #1a1a1a;
        }

        h1 {
            font-size: 18px;
            margin: 0 0 4px 0;
        }

        .subtitle {
            font-size: 11px;
            color: #555;
            margin: 0 0 3px 0;
        }

        .meta {
            font-size: 9px;
            color: #777;
            margin: 0 0 16px 0;
        }

        .stale {
            font-size: 9px;
            color: #92400e;
            background-color: #fef3c7;
            border: 1px solid #fde68a;
            padding: 6px 8px;
            margin: 0 0 14px 0;
        }

        table {
            width: 100%;
            table-layout: fixed;
            border-collapse: collapse;
        }

        th, td {
            padding: 4px 6px;
            text-align: left;
            border-bottom: 1px solid #eee;
            overflow: hidden;
        }

        th {
            background-color: #fafafa;
            font-size: 9px;
            text-transform: uppercase;
            color: #666;
            border-bottom: 1px solid #ddd;
        }

        td.rank, th.rank {
            width: 34px;
            font-weight: bold;
            color: #555;
        }

        td.num, th.num {
            text-align: right;
        }

        td.club {
            font-weight: bold;
        }

        tr.club-row td {
            border-bottom: 1px solid #ddd;
        }

        .detail-cell {
            padding: 0 6px 6px 40px;
            border-bottom: 1px solid #eee;
        }

        table.athletes {
            width: 100%;
            margin: 2px 0 2px 0;
        }

        table.athletes th, table.athletes td {
            padding: 2px 5px;
            font-size: 9px;
            border-bottom: 1px dotted #eee;
        }

        table.athletes th {
            background-color: #fff;
            color: #999;
            text-transform: none;
        }

        .kader-tag {
            font-size: 8px;
            color: #4338ca;
            font-weight: bold;
        }

        .empty {
            font-size: 11px;
            color: #888;
            padding: 20px 0;
        }
    </style>
</head>
<body>
<h1>ÖBSV Cup — Vereinswertung</h1>
<p class="subtitle">{{ $cup->name }} · {{ $systemLabel }}</p>
<p class="meta">
    Ausländische Vereine: {{ $foreignLabel }}
    @if($system === 'performance')
        · Kaderathleten je Verein: {{ $kaderCount === 0 ? 'keine' : $kaderCount }}
        @if($calculatedAt)
            · Tageswertungen berechnet am {{ $calculatedAt->format('d.m.Y H:i') }} Uhr
        @endif
    @endif
    · Erstellt am {{ $generatedAt->format('d.m.Y H:i') }} Uhr
</p>

@if($system === 'performance' && $isStale)
    <p class="stale">{{ $staleReason }} Bitte die Tageswertung der betroffenen Meets neu berechnen.</p>
@endif

@if($ranking->isEmpty())
    <p class="empty">Keine {{ $systemLabel }} verfügbar.</p>
@elseif($system === 'start')
    <table>
        <thead>
        <tr>
            <th class="rank">Rang</th>
            <th>Verein</th>
            <th class="num">Starts</th>
            <th class="num">Athleten</th>
            <th class="num">Cup-Meets</th>
        </tr>
        </thead>
        <tbody>
        @foreach($ranking as $row)
            <tr class="club-row">
                <td class="rank">{{ $row->rank }}</td>
                <td class="club">{{ $row->clubName }}</td>
                <td class="num">{{ $row->starts }}</td>
                <td class="num">{{ $row->athletes }}</td>
                <td class="num">{{ $row->meets }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@else
    <table>
        <thead>
        <tr>
            <th class="rank">Rang</th>
            <th>Verein</th>
            <th class="num">Gesamtpunkte</th>
            <th class="num">gew. Athleten</th>
            <th class="num">gew. Meets</th>
        </tr>
        </thead>
        <tbody>
        @foreach($ranking as $row)
            <tr class="club-row">
                <td class="rank">{{ $row->rank }}</td>
                <td class="club">{{ $row->clubName }}</td>
                <td class="num">{{ $fmt($row->totalPoints) }}</td>
                <td class="num">{{ $row->countedAthletes }}</td>
                <td class="num">{{ $row->countedMeets }}</td>
            </tr>
            @if($detail)
                <tr>
                    <td class="detail-cell" colspan="5">
                        <table class="athletes">
                            <thead>
                            <tr>
                                <th style="width: 24px;">#</th>
                                <th>Athlet</th>
                                <th>Meet-Punkte</th>
                                <th class="num" style="width: 70px;">Saisonwert</th>
                                <th class="num" style="width: 55px;">Gewicht</th>
                                <th class="num" style="width: 60px;">Beitrag</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($row->athletes as $athlete)
                                <tr>
                                    <td>{{ $athlete->position }}</td>
                                    <td>
                                        {{ $athlete->athleteName }}
                                        @if($athlete->isKader)
                                            <span class="kader-tag">(Kader)</span>
                                        @endif
                                    </td>
                                    <td>{{ implode(' + ', $athlete->meetPoints) }}</td>
                                    <td class="num">{{ $athlete->seasonValue }}</td>
                                    <td class="num">{{ $fmt($athlete->weight) }}</td>
                                    <td class="num">{{ $fmt($athlete->weightedValue) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </td>
                </tr>
            @endif
        @endforeach
        </tbody>
    </table>
@endif
</body>
</html>
