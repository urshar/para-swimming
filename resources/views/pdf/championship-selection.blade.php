@php
    use App\Http\Controllers\ChampionshipSelectionController as Auswahl;
    use App\Support\AthleteAge;
    use App\Support\TimeParser;

    // Der Hinweis aus §11 erscheint nur, wenn tatsächlich eine umgerechnete Zeit enthalten
    // ist. Ein Hinweis, der immer dasteht, wird nicht mehr gelesen — und fehlt dann dort, wo
    // er zählt. In der Auswahl-Rangliste kommen nur Nachweise vor, er entfällt also
    // regelmäßig; die Prüfung bleibt trotzdem stehen, falls sich die Grundlage je ändert.
    $enthaeltSchaetzung = $athleteRanking->contains(fn ($eintrag): bool => $eintrag->row->status->isEstimate());
@endphp
    <!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Auswahl-Rangliste — {{ $championship->display_name }}</title>
    <style>
        * { box-sizing: border-box; }

        @page { margin: 20px 25px; }

        body {
            font-family: Helvetica, Arial, sans-serif;
            font-size: 10px;
            color: #1a1a1a;
        }

        h1 { font-size: 18px; margin: 0 0 4px 0; }

        .subtitle { font-size: 11px; color: #555; margin: 0 0 4px 0; }

        .meta { font-size: 9px; color: #777; margin: 0 0 18px 0; }

        h2 {
            width: 100%;
            font-size: 13px;
            margin: 18px 0 6px 0;
            padding: 6px 8px;
            background-color: #f0f0f0;
            border: 1px solid #ddd;
        }

        h3 { font-size: 11px; margin: 14px 0 4px 0; color: #333; }

        table { width: 100%; table-layout: fixed; border-collapse: collapse; }

        th {
            text-align: left;
            font-size: 9px;
            text-transform: uppercase;
            color: #555;
            border-bottom: 1px solid #bbb;
            padding: 4px 5px;
        }

        td { padding: 3px 5px; border-bottom: 1px solid #eee; }

        .num { font-family: 'DejaVu Sans Mono', monospace; }

        .muted { color: #888; }

        .note {
            margin-top: 20px;
            padding: 8px 10px;
            border: 1px solid #d9a441;
            background-color: #fdf6e6;
            font-size: 9px;
            line-height: 1.4;
        }

        .foot { margin-top: 14px; font-size: 8px; color: #999; }
    </style>
</head>
<body>

<h1>Auswahl-Rangliste</h1>
<p class="subtitle">{{ $championship->display_name }}</p>
<p class="meta">
    Qualifikationszeitraum {{ $championship->qualification_start->format('d.m.Y') }}
    bis {{ $championship->qualification_end->format('d.m.Y') }} ·
    Normen auf {{ $championship->course }} ·
    Stand der Auswertung {{ $generatedAt->format('d.m.Y H:i') }}
    @if($limit !== null)
        · gezeigt werden die besten {{ $limit }} je Liste
    @endif
</p>

<h2>Athleten gesamt</h2>
<p class="meta">
    Sortiert nach WPS-Punkten der besten einzelnen Leistung, nicht nach deren Summe.
</p>

<table>
    <thead>
    <tr>
        <th style="width: 6%;">Rang</th>
        <th style="width: 20%;">Athlet</th>
        <th style="width: 13%;">Alter</th>
        <th style="width: 16%;">Verein</th>
        <th style="width: 20%;">Bester Bewerb</th>
        <th style="width: 10%;">Zeit</th>
        <th style="width: 8%;">Punkte</th>
        <th style="width: 7%;">Normen</th>
    </tr>
    </thead>
    <tbody>
    @forelse(Auswahl::applyLimit($athleteRanking, $limit) as $eintrag)
        <tr>
            <td class="num">{{ $eintrag->rank ?? '–' }}</td>
            <td>{{ $eintrag->athlete->full_name }}</td>
            <td>{{ AthleteAge::label($eintrag->athlete, $championship->year) ?? '–' }}</td>
            <td>{{ $eintrag->athlete->club?->display_name }}</td>
            <td>{{ $eintrag->row->eventLabel }} {{ $eintrag->row->sportClass }}</td>
            <td class="num">{{ TimeParser::display($eintrag->row->status->swimTime) }}</td>
            <td class="num">
                @if($eintrag->points === null)
                    <span class="muted">ohne Bewertung</span>
                @else
                    {{ $eintrag->points }}
                @endif
            </td>
            <td class="num">{{ $eintrag->fulfilledCount }}</td>
        </tr>
    @empty
        <tr>
            <td colspan="8" class="muted">Bislang hat niemand eine Norm nachweislich erfüllt.</td>
        </tr>
    @endforelse
    </tbody>
</table>

<h2>Je Bewerb</h2>

@foreach($eventRankings as $bezeichnung => $eintraege)
    <h3>{{ $bezeichnung }}</h3>
    <table>
        <thead>
        <tr>
            <th style="width: 6%;">Rang</th>
            <th style="width: 22%;">Athlet</th>
            <th style="width: 13%;">Alter</th>
            <th style="width: 18%;">Verein</th>
            <th style="width: 11%;">Zeit</th>
            <th style="width: 9%;">Punkte</th>
            <th style="width: 21%;">Wettkampf</th>
        </tr>
        </thead>
        <tbody>
        @foreach(Auswahl::applyLimit($eintraege, $limit) as $eintrag)
            <tr>
                <td class="num">{{ $eintrag->rank ?? '–' }}</td>
                <td>{{ $eintrag->athlete->full_name }}</td>
                <td>{{ AthleteAge::label($eintrag->athlete, $championship->year) ?? '–' }}</td>
                <td>{{ $eintrag->athlete->club?->display_name }}</td>
                <td class="num">{{ TimeParser::display($eintrag->row->status->swimTime) }}</td>
                <td class="num">
                    @if($eintrag->points === null)
                        <span class="muted">ohne Bewertung</span>
                    @else
                        {{ $eintrag->points }}
                    @endif
                </td>
                <td>{{ $eintrag->row->status->meetName }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endforeach

@if($enthaeltSchaetzung)
    <div class="note">
        <strong>Hinweis:</strong><br>
        Mit „rechnerisch erreicht" gekennzeichnete Leistungen beruhen auf umgerechneten
        Kurzbahnzeiten. Sie sind kein Qualifikationsnachweis — international zählt ausschließlich
        eine auf der Langbahn geschwommene Zeit innerhalb des Qualifikationszeitraums.
    </div>
@endif

<p class="foot">
    Para Swimming NatDB · erzeugt am {{ $generatedAt->format('d.m.Y H:i') }}
</p>

</body>
</html>
