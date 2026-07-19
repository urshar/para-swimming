<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Qualifikation {{ $list->year }}</title>
    <style>
        @page {
            margin: 25px 30px;
        }

        body {
            font-family: Helvetica, Arial, sans-serif;
            font-size: 10px;
            color: #1a1a1a;
        }

        h1 {
            font-size: 18px;
            margin: 0 0 4px 0;
        }

        .subtitle {
            font-size: 11px;
            color: #555;
            margin: 0 0 4px 0;
        }

        .filters {
            font-size: 10px;
            color: #888;
            margin: 0 0 20px 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 4px;
        }

        th, td {
            padding: 4px 6px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        th {
            background-color: #fafafa;
            font-size: 9px;
            text-transform: uppercase;
            color: #666;
        }

        td.time, th.time {
            width: 65px;
            text-align: right;
            font-family: Courier, monospace;
        }

        .footer {
            margin-top: 24px;
            font-size: 9px;
            color: #999;
        }
    </style>
</head>
<body>
<h1>Qualifikation ÖSTM & ÖM {{ $list->year }}</h1>
<p class="subtitle">{{ $qualifications->count() }} Schwimmer</p>

@php
    $activeFilters = [];
    if (!empty($filters['stroke_type_id']) && !empty($filters['distance'])) {
        $event = $events->firstWhere(fn ($e) => $e['stroke_type_id'] == $filters['stroke_type_id'] && $e['distance'] == $filters['distance']);
        $activeFilters[] = 'Bewerb: '.($event['label'] ?? '?');
    }
    if (!empty($filters['gender'])) {
        $activeFilters[] = 'Geschlecht: '.$filters['gender'];
    }
    if (!empty($filters['sport_class'])) {
        $activeFilters[] = 'Sportklasse: '.$filters['sport_class'];
    }
    if (!empty($filters['club_id'])) {
        $club = $clubs->firstWhere('id', $filters['club_id']);
        $activeFilters[] = 'Verein: '.($club->display_name ?? $club->name ?? '?');
    }
    if (!empty($filters['search'])) {
        $activeFilters[] = 'Suche: "'.$filters['search'].'"';
    }
@endphp

@if(count($activeFilters) > 0)
    <p class="filters">Gefiltert nach: {{ implode(' · ', $activeFilters) }}</p>
@endif

<table>
    <thead>
    <tr>
        <th>Name</th>
        <th>Verein</th>
        <th>Geschl.</th>
        <th>Klasse</th>
        <th class="time">Zeit</th>
        <th class="time">Richtzeit</th>
        <th>Punkte</th>
        <th>Datum</th>
    </tr>
    </thead>
    <tbody>
    @forelse($sections as $section)
        <tr>
            <td colspan="8" style="background-color:#e5e5e5;font-weight:bold;font-size:11px;padding-top:8px;">
                {{ $section['group']?->name_de ?? 'Sonstige Sportklassen' }}
            </td>
        </tr>
        @foreach($section['strokes'] as $strokeGroup)
            <tr>
                <td colspan="8" style="background-color:#f5f5f5;font-weight:bold;">
                    {{ $strokeGroup['distance'].'m '.($strokeGroup['stroke']?->name_de ?? 'Unbekannte Lage') }}
                </td>
            </tr>
            @foreach($strokeGroup['items'] as $q)
                <tr>
                    <td>{{ $q->athlete?->last_name }}, {{ $q->athlete?->first_name }}</td>
                    <td>{{ $q->club?->display_name ?? $q->club?->name ?? '–' }}</td>
                    <td>{{ $q->qualifyingTime->gender }}</td>
                    <td>{{ $q->sport_class }}</td>
                    <td class="time">{{ $q->formatted_swim_time }}</td>
                    <td class="time">{{ $q->qualifyingTime->formatted_value }}</td>
                    <td>{{ $q->points ?? '–' }}</td>
                    <td>{{ $q->qualified_at->format('d.m.Y') }}</td>
                </tr>
            @endforeach
        @endforeach
    @empty
        <tr>
            <td colspan="8">Keine Qualifikationen gefunden.</td>
        </tr>
    @endforelse
    </tbody>
</table>

<p class="footer">
    Para Swimming NatDB — ÖBSV — erstellt am {{ now()->format('d.m.Y H:i') }} Uhr
</p>
</body>
</html>
