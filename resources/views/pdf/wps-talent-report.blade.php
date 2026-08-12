@php
    use App\Support\TimeParser;
    use Illuminate\Support\Carbon;
@endphp
    <!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Förderauswertung</title>
    <style>
        * { box-sizing: border-box; }
        @page { margin: 18px 20px; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 9px; color: #1a1a1a; }
        h1 { font-size: 17px; margin: 0 0 4px 0; }
        .meta { font-size: 9px; color: #777; margin: 0 0 12px 0; }
        h2 { width: 100%; font-size: 12px; margin: 14px 0 5px 0; padding: 5px 8px;
             background-color: #f0f0f0; border: 1px solid #ddd; }
        table { width: 100%; table-layout: fixed; border-collapse: collapse; }
        th { text-align: left; font-size: 8px; text-transform: uppercase; color: #555;
             border-bottom: 1px solid #bbb; padding: 3px 4px; }
        td { padding: 3px 4px; border-bottom: 1px solid #eee; }
        .num { font-family: 'DejaVu Sans Mono', monospace; }
        .right { text-align: right; }
        .muted { color: #888; }
        .over { color: #1f7a34; }
        .est { color: #a06a10; }
        .note { margin: 0 0 14px 0; padding: 8px 10px; border: 1px solid #d9a441;
                background-color: #fdf6e6; font-size: 9px; line-height: 1.4; }
        .athlete { background-color: #f7f7f7; }
        .athlete td { border-top: 1px solid #ccc; padding-top: 5px; }
        .foot { margin-top: 12px; font-size: 8px; color: #999; }
    </style>
</head>
<body>

<h1>Förderauswertung</h1>
<p class="meta">{{ $config->describe() }} · Stand {{ $generatedAt->format('d.m.Y H:i') }}</p>

{{-- Verpflichtender Hinweis nach §6.6.5 — steht im PDF ebenso wie in der Ansicht. --}}
<div class="note">
    <strong>Hinweis:</strong><br>
    Die Punkte beruhen auf umgerechneten Kurzbahnzeiten. Der Umrechnungsfaktor ist an
    international startenden Athletinnen und Athleten geeicht und fällt für den Nachwuchs
    tendenziell zu optimistisch aus. Die Auswertung ist ein Anhaltspunkt für die Förderung,
    kein Leistungsnachweis.
</div>

@foreach($groups as $gruppe => $zeilen)
    <h2>{{ $gruppe }} — {{ $zeilen->count() }} Leistungen,
        Schwelle {{ $config->thresholdPercentFor($gruppe) }} % der Norm</h2>

    <table>
        <thead>
        <tr>
            <th style="width: 22%;">Bewerb</th>
            <th style="width: 8%;">Klasse</th>
            <th style="width: 13%;">Zeit</th>
            <th style="width: 12%;">gesch. LCM</th>
            <th style="width: 12%;">Norm ({{ $config->normLabel() }})</th>
            <th style="width: 8%;" class="right">Pkt.</th>
            <th style="width: 8%;" class="right">Schw.</th>
            <th style="width: 17%;" class="right">Abstand</th>
        </tr>
        </thead>
        <tbody>
        @php($vorherigerAthlet = null)

        @foreach($zeilen as $zeile)
            {{-- Athlet nur in der ersten seiner Zeilen; die Wiederholung verdeckt sonst, dass
                 es sich um denselben handelt. --}}
            @php($ersteZeile = $vorherigerAthlet !== $zeile->athlete->id)
            @php($vorherigerAthlet = $zeile->athlete->id)

            {{-- Athlet, Jahrgang und Verein in einer eigenen Zeile über ihren Bewerben; die
                 Sportklasse dagegen in JEDER Bewerbszeile, weil sie am Bewerb hängt und nicht
                 am Athleten (S4 Freistil, SB3 Brust, SM4 Lagen). --}}
            @if($ersteZeile)
                <tr class="athlete">
                    <td colspan="8">
                        <strong>{{ $zeile->athlete->full_name }}</strong>
                        — Jg. {{ $zeile->birthYear }}, {{ $zeile->athlete->club?->display_name }}
                    </td>
                </tr>
            @endif

            <tr>
                <td>{{ $zeile->eventLabel }}</td>
                <td class="num">{{ $zeile->sportClass }}</td>
                <td class="num">{{ TimeParser::display($zeile->swimTime) }} {{ $zeile->course }}</td>
                <td class="num est">
                    @if($zeile->estimatedLcmTime !== null)
                        {{ TimeParser::display($zeile->estimatedLcmTime) }}
                    @endif
                </td>
                <td class="num muted">
                    @if($zeile->normTime !== null)
                        {{ TimeParser::display($zeile->normTime) }}
                    @endif
                </td>
                <td class="num right">{{ $zeile->points }}</td>
                <td class="num right muted">{{ $zeile->thresholdPoints }}</td>
                <td class="num right">
                    @if($zeile->reachesThreshold())
                        <span class="over">{{ $zeile->formattedGap() }}</span>
                    @else
                        <span class="muted">{{ $zeile->formattedGap() }}</span>
                    @endif
                    ({{ $zeile->percentOfNorm() }} %)
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endforeach

@if($groups->isEmpty())
    <p class="muted">
        Keine Leistungen im Zeitraum, für die in der Referenznorm ein Bewerb ausgeschrieben ist.
    </p>
@endif

@if($withoutBirthDate->isNotEmpty())
    <h2>Ohne Geburtsdatum — keiner Altersgruppe zuzuordnen ({{ $withoutBirthDate->count() }})</h2>
    <table>
        <tbody>
        @foreach($withoutBirthDate as $eintrag)
            <tr>
                <td style="width: 30%;">{{ $eintrag->athlete->full_name }}</td>
                <td style="width: 25%;">{{ $eintrag->athlete->club?->display_name }}</td>
                <td style="width: 25%;">{{ $eintrag->eventLabel }} {{ $eintrag->sportClass }}</td>
                <td style="width: 20%;" class="num right">{{ $eintrag->points }} Punkte</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

<p class="foot">Para Swimming NatDB · erzeugt am {{ $generatedAt->format('d.m.Y H:i') }}</p>

</body>
</html>
