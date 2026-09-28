@php
    use App\Services\MeetEntryListService;
    $logo = 'data:image/png;base64,'.base64_encode(file_get_contents(resource_path('images/oebsv-logo.png')));
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Sportpasskontrolle — {{ $meet->name }}</title>
    <style>
        /*
         * PDF-Fassung der Liste für Sportpasskontrolle (ÖBSV-Vorlage), über alle
         * Vereine. Eigenständiges HTML/CSS für dompdf. Layout über Tabellen statt
         * float (CLAUDE.md-Gotcha).
         */
        @page { margin: 22px 26px 24px 26px; }

        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1a1a1a; margin: 0; }

        .top { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        .top td { border: 0; padding: 0; vertical-align: top; }
        .top .fill { font-size: 8px; font-style: italic; color: #555; }
        .top .logo { text-align: center; }
        .top .logo img { height: 60px; }
        .top .org { font-weight: bold; }
        .top .control { text-align: left; }
        .top .control .sig { border-bottom: 1px solid #999; width: 200px; display: inline-block; height: 12px; }

        .title { text-align: center; font-size: 15px; font-weight: bold; letter-spacing: 1px; margin: 6px 0 8px; }

        .meta { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        .meta td { border: 0; padding: 1px 0; vertical-align: bottom; }
        .meta .label { font-weight: bold; white-space: nowrap; padding-right: 5px; }
        .meta .val { border-bottom: 1px solid #999; }
        .meta .spacer { width: 30px; }

        table.people { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.people th, table.people td { border: 1px solid #999; padding: 3px 4px; font-size: 9px; }
        table.people th { background: #efefef; font-size: 8px; text-align: center; }
        table.people td { vertical-align: top; word-wrap: break-word; }
        table.people .nr { width: 26px; text-align: center; }
        table.people .name { width: auto; }
        table.people .club { color: #555; font-size: 8px; }
        table.people .exam { width: 78px; }
        table.people .pass { width: 82px; }
        table.people .note { width: 82px; }
        table.people .faus { width: 34px; }

        .empty { color: #888; font-style: italic; padding: 8px 0; }
    </style>
</head>
<body>

<table class="top">
    <tr>
        <td style="width: 22%">
            <div class="fill">Bitte in Block- oder<br>Maschinschrift ausfüllen</div>
        </td>
        <td style="width: 40%" class="logo">
            <img src="{{ $logo }}" alt="ÖBSV">
            <div class="org">Österreichischer Behindertensportverband</div>
            <div>Brigittenauer Lände 42, A-1200 Wien</div>
        </td>
        <td style="width: 38%" class="control">
            <div>Die Kontrolle wurde durchgeführt von:</div>
            <div style="margin-top: 4px">Name: <span class="sig"></span></div>
            <div style="margin-top: 6px">Unterschrift: <span class="sig"></span></div>
        </td>
    </tr>
</table>

<div class="title">LISTE FÜR SPORTPASSKONTROLLE</div>

<table class="meta">
    <tr>
        <td class="label">Betrifft:</td>
        <td class="val">{{ $meet->name }}</td>
        <td class="spacer"></td>
        <td class="label">Ort:</td>
        <td class="val">{{ $meet->city }}</td>
    </tr>
    <tr>
        <td class="label">in der Zeit vom:</td>
        <td class="val">{{ $meet->start_date->format('d.m.Y') }}
            bis {{ $meet->end_date?->format('d.m.Y') ?? $meet->start_date->format('d.m.Y') }}</td>
        <td class="spacer"></td>
        <td class="label">Gesamtzahl d. Teilnehmer:</td>
        <td class="val">{{ $participants->count() }}</td>
    </tr>
</table>

@if($participants->isEmpty())
    <p class="empty">Keine Teilnehmer für diese Veranstaltung.</p>
@else
    <table class="people">
        <thead>
            <tr>
                <th class="nr">lfd. Nr.</th>
                <th class="name">ZU- und VORNAME der TEILNEHMER</th>
                <th class="exam">DATUM der letzten UNTERSUCHUNG</th>
                <th class="pass">SPORTPASS Nummer</th>
                <th class="note">ANMERKUNG</th>
                <th class="faus">FAUS</th>
            </tr>
        </thead>
        <tbody>
            @foreach($participants as $i => $entry)
                <tr>
                    <td class="nr">{{ $i + 1 }}.</td>
                    <td class="name">
                        {{ MeetEntryListService::personName($entry['athlete']) }}<br>
                        <span class="club">{{ $entry['club']->display_name }}</span>
                    </td>
                    <td class="exam"></td>
                    <td class="pass">{{ $entry['athlete']->license }}</td>
                    <td class="note"></td>
                    <td class="faus"></td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

</body>
</html>
