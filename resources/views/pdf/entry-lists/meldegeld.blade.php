@php
    use App\Support\AthleteFees;
    use App\Support\ClubFeeStatement;
    use App\Support\FeeLine;
    use App\Support\FeeScheduleLine;
    use App\Support\Money;
@endphp
    <!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Meldegeld-Abrechnung — {{ $meet->name }}</title>
    <style>
        /*
         * PDF der Meldegeld-Abrechnung (EntryFeeCalculator). Eigenständiges HTML/CSS für dompdf — kein
         * Tailwind/Flux. Layout über Tabellen, NICHT über float (CLAUDE.md-Gotcha). Oben die hinterlegten
         * Meldegebühren; Admin: Gesamtübersicht, dann je Verein eine neue Seite; Verein: nur die eigene Abrechnung.
         */
        @page {
            margin: 80px 30px 30px 30px;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            color: #1a1a1a;
            margin: 0;
        }

        .page-header {
            position: fixed;
            top: -68px;
            left: 0;
            right: 0;
            height: 62px;
        }

        .page-header table {
            width: 100%;
            border-collapse: collapse;
        }

        .page-header td {
            border: 0;
            padding: 0;
        }

        .page-header .org {
            font-weight: bold;
        }

        .page-header .right {
            text-align: right;
            color: #444;
        }

        .page-header .meet {
            font-size: 13px;
            font-weight: bold;
            margin-top: 2px;
        }

        .page-header .sub {
            font-size: 10px;
            color: #333;
        }

        .page-header .rule {
            border-bottom: 1px solid #999;
            margin-top: 3px;
        }

        h2 {
            font-size: 12px;
            margin: 10px 0 6px;
            background: #e5e5e5;
            padding: 3px 5px;
        }

        h3 {
            font-size: 11px;
            margin: 10px 0 3px;
            border-bottom: 1px solid #bbb;
            padding-bottom: 1px;
        }

        table.fees {
            width: 100%;
            border-collapse: collapse;
        }

        table.fees td, table.fees th {
            border: 0;
            padding: 2px 4px;
            vertical-align: top;
            text-align: left;
        }

        table.fees th {
            font-weight: bold;
            border-bottom: 1px solid #bbb;
        }

        table.fees .num {
            text-align: right;
            white-space: nowrap;
        }

        table.fees .athlete td {
            font-weight: bold;
            padding-top: 4px;
        }

        table.fees .start td.label {
            padding-left: 14px;
            color: #333;
        }

        table.fees .muted {
            color: #666;
            font-weight: normal;
        }

        table.fees .total td {
            border-top: 2px solid #333;
            font-weight: bold;
            padding-top: 3px;
        }

        .page-break {
            page-break-before: always;
        }

        .empty {
            color: #888;
            font-style: italic;
            padding: 8px 0;
        }
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
    <div class="sub">Meldegeld-Abrechnung</div>
    <div class="rule"></div>
</div>

{{-- Hinterlegte Gebühren (nur befüllte), damit die Abrechnung nachvollziehbar ist. --}}
@if($feeSchedule->isNotEmpty())
    <h2>Meldegebühren</h2>
    <table class="fees">
        @foreach($feeSchedule->groupBy('group') as $group => $lines)
            <tr>
                <td colspan="2"><h3>{{ $group }}</h3></td>
            </tr>
            @foreach($lines as $line)
                @php /** @var FeeScheduleLine $line */ @endphp
                <tr>
                    <td>{{ $line->label }}</td>
                    <td class="num">{{ Money::format($line->amountCents) }}</td>
                </tr>
            @endforeach
        @endforeach
    </table>
@endif

@if($statements->isEmpty())
    <div class="empty">Keine berechneten Meldungen.</div>
@else
    @if($showOverview)
        @php
            $hasLateFees = $statements->contains(fn (ClubFeeStatement $s): bool => $s->lateFees->isNotEmpty());
        @endphp
        <h2>Übersicht aller Vereine</h2>
        <table class="fees">
            <tr>
                <th>Verein</th>
                <th class="num">Athleten</th>
                <th class="num">Einzelstarts</th>
                <th class="num">Staffeln</th>
                <th class="num">Startgebühren</th>
                <th class="num">Staffelgebühren</th>
                <th class="num">Pauschalen</th>
                @if($hasLateFees)
                    <th class="num">Nachmeldungen</th>
                @endif
                <th class="num">Summe</th>
            </tr>
            @foreach($statements as $statement)
                @php /** @var ClubFeeStatement $statement */ @endphp
                <tr>
                    <td>{{ $statement->club->display_name }}</td>
                    <td class="num">{{ $statement->athletes->count() }}</td>
                    <td class="num">{{ $statement->startCount }}</td>
                    <td class="num">{{ $statement->relays->count() }}</td>
                    <td class="num">{{ Money::format($statement->startsCents()) }}</td>
                    <td class="num">{{ Money::format($statement->relaysCents()) }}</td>
                    <td class="num">{{ Money::format($statement->flatCents()) }}</td>
                    @if($hasLateFees)
                        <td class="num">{{ Money::format($statement->lateCents()) }}</td>
                    @endif
                    <td class="num">{{ Money::format($statement->totalCents) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td>Gesamt</td>
                <td class="num">{{ $statements->sum(fn (ClubFeeStatement $s) => $s->athletes->count()) }}</td>
                <td class="num">{{ $statements->sum('startCount') }}</td>
                <td class="num">{{ $statements->sum(fn (ClubFeeStatement $s) => $s->relays->count()) }}</td>
                <td class="num">{{ Money::format($statements->sum(fn (ClubFeeStatement $s) => $s->startsCents())) }}</td>
                <td class="num">{{ Money::format($statements->sum(fn (ClubFeeStatement $s) => $s->relaysCents())) }}</td>
                <td class="num">{{ Money::format($statements->sum(fn (ClubFeeStatement $s) => $s->flatCents())) }}</td>
                @if($hasLateFees)
                    <td class="num">{{ Money::format($statements->sum(fn (ClubFeeStatement $s) => $s->lateCents())) }}</td>
                @endif
                <td class="num">{{ Money::format($totalCents) }}</td>
            </tr>
        </table>
    @endif

    @foreach($statements as $statement)
        @php /** @var ClubFeeStatement $statement */ @endphp
        {{-- Neue Seite je Verein — außer für den allerersten Verein, wenn keine Übersicht davor steht. --}}
        <div @class(['page-break' => $showOverview || ! $loop->first])>
            <h2>{{ $statement->club->display_name }}</h2>

            <table class="fees">
                @if($statement->athletes->isNotEmpty())
                    <tr>
                        <td colspan="2"><h3>Einzelstarts</h3></td>
                    </tr>
                    @foreach($statement->athletes as $athleteFees)
                        @php /** @var AthleteFees $athleteFees */ @endphp
                        <tr class="athlete">
                            <td>
                                {{ $athleteFees->athlete->display_name }}
                                @if($athleteFees->starts->isEmpty())
                                    <span class="muted">(nur Staffel)</span>
                                @endif
                            </td>
                            <td class="num">{{ Money::format($athleteFees->totalCents) }}</td>
                        </tr>
                        @foreach($athleteFees->starts as $start)
                            @php /** @var FeeLine $start */ @endphp
                            <tr class="start">
                                <td class="label">{{ $start->label }}</td>
                                <td class="num">{{ Money::format($start->totalCents) }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                @endif

                @if($statement->relays->isNotEmpty())
                    <tr>
                        <td colspan="2"><h3>Staffeln</h3></td>
                    </tr>
                    @foreach($statement->relays as $relay)
                        @php /** @var FeeLine $relay */ @endphp
                        <tr>
                            <td>{{ $relay->label }}</td>
                            <td class="num">{{ Money::format($relay->totalCents) }}</td>
                        </tr>
                    @endforeach
                @endif

                @if($statement->flatFees->isNotEmpty())
                    <tr>
                        <td colspan="2"><h3>Pauschalen</h3></td>
                    </tr>
                    @foreach($statement->flatFees as $line)
                        @php /** @var FeeLine $line */ @endphp
                        <tr>
                            <td>{{ $line->label }} <span class="muted">({{ $line->quantity }} × {{ Money::format($line->unitCents) }})</span>
                            </td>
                            <td class="num">{{ Money::format($line->totalCents) }}</td>
                        </tr>
                    @endforeach
                @endif

                @if($statement->lateFees->isNotEmpty())
                    <tr>
                        <td colspan="2"><h3>Nachmeldegebühren</h3></td>
                    </tr>
                    @foreach($statement->lateFees as $line)
                        @php /** @var FeeLine $line */ @endphp
                        <tr>
                            <td>{{ $line->label }} <span class="muted">({{ $line->quantity }} × {{ Money::format($line->unitCents) }})</span>
                            </td>
                            <td class="num">{{ Money::format($line->totalCents) }}</td>
                        </tr>
                    @endforeach
                @endif

                <tr class="total">
                    <td>Summe</td>
                    <td class="num">{{ Money::format($statement->totalCents) }}</td>
                </tr>
            </table>
        </div>
    @endforeach
@endif

</body>
</html>
