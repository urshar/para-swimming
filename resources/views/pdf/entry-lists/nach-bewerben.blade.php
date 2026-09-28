<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Übersichtsliste nach Wettkämpfen — {{ $meet->name }}</title>
    <style>
        /*
         * PDF-Fassung der "Übersichtsliste nach Wettkämpfen" (Meldeliste nach
         * Bewerben, an der swimify-Vorlage orientiert). Eigenständiges HTML/CSS für
         * dompdf — kein Tailwind/Flux. Layout über Tabellen, NICHT über float
         * (CLAUDE.md-Gotcha).
         *
         * Teilnehmer je Bewerb alphabetisch, in zwei Spalten (spaltenweise: erste
         * Hälfte links, zweite Hälfte rechts) zum Platzsparen.
         */
        @page { margin: 80px 26px 30px 26px; }

        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1a1a1a; margin: 0; }

        .page-header { position: fixed; top: -68px; left: 0; right: 0; height: 62px; }
        .page-header table { width: 100%; border-collapse: collapse; }
        .page-header td { border: 0; padding: 0; }
        .page-header .org { font-weight: bold; }
        .page-header .right { text-align: right; color: #444; }
        .page-header .meet { font-size: 13px; font-weight: bold; margin-top: 2px; }
        .page-header .sub { font-size: 10px; color: #333; }
        .page-header .rule { border-bottom: 1px solid #999; margin-top: 3px; }

        .section-label { font-size: 12px; font-weight: bold; margin: 12px 0 4px;
            background: #e5e5e5; padding: 3px 5px; }

        .event { margin: 6px 0 4px; page-break-inside: avoid; }
        .event-title { font-weight: bold; font-size: 10px; border-bottom: 1px solid #bbb;
            padding-bottom: 1px; margin-bottom: 2px; }

        /*
         * Feste, über ALLE Bewerbe identische Spaltenbreiten (table-layout: fixed),
         * damit die Spalten (v.a. der Verein) in jeder Bewerbstabelle an derselben
         * Stelle stehen. Die Namensspalte ist bewusst breit genug, damit lange Namen
         * (z.B. "Baumegger, Sarah Maria") trotzdem nicht umbrechen.
         */
        .c-year { color: #555; }
        .c-time { font-family: DejaVu Sans Mono, monospace; }
        .c-cls  { color: #444; }
        .c-club { color: #555; }

        table.ent { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.ent td { border: 0; padding: 1px 4px; vertical-align: top; }
        table.ent .c-name { width: 33%; }
        table.ent .c-year { width: 4%; text-align: right; }
        table.ent .c-time { width: 8%; }
        table.ent .c-cls  { width: 4%; }
        table.ent .gap    { width: 2%; }

        table.ent1 { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.ent1 td { border: 0; padding: 1px 4px; vertical-align: top; }
        table.ent1 .c-name { width: 40%; }
        table.ent1 .c-year { width: 7%; text-align: right; }
        table.ent1 .c-time { width: 12%; }
        table.ent1 .c-cls  { width: 9%; }
        table.ent1 .c-club { width: 32%; }

        .relay-team { margin: 2px 0; }
        table.rteam { width: 100%; border-collapse: collapse; }
        table.rteam td { border: 0; padding: 1px 3px; }
        table.rteam .rn { font-weight: bold; }
        table.rteam .rc { width: 70px; color: #444; }
        table.rteam .rt { width: 84px; font-family: DejaVu Sans Mono, monospace; }
        .relay-legs { padding-left: 14px; color: #333; font-size: 9px; }
        .relay-legs .pos { color: #777; }

        .empty { color: #888; font-style: italic; padding: 8px 0; }
    </style>
</head>
<body>

<div class="page-header">
    <table>
        <tr>
            <td class="org">Österreichischer Behindertensportverband</td>
            <td class="right">{{ $meet->city }} · {{ $meet->start_date->format('d.m.Y') }}</td>
        </tr>
    </table>
    <div class="meet">{{ $meet->name }}</div>
    <table style="width:100%; border-collapse:collapse;">
        <tr>
            <td class="sub">Übersichtsliste nach Wettkämpfen</td>
            <td class="right sub">{{ $courseLabel }}</td>
        </tr>
    </table>
    <div class="rule"></div>
</div>

@forelse($sections as $section)
    <div class="section-label">{{ $section['label'] }}</div>

    @foreach($section['events'] as $ev)
        <div class="event">
            <div class="event-title">{{ $ev['title'] }}</div>

            @if($ev['isRelay'])
                @foreach($ev['relays'] as $relay)
                    <div class="relay-team">
                        <table class="rteam">
                            <tr>
                                <td class="rn">{{ $relay['name'] }}</td>
                                <td class="rc">{{ $relay['class'] }}</td>
                                <td class="rt">{{ $relay['time'] }}</td>
                            </tr>
                        </table>
                        @if($relay['members']->isNotEmpty())
                            <div class="relay-legs">
                                @foreach($relay['members'] as $m)
                                    <span class="pos">{{ $m['position'] }}.</span> {{ $m['name'] }}@if(! $loop->last) &nbsp;&nbsp; @endif
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            @elseif($columns === 1)
                <table class="ent1">
                    @foreach($ev['entrants'] as $e)
                        <tr>
                            <td class="c-name">{{ $e['name'] }}</td>
                            <td class="c-year">{{ $e['year'] }}</td>
                            <td class="c-time">{{ $e['time'] }}</td>
                            <td class="c-cls">{{ $e['class'] }}</td>
                            @if($showClub)
                                <td class="c-club">{{ $e['club'] }}</td>
                            @endif
                        </tr>
                    @endforeach
                </table>
            @else
                @php
                    $entrants = $ev['entrants'];
                    $half = (int) ceil($entrants->count() / 2);
                    $left = $entrants->slice(0, $half)->values();
                    $right = $entrants->slice($half)->values();
                @endphp
                <table class="ent">
                    {{-- range() statt @for, weil das "<" in einer @for-Bedingung PhpStorms
                         Blade-Parser stört (falsche Directive-Paarung). --}}
                    @foreach(range(0, $half - 1) as $i)
                        <tr>
                            <td class="c-name">{{ $left[$i]['name'] }}</td>
                            <td class="c-year">{{ $left[$i]['year'] }}</td>
                            <td class="c-time">{{ $left[$i]['time'] }}</td>
                            <td class="c-cls">{{ $left[$i]['class'] }}</td>
                            <td class="gap"></td>
                            @if(isset($right[$i]))
                                <td class="c-name">{{ $right[$i]['name'] }}</td>
                                <td class="c-year">{{ $right[$i]['year'] }}</td>
                                <td class="c-time">{{ $right[$i]['time'] }}</td>
                                <td class="c-cls">{{ $right[$i]['class'] }}</td>
                            @else
                                <td colspan="4"></td>
                            @endif
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>
    @endforeach
@empty
    <p class="empty">Keine Meldungen für diese Veranstaltung.</p>
@endforelse

</body>
</html>
