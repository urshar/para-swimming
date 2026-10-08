@php
    use App\Support\TimeParser;
    use Illuminate\Support\Carbon;
@endphp
{{-- Eine Rekordzeile der Import-Vorschau: Klasse, Wertung, Bewerb, Zeit, Datum, Wettkampf, Typ und Bahn. --}}
@php
    $isRelayRow = ($rec['relay_count'] ?? 1) > 1;
    $genderLabel = match ($rec['gender'] ?? '') {
        'F' => 'Damen',
        'X' => 'Mixed',
        default => 'Herren',
    };
    $setDate = !empty($rec['set_date']) && strtotime($rec['set_date']) !== false
        ? Carbon::parse($rec['set_date'])->format('d.m.Y')
        : null;
    $meet = trim(($rec['meet_name'] ?? '').(!empty($rec['meet_city']) ? ', '.$rec['meet_city'] : ''), ', ');
    $splitCount = count($rec['splits'] ?? []);
@endphp
<flux:badge size="sm" color="blue">{{ $rec['sport_class'] }}</flux:badge>
<span class="text-zinc-900 dark:text-zinc-100">
    {{ $genderLabel }} · {{ $isRelayRow ? $rec['relay_count'].'×' : '' }}{{ $rec['distance'] }}m {{ $rec['stroke_name'] ?? '' }}
</span>
<span class="font-mono tabular-nums text-zinc-900 dark:text-zinc-100">{{ TimeParser::display($rec['swim_time'] ?? 0) }}</span>
@if($setDate)
    · {{ $setDate }}
@endif
@if($meet !== '')
    · {{ $meet }}
@endif
· {{ $rec['record_type'] }} · {{ $rec['course'] }}
@if($splitCount > 0)
    · {{ $splitCount }} {{ $splitCount === 1 ? 'Zwischenzeit' : 'Zwischenzeiten' }}
@endif
