<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Ergebnisliste — {{ $meet->name }}</title>
    <style>
        /*
         * Ergebnisliste einer Veranstaltung (MeetResultListService). Eigenständiges HTML/CSS für
         * dompdf, kein Tailwind/Flux. Layout über Tabellen, NICHT über float (CLAUDE.md-Gotcha).
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

        .event { margin-bottom: 12px; }
        .event-title { font-size: 12px; font-weight: bold; background: #eee; padding: 3px 5px;
            border: 1px solid #ccc; }
        .group-title { font-weight: bold; font-size: 10px; margin: 6px 0 2px; padding-left: 2px;
            border-bottom: 1px solid #ccc; }

        table.res { width: 100%; border-collapse: collapse; }
        table.res td { border: 0; padding: 1px 4px; vertical-align: top; }
        table.res .col-pl { width: 26px; text-align: right; }
        table.res .col-yr { width: 34px; color: #555; }
        table.res .col-club { width: 170px; }
        table.res .col-cls { width: 48px; }
        table.res .col-tm { width: 64px; text-align: right; font-family: DejaVu Sans Mono, monospace; }
        table.res .col-pts { width: 40px; text-align: right; }
        table.res th { font-size: 8px; font-weight: normal; color: #666; text-align: left; padding: 1px 4px; }
        table.res th.col-pl, table.res th.col-tm, table.res th.col-pts { text-align: right; }

        .footnote { margin-top: 10px; font-size: 8px; color: #555; }
        tr.members td { font-size: 8px; color: #555; padding-top: 0; padding-bottom: 3px; }
        table.res .col-note { width: 70px; color: #555; }
        tr.unranked td { color: #555; }

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
    <div class="sub">Ergebnisliste</div>
    <div class="rule"></div>
</div>

@forelse($events as $block)
    @php $event = $block['event']; @endphp
    <div class="event">
        <div class="event-title">
            {{ $event->event_number ? 'Nr. ' . $event->event_number . '  ' : '' }}{{ $event->display_name }}
            {{ match ($event->gender) { 'M' => 'Herren', 'F' => 'Damen', 'X' => 'Mixed', default => '' } }}
        </div>

        @if($block['isRelay'])
            {{-- Staffeln: Wertung (Herren/Damen/Mixed) und Staffelklasse, darunter die Schwimmer. --}}
            @foreach($block['groups'] as $group)
                <div class="group-title">{{ $group['label'] }}@if($group['missingPoints'] > 0) <span style="font-weight: normal; color: #b00;">({{ $group['missingPoints'] }} ohne Punkte, nicht platziert)</span>@endif</div>
                <table class="res">
                    <tr>
                        <th class="col-pl">Pl.</th>
                        <th>Staffel</th>
                        <th class="col-tm">Zeit</th>
                        @if($pointColumns['points'])
                            <th class="col-pts">Punkte</th>
                        @endif
                        <th class="col-note"></th>
                    </tr>
                    @foreach($group['rows'] as $row)
                        @php
                            $relayResult = $row['result'];
                            $relayRecords = collect([
                                'NR' => $relayResult->is_national_record,
                                'JR' => $relayResult->is_junior_record,
                                'LR' => $relayResult->is_regional_record || $relayResult->is_regional_junior_record,
                            ])->filter()->keys()->implode(' ');
                            $relayStatus = $relayResult->status === 'EXH' ? 'AK' : $relayResult->status;
                            $memberLine = $relayResult->members
                                ->map(fn ($m) => $m->display_name . ($m->athlete?->birth_date ? ' ' . $m->athlete->birth_date->format('Y') : ''))
                                ->implode(' · ');
                        @endphp
                        <tr @class(['unranked' => $row['place'] === null])>
                            <td class="col-pl">{{ $row['place'] ? $row['place'] . '.' : '' }}</td>
                            <td>{{ $relayResult->display_name }}</td>
                            <td class="col-tm">{{ $relayResult->swim_time ? $relayResult->formatted_swim_time : '' }}</td>
                            @if($pointColumns['points'])
                                <td class="col-pts">{{ $relayResult->points }}</td>
                            @endif
                            <td class="col-note">{{ trim($relayStatus . ' ' . $relayRecords) }}</td>
                        </tr>
                        @if($memberLine !== '')
                            <tr class="members">
                                <td></td>
                                <td colspan="{{ $pointColumns['points'] ? 4 : 3 }}">{{ $memberLine }}</td>
                            </tr>
                        @endif
                    @endforeach
                </table>
            @endforeach
        @else
        @foreach($block['groups'] as $group)
            <div class="group-title">{{ $group['label'] }}@if($group['missingPoints'] > 0) <span style="font-weight: normal; color: #b00;">({{ $group['missingPoints'] }} ohne Punkte, nicht platziert)</span>@endif</div>
            <table class="res">
                <tr>
                    <th class="col-pl">Pl.</th>
                    <th>Name</th>
                    <th class="col-yr">Jg.</th>
                    <th class="col-club">Verein</th>
                    <th class="col-cls">Klasse</th>
                    <th class="col-tm">Zeit</th>
                    @if($pointColumns['points'])
                        <th class="col-pts">Punkte</th>
                    @endif
                    @if($pointColumns['wps'])
                        <th class="col-pts">WPS</th>
                    @endif
                    <th class="col-note"></th>
                </tr>
                @foreach($group['rows'] as $row)
                    @php
                        $result = $row['result'];
                        $records = collect([
                            'WR' => $result->is_world_record,
                            'ER' => $result->is_european_record,
                            'NR' => $result->is_national_record,
                            'JR' => $result->is_junior_record,
                            'LR' => $result->is_regional_record || $result->is_regional_junior_record,
                        ])->filter()->keys()->implode(' ');
                        $statusLabel = $result->status === 'EXH' ? 'AK' : $result->status;
                    @endphp
                    <tr @class(['unranked' => $row['place'] === null])>
                        <td class="col-pl">{{ $row['place'] ? $row['place'] . '.' : '' }}</td>
                        <td>{{ $result->athlete?->display_name }}</td>
                        <td class="col-yr">{{ $result->athlete?->birth_date?->format('Y') }}</td>
                        <td class="col-club">{{ $result->club?->display_name }}</td>
                        <td class="col-cls">{{ $result->sport_class }}</td>
                        <td class="col-tm">{{ $result->swim_time ? $result->formatted_swim_time : '' }}</td>
                        @if($pointColumns['points'])
                            <td class="col-pts">{{ $result->points }}</td>
                        @endif
                        @if($pointColumns['wps'])
                            <td class="col-pts">{{ $result->wps_points }}{{ $result->hasWpsPoints() && $result->hasEstimatedWpsPoints() ? '*' : '' }}</td>
                        @endif
                        <td class="col-note">{{ trim($statusLabel . ' ' . $records) }}</td>
                    </tr>
                @endforeach
            </table>
        @endforeach
        @endif
    </div>
@empty
    <div class="empty">Keine Ergebnisse vorhanden.</div>
@endforelse

@if($events->isNotEmpty() && ($pointColumns['points'] || $pointColumns['wps']))
    <div class="footnote">
        @if($pointColumns['points'])
            Punkte = ÖBSV-Punkte (World-Aquatics-Formel).
        @endif
        @if($pointColumns['wps'])
            WPS = World-Para-Swimming-Punkte; * geschätzt, nicht offiziell (abgeleitete Kurzbahn-Parameter).
        @endif
    </div>
@endif

</body>
</html>
