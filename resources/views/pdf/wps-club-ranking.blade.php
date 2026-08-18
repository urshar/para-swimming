@php
    use App\Support\WpsClubRankingConfiguration;

    $istDurchschnitt = $config->method === WpsClubRankingConfiguration::METHOD_AVERAGE;
@endphp
    <!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>WPS-Vereinsauswertung</title>
    <style>
        * { box-sizing: border-box; }
        @page { margin: 20px 25px; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 10px; color: #1a1a1a; }
        h1 { font-size: 18px; margin: 0 0 4px 0; }
        .meta { font-size: 9px; color: #777; margin: 0 0 12px 0; }
        h2 { width: 100%; font-size: 12px; margin: 16px 0 5px 0; padding: 5px 8px;
             background-color: #f0f0f0; border: 1px solid #ddd; }
        table { width: 100%; table-layout: fixed; border-collapse: collapse; }
        th { text-align: left; font-size: 9px; text-transform: uppercase; color: #555;
             border-bottom: 1px solid #bbb; padding: 4px 5px; }
        td { padding: 3px 5px; border-bottom: 1px solid #eee; }
        .num { font-family: 'DejaVu Sans Mono', monospace; }
        .right { text-align: right; }
        .muted { color: #888; }
        .note { margin: 0 0 14px 0; padding: 8px 10px; border: 1px solid #d9a441;
                background-color: #fdf6e6; font-size: 9px; line-height: 1.4; }
        .foot { margin-top: 14px; font-size: 8px; color: #999; }
    </style>
</head>
<body>

<h1>WPS-Vereinsauswertung</h1>
<p class="meta">
    {{ $config->describe() }} · {{ implode(' · ', $filter->describe()) }} ·
    Stand {{ $generatedAt->format('d.m.Y H:i') }}
</p>

{{-- Die Abgrenzung nach §9 gehört ins PDF, nicht nur auf den Bildschirm: Ein weitergegebenes
     Blatt ohne diesen Hinweis könnte für eine offizielle Wertung gehalten werden. --}}
<div class="note">
    <strong>Analysewerkzeug, keine offizielle Wertung.</strong><br>
    Die offizielle ÖBSV-Vereinswertung ist die Cup-Wertung. Diese Auswertung dient der
    Einschätzung; ihre Rechenweise ist wählbar, und je nach Methode ergeben sich unterschiedliche
    Reihenfolgen.
</div>

<table>
    <thead>
    <tr>
        <th style="width: 8%;">Rang</th>
        <th style="width: 42%;">Verein</th>
        <th style="width: 18%;" class="right">
            @if($config->countsEntries()) Leistungen @else Wert @endif
        </th>
        <th style="width: 16%;" class="right">Athleten</th>
        <th style="width: 16%;" class="right">gewertet</th>
    </tr>
    </thead>
    <tbody>
    @forelse($ranked as $eintrag)
        <tr>
            <td class="num">{{ $eintrag->rank }}</td>
            <td>{{ $eintrag->club->display_name }}</td>
            <td class="num right">{{ $eintrag->formattedValue($istDurchschnitt) }}</td>
            <td class="num right">{{ $eintrag->athleteCount }}</td>
            <td class="num right">{{ $eintrag->entryCount }}</td>
        </tr>
    @empty
        <tr>
            <td colspan="5" class="muted">Keine Vereine mit gewerteten Leistungen für diese Auswahl.</td>
        </tr>
    @endforelse
    </tbody>
</table>

@if($belowMinimum->isNotEmpty())
    <h2>Unter {{ $config->minEntriesPerClub }} gewerteten Leistungen — nicht platziert</h2>

    <table>
        <tbody>
        @foreach($belowMinimum as $eintrag)
            <tr>
                <td style="width: 50%;">{{ $eintrag->club->display_name }}</td>
                <td style="width: 25%;" class="num right">{{ $eintrag->formattedValue($istDurchschnitt) }}</td>
                <td style="width: 25%;" class="num right">{{ $eintrag->entryCount }} Leistung(en)</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

<p class="foot">Para Swimming NatDB · erzeugt am {{ $generatedAt->format('d.m.Y H:i') }}</p>

</body>
</html>
