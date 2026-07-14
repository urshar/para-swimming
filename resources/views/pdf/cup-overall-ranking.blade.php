@php use Illuminate\Support\Carbon; @endphp
    <!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Gesamtwertung — {{ $cup->name }}</title>
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

        td.rank, th.rank {
            width: 40px;
        }

        td.rounds, th.rounds {
            width: 60px;
            text-align: right;
        }

        td.points, th.points {
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
<h1>Gesamtwertung</h1>
<p class="subtitle">
    {{ $cup->name }} · beste {{ $cup->best_of_count }} Tageswertungen
    @if($calculatedAt)
        · berechnet am {{ Carbon::parse($calculatedAt)->format('d.m.Y H:i') }} Uhr
    @endif
</p>

@forelse($brackets as $bracket)
    <h2>
        {{ $bracket['gender'] === 'F' ? 'Damen' : 'Herren' }} — {{ $bracket['group']->name_de }}
        @if($bracket['ageGroup'])
            — {{ $bracket['ageGroup']->name_de }}
        @endif
    </h2>
    <table>
        <thead>
        <tr>
            <th class="rank">Rang</th>
            <th>Athlet</th>
            <th>Verein</th>
            <th>Sportklassen</th>
            @foreach($meets as $index => $meet)
                <th class="rounds">R.{{ $index + 1 }}</th>
            @endforeach
            <th class="points">Gesamtpunkte</th>
        </tr>
        </thead>
        <tbody>
        @foreach($bracket['results'] as $row)
            <tr>
                <td class="rank">{{ $row->rank }}</td>
                <td>{{ $row->athlete->last_name }}, {{ $row->athlete->first_name }}</td>
                <td>{{ $row->club?->display_name }}</td>
                <td>{{ $row->rounds->pluck('sport_class')->filter()->implode('/') ?: '—' }}</td>
                @foreach($row->rounds as $round)
                    <td class="rounds"
                        style="{{ $round['counted'] ? 'font-weight: bold; color: #047857;' : 'color: #999;' }}">
                        {{ $round['points'] ?? '—' }}
                    </td>
                @endforeach
                <td class="points">{{ $row->total_points }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@empty
    <p>Für diesen Cup wurde noch keine Gesamtwertung berechnet.</p>
@endforelse

<p class="footer">Para Swimming NatDB · erzeugt am {{ now()->format('d.m.Y H:i') }} Uhr</p>
</body>
</html>
