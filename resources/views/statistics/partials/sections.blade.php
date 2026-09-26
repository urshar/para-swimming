@use('App\Support\ReportConfiguration')
@use('Illuminate\Database\Eloquent\Collection')
@use('Illuminate\Support\Carbon')
@php
    /**
     * Jahresbericht (Spec Phase 13).
     *
     * Eigenständiges HTML-Dokument, damit dieselbe Vorlage sowohl im Browser
     * als auch für den PDF-Export verwendet werden kann. Bewusst ohne Flux —
     * PDF-Renderer verarbeiten kein Komponenten-Markup.
     *
     * Die Vorlage rechnet nichts: Sie stellt ausschließlich dar, was der
     * StatisticsService geliefert hat.
     *
     * @var ReportConfiguration $config
     * @var array<string, mixed> $statistics
     * @var Collection $selectedMeets
     */
    $sectionNumber = 0;
    $section = function (string $key) use ($statistics, &$sectionNumber): ?int {
        return array_key_exists($key, $statistics) ? ++$sectionNumber : null;
    };
    $date = fn (?string $value): string => $value
        ? Carbon::parse($value)->format('d.m.Y')
        : '—';
@endphp

{{-- Gestaltung des Inhalts. Steht bewusst hier beim zugehörigen Markup und
     nicht in den Wrappern, damit Stil und Auszeichnung zusammen gepflegt
     werden. Bewusst ohne Nachkommastellen bei Pixelwerten, da diese je nach
     Renderer unterschiedlich gerundet werden. --}}
<style>
    * { box-sizing: border-box; }

    h1 { font-size: 16px; margin: 0 0 4px; }

    h2 {
        font-size: 12px;
        margin: 16px 0 6px;
        padding: 5px 7px;
        background-color: #f0f0f0;
        border: 1px solid #ddd;
    }

    h3 { font-size: 10px; margin: 10px 0 4px; color: #555; }

    .meta { color: #666; font-size: 9px; margin: 0 0 3px; }

    /*
     * Bewusst OHNE table-layout: fixed — dompdf verteilt die Spalten sonst
     * gleichmäßig, wodurch lange Vereins- und Veranstaltungsnamen umbrechen;
     * mehrzeilige Zeilen bringen dann den Seitenumbruch durcheinander.
     */
    table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 6px;
    }

    /* Kopfzeile mehrseitiger Tabellen auf jeder Seite wiederholen. */
    thead { display: table-header-group; }

    th {
        text-align: left;
        font-size: 9px;
        text-transform: uppercase;
        color: #666;
        background-color: #fafafa;
        border-bottom: 1px solid #ddd;
        padding: 3px 5px;
    }

    /*
     * vertical-align:top hält bei mehrzeiligen Zeilen (umgebrochene lange
     * Veranstaltungsnamen) die Zahlenspalten auf Höhe der ersten Textzeile.
     *
     * Hinweis zur Linksbündigkeit umgebrochener Namen: Die Folgezeilen langer
     * Namen rutschten im PDF zeitweise nach rechts. Ursache war NICHT diese
     * Tabelle, sondern ein float innerhalb der position:fixed-Fußzeile des
     * PDF-Wrappers (dompdf verschiebt dadurch umgebrochene Tabellenzellen an
     * anderer Stelle im Dokument). Behoben in pdf/statistics-report (Fußzeile
     * über eine Tabelle statt float); die Namenszellen brauchen daher kein
     * eigenes text-align — <td> ist ohnehin linksbündig.
     */
    td {
        padding: 3px 5px;
        border-bottom: 1px solid #eee;
        vertical-align: top;
    }

    /* Zahlenspalten schmal halten, damit die Textspalten Platz bekommen. */
    td.num, th.num { text-align: right; width: 70px; }

    /*
     * Status je Veranstaltung hat acht Zahlenspalten. Mit den Standard-70px
     * (oder auch 40px) bleibt der Veranstaltungsspalte zu wenig Platz — lange
     * Namen brechen auf viele Zeilen um, die Tabelle wird sehr hoch und dompdf
     * verteilt sie über unnötig viele Seiten. Schmale Zahlenspalten (Zählwerte
     * mit 1–4 Stellen) und normal geschriebene Kopfzeilen halten sie kompakt.
     */
    .status-by-meet td.num, .status-by-meet th.num { width: 28px; }
    .status-by-meet th { text-transform: none; }

    .kpis { margin: 8px 0 6px; }
    .kpis th { text-align: center; }
    .kpis td.value {
        text-align: center;
        font-size: 14px;
        font-weight: bold;
        padding: 6px 5px;
    }

    .empty { color: #999; font-style: italic; padding: 4px; }
    .note { color: #666; font-size: 8px; margin: 3px 0 0; }

    /* Ranglisten einer Wertungskategorie möglichst nicht umbrechen. */
    .avoid-break { page-break-inside: avoid; }
</style>


{{--
    Gemeinsamer Inhalt des Jahresberichts.

    Wird sowohl von der Browser-Ansicht (statistics.report) als auch von der
    PDF-Ansicht (pdf.statistics-report) eingebunden. Beide Wrapper bringen
    nur Dokumentrahmen und CSS mit; die Abschnitte selbst stehen ausschließlich
    hier, damit sie nicht doppelt gepflegt werden müssen.
--}}


<h1>Jahresbericht {{ $config->year }}</h1>
<p class="meta">
    ÖBSV Para Swimming &middot; Zeitraum {{ $config->dateFrom->format('d.m.Y') }}
    bis {{ $config->dateTo->format('d.m.Y') }}
</p>
<p class="meta">
    @if($config->isMeetFiltered())
        Ausgewertete Veranstaltungen ({{ $selectedMeets->count() }}):
    @else
        Alle Veranstaltungen des Zeitraums ({{ $selectedMeets->count() }}):
    @endif
    {{ $selectedMeets->pluck('name')->implode(', ') ?: 'keine' }}
</p>

{{-- ── Allgemeiner Überblick ───────────────────────────────────────────── --}}
@if($number = $section('overview'))
    @php $overview = $statistics['overview']; @endphp
    <h2>{{ $number }}. Allgemeiner Überblick</h2>

    <table class="kpis">
        <thead>
        <tr><th>Veranstaltungen</th><th>Teilnehmer</th><th>Teilnahmen</th><th>Starts</th><th>Vereine (AUT)</th></tr>
        </thead>
        <tbody>
        <tr><td class="value">{{ $overview['meets'] }}</td><td class="value">{{ $overview['participants'] }}</td><td class="value">{{ $overview['participations'] }}</td><td class="value">{{ $overview['starts'] }}</td><td class="value">{{ $overview['clubs'] }}</td></tr>
        </tbody>
    </table>

    <p class="note">
        Ausländische Vereine: {{ $overview['foreign_clubs'] }} &middot;
        Sportler mit mindestens {{ $overview['min_participations'] }} Teilnahmen:
        {{ $overview['athletes_with_min_participations'] }}
    </p>

    <h3>Ergebnisse nach Status</h3>
    <table>
        <thead>
        <tr>
            <th>Regulär</th><th class="num">EXH</th><th class="num">DSQ</th><th class="num">DNF</th>
            <th class="num">DNS</th><th class="num">SICK</th><th class="num">WDR</th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td class="num">{{ $overview['status_breakdown']['regular'] }}</td>
            <td class="num">{{ $overview['status_breakdown']['EXH'] }}</td>
            <td class="num">{{ $overview['status_breakdown']['DSQ'] }}</td>
            <td class="num">{{ $overview['status_breakdown']['DNF'] }}</td>
            <td class="num">{{ $overview['status_breakdown']['DNS'] }}</td>
            <td class="num">{{ $overview['status_breakdown']['SICK'] }}</td>
            <td class="num">{{ $overview['status_breakdown']['WDR'] }}</td>
        </tr>
    
        </tbody>
    </table>
    <p class="note">Als Start zählen reguläre Ergebnisse sowie EXH, DSQ und DNF.</p>
@endif

{{-- ── 5-Jahres-Vergleich ──────────────────────────────────────────────── --}}
@if($number = $section('multi_year'))
    @php
        $multiYear = $statistics['multi_year'];
        $years = $multiYear['years'];
        $rowsByYear = collect($multiYear['rows'])->keyBy('year');
        $genderLabels = ['M' => 'Herren', 'F' => 'Damen', 'N' => 'Nicht binär'];
        $relayGenderLabels = ['M' => 'Herren', 'F' => 'Damen', 'X' => 'Mixed'];
        // Farben für die optionalen Grafiken (identisch zur Jahresvergleich-Seite).
        $multiYearHex = ['M' => '#3b82f6', 'F' => '#ec4899', 'N' => '#f59e0b', 'X' => '#8b5cf6'];

        // Die drei Vergleichstabellen: Feld-Präfix, welche Geschlechter, welche Beschriftung.
        $multiYearTables = [
            ['title' => 'Einzelstarts nach Geschlecht', 'prefix' => 'starts',
                'genders' => $multiYear['individual_genders'], 'labels' => $genderLabels],
            ['title' => 'Teilnehmer nach Geschlecht', 'prefix' => 'participants',
                'genders' => $multiYear['individual_genders'], 'labels' => $genderLabels],
            ['title' => 'Staffelstarts nach Typ', 'prefix' => 'relay',
                'genders' => $multiYear['relay_genders'], 'labels' => $relayGenderLabels],
        ];
    @endphp
    <h2>{{ $number }}. 5-Jahres-Vergleich ({{ $years[0] }}–{{ $years[count($years) - 1] }})</h2>

    @foreach($multiYearTables as $myTable)
        <h3>{{ $myTable['title'] }}</h3>

        @if($showCharts ?? false)
            @php
                $svgSeries = array_map(fn (string $g): array => [
                    'label' => $myTable['labels'][$g],
                    'color' => $multiYearHex[$g] ?? '#71717a',
                    'values' => array_map(fn (int $y): int => $rowsByYear[$y][$myTable['prefix'].'_'.strtolower($g)], $years),
                ], $myTable['genders']);
            @endphp
            <x-trend-chart :chart="\App\Support\TrendChart::fromSeries($years, $svgSeries)"
                           :for-pdf="$forPdf ?? false"/>
        @endif

        <table>
            <thead>
            <tr>
                <th>Geschlecht</th>
                @foreach($years as $y)
                    <th class="num">{{ $y }}</th>
                @endforeach
            </tr>
            </thead>
            <tbody>
            @foreach($myTable['genders'] as $g)
                <tr>
                    <td>{{ $myTable['labels'][$g] }}</td>
                    @foreach($years as $y)
                        <td class="num">{{ $rowsByYear[$y][$myTable['prefix'].'_'.strtolower($g)] }}</td>
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </table>
    @endforeach

    <p class="note">
        Volle Kalenderjahre, verankert am Berichtsjahr; unabhängig von einer Veranstaltungsauswahl.
        Staffelstarts pro eingesetztem Schwimmer.
    </p>
@endif

{{-- ── Teilnehmer und Starts pro Veranstaltung ─────────────────────────── --}}
@if($number = $section('meets'))
    <h2>{{ $number }}. Teilnehmer und Starts pro Veranstaltung</h2>
    <table>
        <thead>
        <tr><th>Veranstaltung</th><th>Datum</th><th class="num">Teilnehmer</th><th class="num">Starts</th></tr>
        </thead>
        <tbody>
        @forelse($statistics['meets'] as $row)
            <tr>
                <td>{{ $row['meet'] }}</td>
                <td>{{ $date($row['start_date']) }}</td>
                <td class="num">{{ $row['participants'] }}</td>
                <td class="num">{{ $row['starts'] }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="empty">Keine Veranstaltungen mit Starts im Zeitraum.</td></tr>
        @endforelse

        </tbody>
    </table>
@endif

{{-- ── Status je Veranstaltung ──────────────────────────────────────────── --}}
@if($number = $section('status_by_meet'))
    @php
        $statusOrder = ['regular', 'EXH', 'DSQ', 'DNS', 'DNF', 'SICK', 'WDR'];
        $statusHead = ['regular' => 'Regulär', 'EXH' => 'EXH', 'DSQ' => 'DSQ',
            'DNS' => 'DNS', 'DNF' => 'DNF', 'SICK' => 'SICK', 'WDR' => 'WDR'];
    @endphp
    <h2>{{ $number }}. Status je Veranstaltung</h2>
    <table class="status-by-meet">
        <thead>
        <tr>
            <th>Veranstaltung</th>
            @foreach($statusOrder as $s)
                <th class="num">{{ $statusHead[$s] }}</th>
            @endforeach
            <th class="num">Gesamt</th>
        </tr>
        </thead>
        <tbody>
        @forelse($statistics['status_by_meet'] as $row)
            <tr>
                <td>{{ $row['meet'] }}</td>
                @foreach($statusOrder as $s)
                    <td class="num">{{ $row['statuses'][$s] }}</td>
                @endforeach
                <td class="num">{{ $row['total'] }}</td>
            </tr>
        @empty
            <tr><td colspan="9" class="empty">Keine Veranstaltungen mit Ergebnissen im Zeitraum.</td></tr>
        @endforelse
        </tbody>
    </table>
    <p class="note">Regulär = gewertetes Ergebnis (ohne Sonderstatus). Nur Einzelbewerbe.</p>
@endif

{{-- ── Teilnehmerstruktur ──────────────────────────────────────────────── --}}
@if($number = $section('participants'))
    @php $participants = $statistics['participants']; @endphp
    <h2>{{ $number }}. Teilnehmer nach Altersgruppe und Geschlecht</h2>

    <h3>Nach Altersgruppe</h3>
    <table>
        <thead>
        <tr><th>Altersgruppe</th><th class="num">Teilnehmer</th><th class="num">Starts</th></tr>
        </thead>
        <tbody>
        @forelse($participants['by_age_group'] as $row)
            <tr>
                <td>{{ $row['age_group_name'] }}</td>
                <td class="num">{{ $row['participants'] }}</td>
                <td class="num">{{ $row['starts'] }}</td>
            </tr>
        @empty
            <tr><td colspan="3" class="empty">Keine Daten im Zeitraum.</td></tr>
        @endforelse
    
        </tbody>
    </table>

    <h3>Nach Geschlecht</h3>
    <table>
        <thead>
        <tr><th>Geschlecht</th><th class="num">Teilnehmer</th><th class="num">Starts</th></tr>
        </thead>
        <tbody>
        @forelse($participants['by_gender'] as $row)
            <tr>
                <td>{{ ['M' => 'Herren', 'F' => 'Damen', 'N' => 'Nicht binär'][$row['gender']] ?? $row['gender'] }}</td>
                <td class="num">{{ $row['participants'] }}</td>
                <td class="num">{{ $row['starts'] }}</td>
            </tr>
        @empty
            <tr><td colspan="3" class="empty">Keine Daten im Zeitraum.</td></tr>
        @endforelse
    
        </tbody>
    </table>

    <h3>Nach Altersgruppe und Geschlecht</h3>
    <table>
        <thead>
        <tr><th>Altersgruppe</th><th>Geschlecht</th><th class="num">Teilnehmer</th><th class="num">Starts</th></tr>
        </thead>
        <tbody>
        @forelse($participants['by_age_group_and_gender'] as $row)
            <tr>
                <td>{{ $row['age_group_name'] }}</td>
                <td>{{ ['M' => 'Herren', 'F' => 'Damen', 'N' => 'Nicht binär'][$row['gender']] ?? $row['gender'] }}</td>
                <td class="num">{{ $row['participants'] }}</td>
                <td class="num">{{ $row['starts'] }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="empty">Keine Daten im Zeitraum.</td></tr>
        @endforelse
    
        </tbody>
    </table>
@endif

{{-- ── Vereinsstatistik ────────────────────────────────────────────────── --}}
@if($number = $section('clubs'))
    <h2>{{ $number }}. Vereinsstatistik</h2>
    <table>
        <thead>
        <tr><th class="num">Rang</th><th>Verein</th><th>Nation</th><th class="num">Teilnehmer</th><th class="num">Starts</th></tr>
        </thead>
        <tbody>
        @forelse($statistics['clubs'] as $row)
            <tr>
                <td class="num">{{ $row['rank'] }}</td>
                <td>{{ $row['club'] }}</td>
                <td>{{ $row['nation'] ?? '—' }}</td>
                <td class="num">{{ $row['participants'] }}</td>
                <td class="num">{{ $row['starts'] }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="empty">Keine Daten im Zeitraum.</td></tr>
        @endforelse
    
        </tbody>
    </table>
@endif

{{-- ── Sportlerstatistik ───────────────────────────────────────────────── --}}
@if($number = $section('athletes'))
    <h2>{{ $number }}. Sportlerstatistik</h2>
    <table>
        <thead>
        <tr><th class="num">Rang</th><th>Sportler</th><th>Nation</th><th class="num">Teilnahmen</th><th class="num">Starts</th></tr>
        </thead>
        <tbody>
        @forelse($statistics['athletes'] as $row)
            <tr>
                <td class="num">{{ $row['rank'] }}</td>
                <td>{{ $row['athlete'] }}</td>
                <td>{{ $row['nation'] ?? '—' }}</td>
                <td class="num">{{ $row['participations'] }}</td>
                <td class="num">{{ $row['starts'] }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="empty">Keine Daten im Zeitraum.</td></tr>
        @endforelse
    
        </tbody>
    </table>
@endif

{{-- ── Ausländische Teilnehmer ─────────────────────────────────────────── --}}
@if($number = $section('nations'))
    @php $foreign = $statistics['nations']->where('nation', '!=', 'AUT'); @endphp
    <h2>{{ $number }}. Ausländische Teilnehmer</h2>
    <table>
        <thead>
        <tr><th>Nation</th><th>Bezeichnung</th><th class="num">Teilnehmer</th><th class="num">Starts</th></tr>
        </thead>
        <tbody>
        @forelse($foreign as $row)
            <tr>
                <td>{{ $row['nation'] }}</td>
                <td>{{ $row['nation_name'] }}</td>
                <td class="num">{{ $row['participants'] }}</td>
                <td class="num">{{ $row['starts'] }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="empty">Keine ausländischen Teilnehmer im Zeitraum.</td></tr>
        @endforelse
    
        </tbody>
    </table>
    <p class="note">Die Zuordnung erfolgt über die Nation des Sportlers, nicht über die des Vereins.</p>
@endif

{{-- ── Behinderungsgruppen und Sportklassen ────────────────────────────── --}}
@if($number = $section('sport_classes'))
    @php $sportClasses = $statistics['sport_classes']; @endphp
    <h2>{{ $number }}. Behinderungsgruppen</h2>
    <table>
        <thead>
        <tr><th>Gruppe</th><th class="num">Teilnehmer</th><th class="num">Starts</th></tr>
        </thead>
        <tbody>
        @forelse($sportClasses['by_disability_group'] as $row)
            <tr>
                {{-- Der Gruppenname enthält den Code je nach Stammdatenpflege
                     bereits; er wird deshalb nicht zusätzlich angehängt. --}}
                <td>{{ $row['group_name'] }}</td>
                <td class="num">{{ $row['participants'] }}</td>
                <td class="num">{{ $row['starts'] }}</td>
            </tr>
        @empty
            <tr><td colspan="3" class="empty">Keine Daten im Zeitraum.</td></tr>
        @endforelse
    
        </tbody>
    </table>

    <h2>{{ ++$sectionNumber }}. Sportklassen</h2>
    <table>
        <thead>
        <tr><th>Sportklasse</th><th>Behinderungsgruppe</th><th class="num">Teilnehmer</th><th class="num">Starts</th></tr>
        </thead>
        <tbody>
        @forelse($sportClasses['by_sport_class'] as $row)
            <tr>
                <td>{{ $row['sport_class'] }}</td>
                <td>{{ $row['group_code'] ?? 'Ohne Zuordnung' }}</td>
                <td class="num">{{ $row['participants'] }}</td>
                <td class="num">{{ $row['starts'] }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="empty">Keine Daten im Zeitraum.</td></tr>
        @endforelse
    
        </tbody>
    </table>
@endif

{{-- ── Rekorde ─────────────────────────────────────────────────────────── --}}
@if($number = $section('records'))
    @php $records = $statistics['records']; @endphp
    <h2>{{ $number }}. Rekorde</h2>

    <table class="kpis">
        <thead>
        <tr><th>Gesamt</th><th>Österreich</th><th>Jugend</th><th>Staffel</th><th>Ohne Sportler</th></tr>
        </thead>
        <tbody>
        <tr><td class="value">{{ $records['overview']['total'] }}</td><td class="value">{{ $records['overview']['austrian'] }}</td><td class="value">{{ $records['overview']['austrian_junior'] }}</td><td class="value">{{ $records['overview']['relay'] }}</td><td class="value">{{ $records['overview']['without_athlete'] }}</td></tr>
        </tbody>
    </table>

    <h3>Nach Rekordart</h3>
    <table>
        <thead>
        <tr><th>Rekordart</th><th class="num">Anzahl</th></tr>
        </thead>
        <tbody>
        @forelse($records['by_record_type'] as $row)
            <tr><td>{{ $row['record_type'] }}</td><td class="num">{{ $row['records'] }}</td></tr>
        @empty
            <tr><td colspan="2" class="empty">Keine Rekorde im Zeitraum.</td></tr>
        @endforelse
    
        </tbody>
    </table>

    <h3>Rekorde pro Sportler</h3>
    <table>
        <thead>
        <tr><th class="num">Rang</th><th>Sportler</th><th class="num">Rekorde</th></tr>
        </thead>
        <tbody>
        @forelse($records['by_athlete'] as $row)
            <tr>
                <td class="num">{{ $row['rank'] }}</td>
                <td>{{ $row['athlete'] }}</td>
                <td class="num">{{ $row['records'] }}</td>
            </tr>
        @empty
            <tr><td colspan="3" class="empty">Keine Rekorde im Zeitraum.</td></tr>
        @endforelse
    
        </tbody>
    </table>
@endif

{{-- ── ÖBSV Cup ────────────────────────────────────────────────────────── --}}
@if($number = $section('cup'))
    <h2>{{ $number }}. ÖBSV Cup {{ $config->year }}</h2>
    @forelse($statistics['cup'] as $bracket)
        <div class="avoid-break">
            <h3>
                {{ $bracket['group_name'] }}
                @if($bracket['age_group_name']) &middot; {{ $bracket['age_group_name'] }} @endif
                &middot;
                {{ ['M' => 'Herren', 'F' => 'Damen'][$bracket['gender']] ?? 'Damen & Herren' }}
            </h3>
            <table>
        <thead>
        <tr><th class="num">Rang</th><th>Sportler</th><th>Verein</th><th class="num">Punkte</th></tr>
        </thead>
        <tbody>
                @foreach($bracket['results'] as $result)
                    <tr>
                        <td class="num">{{ $result->rank }}</td>
                        <td>{{ $result->athlete?->display_name }}</td>
                        <td>{{ $result->club?->name }}</td>
                        <td class="num">{{ $result->total_points }}</td>
                    </tr>
                @endforeach
            
        </tbody>
    </table>
        </div>
    @empty
        <p class="empty">Für {{ $config->year }} liegt keine berechnete Cup-Gesamtwertung vor.</p>
    @endforelse
@endif

{{-- ── Meisterschaften (ÖBM / ÖJM) ─────────────────────────────────────── --}}
@foreach(['oebm' => 'Österreichische Meisterschaften (ÖBM)', 'oejm' => 'Österreichische Jugendmeisterschaften (ÖJM)'] as $key => $title)
    @if($number = $section($key))
        <h2>{{ $number }}. {{ $title }}</h2>
        @php $championship = $statistics[$key]; @endphp

        @if(count($championship) > 0)
            <table class="kpis">
        <thead>
        <tr><th>Veranstaltungen</th><th>Teilnehmer</th><th>Teilnahmen</th><th>Starts</th><th>Vereine (AUT)</th></tr>
        </thead>
        <tbody>
        <tr><td class="value">{{ $championship['overview']['meets'] }}</td><td class="value">{{ $championship['overview']['participants'] }}</td><td class="value">{{ $championship['overview']['participations'] }}</td><td class="value">{{ $championship['overview']['starts'] }}</td><td class="value">{{ $championship['overview']['clubs'] }}</td></tr>
        </tbody>
    </table>

            <table>
        <thead>
        <tr><th>Veranstaltung</th><th>Datum</th><th class="num">Teilnehmer</th><th class="num">Starts</th></tr>
        </thead>
        <tbody>
                @foreach($championship['meets'] as $row)
                    <tr>
                        <td>{{ $row['meet'] }}</td>
                        <td>{{ $date($row['start_date']) }}</td>
                        <td class="num">{{ $row['participants'] }}</td>
                        <td class="num">{{ $row['starts'] }}</td>
                    </tr>
                @endforeach
            
        </tbody>
    </table>

            <h3>Teilnehmende Sportler</h3>
            <table>
        <thead>
        <tr><th class="num">Rang</th><th>Sportler</th><th class="num">Teilnahmen</th><th class="num">Starts</th></tr>
        </thead>
        <tbody>
                @foreach($championship['athletes'] as $row)
                    <tr>
                        <td class="num">{{ $row['rank'] }}</td>
                        <td>{{ $row['athlete'] }}</td>
                        <td class="num">{{ $row['participations'] }}</td>
                        <td class="num">{{ $row['starts'] }}</td>
                    </tr>
                @endforeach
            
        </tbody>
    </table>
        @else
            <p class="empty">Für diesen Abschnitt wurden keine Veranstaltungen ausgewählt.</p>
        @endif
    @endif
@endforeach

@if($sectionNumber === 0)
    <p class="empty">Es wurde kein Abschnitt für den Bericht ausgewählt.</p>
@endif

