@php
    use App\Services\MeetEntryListService;
    $logo = 'data:image/png;base64,'.base64_encode(file_get_contents(resource_path('images/sport-austria-logo.png')));
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Teilnehmerliste — {{ $meet->name }}</title>
    <style>
        /*
         * PDF-Fassung der TeilnehmerInnenliste (Sport-Austria-Vorlage), ein Formular
         * je Verein. Eigenständiges HTML/CSS für dompdf — kein Tailwind/Flux.
         * Layout über Tabellen, NICHT über float (CLAUDE.md-Gotcha).
         */
        @page { margin: 24px 26px 26px 26px; }

        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1a1a1a; margin: 0; }

        .head { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .head td { border: 0; padding: 0; vertical-align: middle; }
        .head .title { font-size: 15px; font-weight: bold; letter-spacing: 2px; }
        .head .logo { text-align: right; }
        .head .logo img { height: 34px; }

        .meta { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        .meta td { border: 0; padding: 1px 0; vertical-align: bottom; }
        .meta .label { font-weight: bold; white-space: nowrap; padding-right: 5px; }
        .meta .val { border-bottom: 1px solid #999; }
        .meta .hint { font-size: 7px; font-style: italic; color: #555; }
        .meta .spacer { width: 22px; }

        table.people { width: 100%; border-collapse: collapse; }
        table.people th, table.people td { border: 1px solid #999; padding: 4px 5px; }
        table.people th { background: #efefef; font-size: 8px; text-align: center; }
        table.people td { height: 15px; vertical-align: top; }
        table.people .nr { width: 34px; text-align: center; }
        table.people .name { width: 190px; }
        table.people .city { width: 150px; }
        table.people .days { width: 34px; text-align: center; }

        .foot { font-size: 7px; font-style: italic; color: #555; margin-top: 4px; }

        .club-section { page-break-after: always; }
        .club-section:last-child { page-break-after: auto; }
        .empty { color: #888; font-style: italic; padding: 8px 0; }
    </style>
</head>
<body>

@forelse($byClub as $group)
    <div class="club-section">
        <table class="head">
            <tr>
                <td class="title">T E I L N E H M E R I N N E N L I S T E</td>
                <td class="logo"><img src="{{ $logo }}" alt="Sport Austria"></td>
            </tr>
        </table>

        <table class="meta">
            <tr>
                <td class="label">BETRIFFT:</td>
                <td class="val">{{ $meet->name }}</td>
                <td class="spacer"></td>
                <td class="label">ORT:</td>
                <td class="val">{{ $meet->city }}</td>
            </tr>
            <tr>
                <td></td>
                <td class="hint">(Wettkampf / Lehrgang / Seminar usw.)</td>
                <td></td>
                <td></td>
                <td class="hint">(im Ausland auch Staat)</td>
            </tr>
            <tr>
                <td class="label">ZEITRAUM:</td>
                <td class="val">am / vom: {{ $meet->start_date->format('d.m.Y') }}
                    bis: {{ $meet->end_date?->format('d.m.Y') }} = {{ $days }} TAGE</td>
                <td class="spacer"></td>
                <td class="label">VEREIN:</td>
                <td class="val">{{ $group['club']->display_name }}</td>
            </tr>
            <tr>
                <td class="label">ANZAHL DER PERSONEN:</td>
                <td class="val">{{ $group['participants']->count() }}</td>
                <td class="spacer"></td>
                <td></td>
                <td class="hint">Bitte in Block- oder Druckschrift ausfüllen</td>
            </tr>
        </table>

        <table class="people">
            <thead>
                <tr>
                    <th class="nr">lfd. Nr.</th>
                    <th class="name">FAMILIEN- und VORNAME</th>
                    <th class="city">WOHNORT</th>
                    <th class="days">TAGE</th>
                    <th>UNTERSCHRIFT</th>
                </tr>
            </thead>
            <tbody>
                @foreach($group['participants'] as $i => $athlete)
                    <tr>
                        <td class="nr">{{ $i + 1 }}</td>
                        <td class="name">{{ MeetEntryListService::personName($athlete) }}</td>
                        <td class="city"></td>
                        <td class="days">{{ $days }}</td>
                        <td></td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="foot">TeilnehmerInnenliste · {{ $group['club']->display_name }} · erzeugt am {{ now()->format('d.m.Y') }}</div>
    </div>
@empty
    <table class="head">
        <tr>
            <td class="title">T E I L N E H M E R I N N E N L I S T E</td>
            <td class="logo"><img src="{{ $logo }}" alt="Sport Austria"></td>
        </tr>
    </table>
    <p class="empty">Keine Teilnehmer für diese Veranstaltung.</p>
@endforelse

</body>
</html>
