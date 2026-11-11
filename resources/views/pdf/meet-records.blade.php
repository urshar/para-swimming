@php
    use App\Models\SwimRecord;
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Rekorde – {{ $meet->name }}</title>
    <style>
        /*
         * PDF-Fassung der Rekorde einer Veranstaltung (meets/records). Eigenständiges HTML/CSS für dompdf —
         * kein Tailwind, kein Flux. Kopf- und Fußzeile wie im Jahresbericht (pdf/statistics-report).
         */

        /* Platz oben und unten für die fest positionierte Kopf-/Fußzeile. */
        @page {
            margin: 70px 25px 55px 25px;
        }

        * { box-sizing: border-box; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 8px;
            color: #1a1a1a;
            margin: 0;
        }

        .page-header {
            position: fixed;
            top: -55px;
            left: 0;
            right: 0;
            height: 40px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 4px;
        }

        .page-header .title { font-size: 13px; font-weight: bold; }
        .page-header .sub { font-size: 8px; color: #666; margin-top: 2px; }

        .page-footer {
            position: fixed;
            bottom: -40px;
            left: 0;
            right: 0;
            height: 30px;
            border-top: 1px solid #ddd;
            padding-top: 4px;
        }

        /*
         * Fußzeilen-Layout bewusst über eine Tabelle statt über float: Ein float in einem position:fixed-Element
         * verschiebt in dompdf umgebrochene Tabellenzellen an anderer Stelle im Dokument (siehe CLAUDE.md).
         */
        .page-footer table { width: 100%; border-collapse: collapse; margin: 0; }
        .page-footer td { border: 0; padding: 0; font-size: 8px; color: #666; }
        .page-footer .left { text-align: left; }
        .page-footer .right { text-align: right; }

        /* Seitenzahl ohne Gesamtzahl — counter(pages) liefert in dompdf in der Fußzeile 0. */
        .page-numbering:before {
            content: "Seite " counter(page);
        }

        h2 {
            font-size: 11px;
            margin: 12px 0 4px 0;
            padding: 4px 6px;
            background-color: #f0f0f0;
            border: 1px solid #ddd;
            page-break-after: avoid;
        }

        h2 .count { font-weight: normal; color: #666; }

        table.records { width: 100%; table-layout: fixed; border-collapse: collapse; }
        table.records th {
            text-align: left;
            font-size: 7px;
            text-transform: uppercase;
            color: #555;
            border-bottom: 1px solid #bbb;
            padding: 3px 4px;
        }
        table.records td { padding: 3px 4px; border-bottom: 1px solid #eee; vertical-align: top; }
        table.records tr { page-break-inside: avoid; }
        table.records .num { font-family: DejaVu Sans Mono, monospace; text-align: right; }
        table.records th.num { text-align: right; }
        .muted { color: #777; }
        .empty { margin-top: 12px; }
    </style>
</head>
<body>

<div class="page-header">
    <div class="title">Rekorde – {{ $meet->name }}</div>
    <div class="sub">
        {{ $meet->city }}@if($meet->city) · @endif{{ $meet->start_date?->format('d.m.Y') }}
        · {{ $groups->sum(fn (array $group) => $group['records']->count()) }} Rekorde
    </div>
</div>

<div class="page-footer">
    <table>
        <tr>
            <td class="left">Para Swimming NatDB · erzeugt am {{ $generatedAt->format('d.m.Y H:i') }} Uhr</td>
            <td class="right page-numbering"></td>
        </tr>
    </table>
</div>

@if($groups->isEmpty())
    <p class="empty">Bei dieser Veranstaltung wurden keine Rekorde aufgestellt.</p>
@else
    @foreach($groups as $group)
        <h2>{{ $group['label'] }} <span class="count">({{ $group['records']->count() }})</span></h2>
        <table class="records">
            <thead>
            <tr>
                <th style="width: 17%;">Disziplin</th>
                <th style="width: 8%;">Klasse</th>
                <th style="width: 8%;">Wertung</th>
                <th style="width: 23%;">Athlet / Staffel</th>
                <th style="width: 15%;">Verein</th>
                <th class="num" style="width: 9%;">Zeit</th>
                <th class="num" style="width: 9%;">Bisher</th>
                <th style="width: 11%;">Status</th>
            </tr>
            </thead>
            <tbody>
            @foreach($group['records'] as $record)
                <tr>
                    <td>
                        {{ $record->discipline_label }} <span class="muted">{{ $record->course }}</span>
                        @if($record->from_split)
                            <br><span class="muted">Zwischenzeit</span>
                        @endif
                    </td>
                    <td>{{ $record->sport_class }}</td>
                    <td>{{ SwimRecord::genderLabel($record->gender) }}</td>
                    <td>
                        @if($record->relay_count > 1)
                            {{ $record->relayTeam->map->display_name->implode(', ') ?: 'Staffel' }}
                        @elseif($record->athlete)
                            {{ $record->athlete->display_name }}
                            <span class="muted">({{ $record->athlete->birth_date?->format('Y') ?? '–' }})</span>
                        @else
                            –
                        @endif
                    </td>
                    <td>{{ $record->record_club_name ?? '–' }}</td>
                    <td class="num">{{ $record->formatted_swim_time }}</td>
                    <td class="num muted">{{ $record->supersedes?->formatted_swim_time ?? '–' }}</td>
                    <td>{{ $statusLabels[$record->record_status] ?? $record->record_status }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endforeach
@endif

</body>
</html>
