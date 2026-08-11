@php
    use App\Support\QualificationAthleteSummary;
    use App\Support\QualificationRow;
    use App\Support\QualificationStatus;
    use App\Support\TimeParser;

    // Der Hinweis aus §11 erscheint nur, wenn tatsächlich eine umgerechnete Zeit enthalten
    // ist. Ein Hinweis, der immer dasteht, wird nicht mehr gelesen — und fehlt dann dort, wo
    // er zählt.
    $enthaeltSchaetzung = $entries->contains(
        fn (QualificationAthleteSummary $eintrag): bool => $eintrag->rows->contains(
            fn (QualificationRow $zeile): bool => $zeile->status->isEstimate()
        )
    );
@endphp
    <!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Förderansicht — {{ $championship->display_name }}</title>
    <style>
        * { box-sizing: border-box; }
        @page { margin: 20px 25px; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 10px; color: #1a1a1a; }
        h1 { font-size: 18px; margin: 0 0 4px 0; }
        .subtitle { font-size: 11px; color: #555; margin: 0 0 4px 0; }
        .meta { font-size: 9px; color: #777; margin: 0 0 18px 0; }
        h3 { font-size: 11px; margin: 14px 0 3px 0; color: #333; }
        .athlete-meta { font-size: 9px; color: #777; margin: 0 0 4px 0; }
        table { width: 100%; table-layout: fixed; border-collapse: collapse; }
        th { text-align: left; font-size: 9px; text-transform: uppercase; color: #555;
             border-bottom: 1px solid #bbb; padding: 4px 5px; }
        td { padding: 3px 5px; border-bottom: 1px solid #eee; }
        .num { font-family: 'DejaVu Sans Mono', monospace; }
        .muted { color: #888; }
        .miss { color: #b03030; }
        .est { color: #a06a10; }
        .filter { margin: 0 0 14px 0; padding: 5px 8px; border: 1px solid #ccc;
                  background-color: #f7f7f7; font-size: 9px; color: #444; }
        .note { margin-top: 20px; padding: 8px 10px; border: 1px solid #d9a441;
                background-color: #fdf6e6; font-size: 9px; line-height: 1.4; }
        .foot { margin-top: 14px; font-size: 8px; color: #999; }
    </style>
</head>
<body>

<h1>Förderansicht</h1>
<p class="subtitle">{{ $championship->display_name }}</p>
<p class="meta">
    Qualifikationszeitraum {{ $championship->qualification_start->format('d.m.Y') }}
    bis {{ $championship->qualification_end->format('d.m.Y') }} ·
    Normen auf {{ $championship->course }} ·
    Stand {{ $generatedAt->format('d.m.Y H:i') }}
</p>
<p class="meta">
    Planungswerkzeug, kein Nachweis. Der Abstand zur Norm wird auch bei Nichterfüllung
    ausgewiesen.
</p>

@if($selectionActive || $kader !== '' || $search !== '')
    <p class="filter">
        Eingeschränkt:
        @if($selectionActive) ausgewählte Athleten @endif
        @if($kader !== '') · Kaderart: {{ $kader }} @endif
        @if($search !== '') · Namenssuche: „{{ $search }}" @endif
    </p>
@endif

@foreach($entries as $eintrag)
    <h3>{{ $eintrag->athlete->full_name }}</h3>
    <p class="athlete-meta">
        {{ $eintrag->athlete->gender === 'M' ? 'männlich' : 'weiblich' }}
        · {{ $eintrag->displaySportClass() ?? '–' }}
        @if($eintrag->ageLabel($championship->year)) · {{ $eintrag->ageLabel($championship->year) }} @endif
        · {{ $eintrag->athlete->club?->display_name }}
    </p>

    <table>
        <thead>
        <tr>
            <th style="width: 22%;">Bewerb</th>
            <th style="width: 20%;">Leistung</th>
            <th style="width: 11%;">MQS</th>
            <th style="width: 11%;">ÖBSV</th>
            <th style="width: 11%;">Ziel</th>
            <th style="width: 25%;">Status</th>
        </tr>
        </thead>
        <tbody>
        @foreach($eintrag->rows as $zeile)
            <tr>
                <td>{{ $zeile->eventLabel }} {{ $zeile->sportClass }}</td>
                <td class="num">
                    @if($zeile->status->swimTime === null)
                        <span class="muted">–</span>
                    @else
                        {{ TimeParser::display($zeile->status->swimTime) }} {{ $zeile->status->course }}
                        @if($zeile->status->estimatedLcmTime !== null)
                            <span class="est">
                                → {{ TimeParser::display($zeile->status->estimatedLcmTime) }} geschätzt
                            </span>
                        @endif
                    @endif
                </td>
                <td class="num">{{ $zeile->standard?->formatted_mqs ?? '–' }}</td>
                <td class="num">{{ $zeile->standard?->formatted_obsv ?? '–' }}</td>
                <td class="num">
                    @if($zeile->targetTimeOtherCourse === null)
                        <span class="muted">–</span>
                    @else
                        {{ TimeParser::display($zeile->targetTimeOtherCourse) }}
                    @endif
                </td>
                <td>
                    @if($zeile->status->status === QualificationStatus::NOT_MET)
                        <span class="miss">{{ $zeile->status->label() }}</span>
                    @elseif($zeile->status->isEstimate())
                        <span class="est">{{ $zeile->status->label() }}</span>
                    @else
                        {{ $zeile->status->label() }}
                    @endif
                    @if($zeile->status->formattedGap())
                        <span class="num">{{ $zeile->status->formattedGap() }}</span>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>

    @php($ohneNorm = $eintrag->rowsWithoutStandard->map(fn (QualificationRow $z): string => $z->eventLabel)->unique()->sort()->values())

    @if($ohneNorm->isNotEmpty())
        <p class="athlete-meta">
            {{ $ohneNorm->count() }} weitere(r) Bewerb(e) ohne ausgeschriebene Norm:
            {{ $ohneNorm->join(', ') }}
        </p>
    @endif
@endforeach

@if($entries->isEmpty())
    <p class="muted">Keine Athleten mit Ergebnissen im Qualifikationszeitraum.</p>
@endif

@if($enthaeltSchaetzung)
    <div class="note">
        <strong>Hinweis:</strong><br>
        Mit „rechnerisch erreicht" gekennzeichnete Leistungen beruhen auf umgerechneten
        Kurzbahnzeiten. Sie sind kein Qualifikationsnachweis — international zählt ausschließlich
        eine auf der Langbahn geschwommene Zeit innerhalb des Qualifikationszeitraums.
    </div>
@endif

<p class="foot">Para Swimming NatDB · erzeugt am {{ $generatedAt->format('d.m.Y H:i') }}</p>

</body>
</html>
