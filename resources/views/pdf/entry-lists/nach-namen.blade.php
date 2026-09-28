<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Übersichtsliste nach Namen — {{ $meet->name }}</title>
    <style>
        /*
         * PDF-Fassung der "Übersichtsliste nach Namen" (Meldeliste, an der
         * swimify-Vorlage orientiert). Eigenständiges HTML/CSS für dompdf — kein
         * Tailwind/Flux. Layout über Tabellen, NICHT über float (CLAUDE.md-Gotcha).
         *
         * Eine gemeinsame 3-Spalten-Tabelle je Verein (Bewerb | Meldezeit |
         * Sportklasse), damit die Sportklasse bei Einzel- und Staffelzeilen in
         * derselben Spalte steht.
         */
        @page { margin: 80px 26px 30px 26px; }

        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1a1a1a; margin: 0; }

        .page-header { position: fixed; top: -68px; left: 0; right: 0; height: 62px; }
        .page-header table { width: 100%; border-collapse: collapse; }
        .page-header td { border: 0; padding: 0; }
        .page-header .org { font-weight: bold; }
        .page-header .right { text-align: right; color: #444; }
        .page-header .meet { font-size: 13px; font-weight: bold; margin-top: 2px; }
        .page-header .sub { font-size: 10px; color: #333; }
        .page-header .rule { border-bottom: 1px solid #999; margin-top: 3px; }

        .section-label { font-size: 12px; font-weight: bold; margin: 12px 0 4px; }

        /* Jeder Verein als eigener Block; die graue Kopfzeile hebt den Verein
           genug hervor, deshalb ohne Trennlinie. */
        .club-block { padding-bottom: 4px; margin-bottom: 10px; }
        .club-header { font-weight: bold; font-size: 11px; background: #eee;
            padding: 3px 5px; border: 1px solid #ccc; }
        .club-header .code { font-weight: normal; color: #555; font-size: 9px; }

        table.ce { width: 100%; border-collapse: collapse; margin-top: 2px; }
        table.ce td { border: 0; padding: 1px 4px; vertical-align: top; }
        table.ce .col-tm { width: 84px; font-family: DejaVu Sans Mono, monospace; }
        table.ce .col-cls { width: 78px; }

        tr.ath td { padding-top: 4px; }
        tr.ath .name { font-weight: bold; }
        tr.ath .lic { color: #555; }
        tr.ath .yr { color: #555; font-weight: normal; }

        td.ev { padding-left: 18px; }

        tr.relay .rl { padding-left: 4px; font-weight: bold; }
        tr.relay-members td { padding-left: 22px; color: #333; font-size: 9px; }
        tr.relay-members .pos { color: #777; }

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
    <div class="sub">Übersichtsliste nach Namen</div>
    <div class="rule"></div>
</div>

@forelse($sections as $section)
    <div class="section-label">{{ $section['label'] }}</div>

    @foreach($section['clubs'] as $clubBlock)
        <div class="club-block">
            <div class="club-header">
                {{ $clubBlock['club']->name }}
                @if($clubBlock['codeLine'])
                    <span class="code">— {{ $clubBlock['codeLine'] }}</span>
                @endif
            </div>

            <table class="ce">
                {{-- Einzelmeldungen je Athlet --}}
                @foreach($clubBlock['athletes'] as $a)
                    <tr class="ath">
                        <td colspan="3">
                            <span class="lic">{{ $a['athlete']->license }}</span>
                            <span class="name">{{ $a['athlete']->display_name }}</span>
                            @if($a['birthYear'] !== '')
                                <span class="yr">(Jg. {{ $a['birthYear'] }})</span>
                            @endif
                        </td>
                    </tr>
                    @foreach($a['entries'] as $entry)
                        <tr>
                            <td class="ev">{{ $entry->swimEvent?->display_name }}</td>
                            <td class="col-tm">{{ $entry->formatted_entry_time }}</td>
                            <td class="col-cls">{{ $entry->sport_class }}</td>
                        </tr>
                    @endforeach
                @endforeach

                {{-- Staffeln je Verein, Sportklasse in derselben Spalte wie oben --}}
                @foreach($clubBlock['relays'] as $relay)
                    <tr class="relay">
                        <td class="rl">Staffel: {{ $relay['name'] }} · {{ $relay['event']?->display_name }}</td>
                        <td class="col-tm">{{ $relay['time'] }}</td>
                        <td class="col-cls">{{ $relay['class'] }}</td>
                    </tr>
                    @if($relay['members']->isNotEmpty())
                        <tr class="relay-members">
                            <td colspan="3">
                                @foreach($relay['members'] as $m)
                                    <span class="pos">{{ $m['position'] }}.</span> {{ $m['name'] }}@if(! $loop->last) &nbsp;&nbsp; @endif
                                @endforeach
                            </td>
                        </tr>
                    @endif
                @endforeach
            </table>
        </div>
    @endforeach
@empty
    <p class="empty">Keine Meldungen für diese Veranstaltung.</p>
@endforelse

</body>
</html>
