@php
    use App\Models\Club;

    $locale = app()->getLocale();
    $levelLabel = $filter->isRegional()
        ? $filter->association.' – '.(Club::REGIONAL_ASSOCIATIONS[$filter->association] ?? $filter->association)
        : __('public.records.filter.level_national');

    $descriptionParts = [$levelLabel];
    if ($filter->youth) {
        $descriptionParts[] = __('public.records.filter.youth');
    }
    if ($filter->sportClass !== '') {
        $descriptionParts[] = $filter->sportClass;
    }
    if ($filter->gender !== '') {
        $descriptionParts[] = __('public.records.gender.'.$filter->gender);
    }
    if ($filter->course !== '') {
        $descriptionParts[] = $filter->course;
    }
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('public.records.title') }}</title>
    <style>
        * { box-sizing: border-box; }
        @page { margin: 18px 20px; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 9px; color: #1a1a1a; }
        h1 { font-size: 17px; margin: 0 0 4px 0; }
        .meta { font-size: 9px; color: #777; margin: 0 0 12px 0; }
        h2 { width: 100%; font-size: 12px; margin: 14px 0 5px 0; padding: 5px 8px;
             background-color: #f0f0f0; border: 1px solid #ddd; }
        table { width: 100%; table-layout: fixed; border-collapse: collapse; }
        th { text-align: left; font-size: 8px; text-transform: uppercase; color: #555;
             border-bottom: 1px solid #bbb; padding: 3px 4px; }
        td { padding: 3px 4px; border-bottom: 1px solid #eee; }
        .num { font-family: 'DejaVu Sans Mono', monospace; }
        .foot { margin-top: 12px; font-size: 8px; color: #999; }
    </style>
</head>
<body>

<h1>{{ __('public.records.title') }}</h1>

<p class="meta">
    {{ implode(' · ', $descriptionParts) }} ·
    {{ $generatedAt->format('d.m.Y H:i') }}
</p>

@if ($groups->isEmpty())
    <p>{{ __('public.records.empty') }}</p>
@else
    {{-- Je Schwimmart eine eigene Tabelle, in Verbandsreihenfolge (Frei/Rücken/Brust/Fly/Lagen)
         — dieselbe Gruppierung wie die Bildschirmansicht, siehe dort. --}}
    @foreach ($groups as $group)
        @php
            $strokeName = $locale === 'de'
                ? $group->stroke?->name_de
                : ($group->stroke?->name_en ?? $group->stroke?->name_de);
        @endphp
        <h2>{{ $strokeName ?? '—' }}</h2>
        <table>
            <thead>
            <tr>
                <th style="width: 8%;">{{ __('public.records.columns.distance') }}</th>
                <th style="width: 8%;">{{ __('public.records.columns.sport_class') }}</th>
                <th style="width: 8%;">{{ __('public.records.columns.gender') }}</th>
                <th style="width: 8%;">{{ __('public.records.columns.course') }}</th>
                <th style="width: 10%;">{{ __('public.records.columns.time') }}</th>
                <th style="width: 18%;">{{ __('public.records.columns.athlete') }}</th>
                <th style="width: 14%;">{{ __('public.records.columns.club') }}</th>
                <th style="width: 12%;">{{ __('public.records.columns.location') }}</th>
                <th style="width: 8%;">{{ __('public.records.columns.date') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($group->records as $record)
                <tr>
                    <td>
                        @if ($record->is_relay)
                            {{ $record->relay_count }}&times;
                        @endif
                        {{ $record->distance }}m
                    </td>
                    <td>{{ $record->sport_class }}</td>
                    <td>{{ __('public.records.gender.'.$record->gender) }}</td>
                    <td>{{ $record->course }}</td>
                    <td class="num">{{ $record->formatted_swim_time }}</td>
                    <td>
                        @if ($record->is_relay)
                            {{ $record->relayTeam->map->display_name->implode(', ') }}
                        @else
                            {{ $record->athlete?->full_name ?? '—' }}
                        @endif
                    </td>
                    <td>{{ $record->record_club_name ?? '—' }}</td>
                    <td>
                        @if ($record->meet_city)
                            {{ $record->meet_city }}{{ $record->meetNation ? ' ('.$record->meetNation->code.')' : '' }}
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ $record->set_date?->format('d.m.Y') ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endforeach
@endif

<p class="foot">{{ config('app.name', 'Para Swimming') }} &middot; {{ __('public.records.title') }}</p>

</body>
</html>
