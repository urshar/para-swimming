<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Richtzeiten {{ $list->year }}</title>
    <style>
        @page {
            margin: 25px 30px;
        }

        body {
            font-family: Helvetica, Arial, sans-serif;
            font-size: 11px;
            color: #1a1a1a;
        }

        h1 {
            font-size: 18px;
            margin: 0 0 4px 0;
        }

        .subtitle {
            font-size: 11px;
            color: #555;
            margin: 0 0 20px 0;
        }

        h2 {
            font-size: 13px;
            margin: 18px 0 6px 0;
            padding: 6px 8px;
            background-color: #f0f0f0;
            border: 1px solid #ddd;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 4px;
        }

        th, td {
            padding: 4px 8px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        th {
            background-color: #fafafa;
            font-size: 10px;
            text-transform: uppercase;
            color: #666;
        }

        td.time, th.time {
            width: 90px;
            text-align: right;
            font-family: Courier, monospace;
        }

        .footer {
            margin-top: 24px;
            font-size: 9px;
            color: #999;
        }
    </style>
</head>
<body>
<h1>Richtzeiten ÖSTM & ÖM {{ $list->year }}</h1>
<p class="subtitle">
    {{ $list->isLatest() ? 'Aktuell' : 'Historisiert' }}
    @if($list->targetPoints->isNotEmpty())
        · Zielpunkte-Overrides:
        {{ $list->targetPoints->sortBy('sport_class')->map(fn ($tp) => "$tp->sport_class: $tp->points Pkt.")->implode(', ') }}
    @endif
</p>

<table>
    <thead>
    <tr>
        <th>Bewerb</th>
        <th>Geschlecht</th>
        <th>Sportklasse</th>
        <th class="time">Richtzeit</th>
    </tr>
    </thead>
    <tbody>
    @forelse($list->times->sortBy(['distance', 'gender', 'sport_class']) as $time)
        <tr>
            <td>{{ $time->distance }}m {{ $time->strokeType?->name_de }}</td>
            <td>{{ $time->gender }}</td>
            <td>{{ $time->sport_class }}</td>
            <td class="time">{{ $time->formatted_value ?? '–' }}</td>
        </tr>
    @empty
        <tr>
            <td colspan="4">Keine Richtzeiten hinterlegt.</td>
        </tr>
    @endforelse
    </tbody>
</table>

<p class="footer">
    Para Swimming NatDB — ÖBSV — erstellt am {{ now()->format('d.m.Y H:i') }} Uhr
</p>
</body>
</html>
