@php
    use App\Support\TimeParser;
    use Illuminate\Support\Carbon;

    $filterbeschreibung = implode(' · ', $filter->describe());
@endphp
    <!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>WPS-Rangliste</title>
    <style>
        * { box-sizing: border-box; }
        @page { margin: 18px 20px; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 9px; color: #1a1a1a; }
        h1 { font-size: 17px; margin: 0 0 4px 0; }
        .subtitle { font-size: 11px; color: #555; margin: 0 0 4px 0; }
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
        .est { color: #a06a10; }
        .note { margin-top: 16px; padding: 8px 10px; border: 1px solid #d9a441;
                background-color: #fdf6e6; font-size: 9px; line-height: 1.4; }
        .foot { margin-top: 12px; font-size: 8px; color: #999; }
    </style>
</head>
<body>

<h1>WPS-{{ $filter->typeLabel() }}</h1>

@if($meetName)
    <p class="subtitle">{{ $meetName }}</p>
@endif

<p class="meta">
    {{ $filterbeschreibung }} ·
    Punktesystem WPS
    @if($versions !== [])
        · @if(count($versions) > 1) Punkteversionen @else Punkteversion @endif
        {{ implode(', ', $versions) }}
    @endif
    · Stand {{ $generatedAt->format('d.m.Y H:i') }}
</p>

{{-- Enthält die Rangliste Ergebnisse aus mehreren Versionen, wird das ausgewiesen ([R3]):
     Eine Liste aus verschiedenen Jahrgängen sähe sonst aus wie eine einheitlich gerechnete. --}}
@if(count($versions) > 1)
    <p class="meta est">
        Diese Rangliste enthält Ergebnisse aus mehreren WPS-Punkteversionen. Die Punkte stammen
        jeweils aus der am Ergebnis gespeicherten Version und sind untereinander nur eingeschränkt
        vergleichbar.
    </p>
@endif

<table>
    <thead>
    <tr>
        <th style="width: 4%;">Rang</th>
        <th style="width: 15%;">Athlet</th>
        <th style="width: 5%;">Alter</th>
        <th style="width: 13%;">Verein</th>
        <th style="width: 14%;">Bewerb</th>
        <th style="width: 6%;">Klasse</th>
        <th style="width: 10%;">Zeit</th>
        <th style="width: 9%;">gesch. LCM</th>
        <th style="width: 6%;" class="right">Punkte</th>
        <th style="width: 18%;">Wettkampf</th>
    </tr>
    </thead>
    <tbody>
    @forelse($entries as $eintrag)
        <tr>
            <td class="num">{{ $eintrag->rank }}</td>
            <td>{{ $eintrag->athlete->full_name }}</td>
            <td class="num">{{ $eintrag->age ?? '' }}</td>
            <td>{{ $eintrag->athlete->club?->display_name }}</td>
            <td>{{ $eintrag->eventLabel }}</td>
            <td class="num">{{ $eintrag->sportClass }}</td>
            <td class="num">{{ TimeParser::display($eintrag->swimTime) }} {{ $eintrag->course }}</td>
            <td class="num est">
                @if($eintrag->estimatedLcmTime !== null)
                    {{ TimeParser::display($eintrag->estimatedLcmTime) }}
                @endif
            </td>
            <td class="num right">{{ $eintrag->points }}</td>
            <td>
                {{ $eintrag->meetName }}
                @if($eintrag->meetDate)
                    ({{ Carbon::parse($eintrag->meetDate)->format('d.m.Y') }})
                @endif
            </td>
        </tr>
    @empty
        <tr>
            <td colspan="10" class="muted">Keine gewerteten Leistungen für diese Auswahl.</td>
        </tr>
    @endforelse
    </tbody>
</table>

{{-- Fehlende Zuordnungen bleiben sichtbar, statt still zu verschwinden (§5). --}}
@if($withoutBirthDate->isNotEmpty())
    <h2>Ohne Geburtsdatum — aus der Altersrangliste ausgeschlossen ({{ $withoutBirthDate->count() }})</h2>

    <table>
        <tbody>
        @foreach($withoutBirthDate as $eintrag)
            <tr>
                <td style="width: 25%;">{{ $eintrag->athlete->full_name }}</td>
                <td style="width: 22%;">{{ $eintrag->athlete->club?->display_name }}</td>
                <td style="width: 25%;">{{ $eintrag->eventLabel }} {{ $eintrag->sportClass }}</td>
                <td style="width: 13%;" class="num">{{ TimeParser::display($eintrag->swimTime) }}</td>
                <td style="width: 15%;" class="num right">{{ $eintrag->points }} Punkte</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

{{-- Verpflichtender Hinweis nach §11.4 — nur bei tatsächlich geschätzten Punkten. Ein
     Hinweis, der immer dasteht, wird nicht mehr gelesen. --}}
@if($hasEstimated)
    <div class="note">
        <strong>Hinweis:</strong><br>
        Die dargestellten SCM-WPS-Punkte wurden anhand abgeleiteter Parameter berechnet. Diese
        Werte sind nicht offiziell von World Para Swimming anerkannt.
        @if($isYouth)
            <br><br>
            Der Umrechnungsfaktor beruht überwiegend auf international startenden Athletinnen und
            Athleten und fällt für den Nachwuchs tendenziell zu optimistisch aus.
        @endif
    </div>
@endif

<p class="foot">Para Swimming NatDB · erzeugt am {{ $generatedAt->format('d.m.Y H:i') }}</p>

</body>
</html>
