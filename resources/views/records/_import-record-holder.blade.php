{{-- Rekordhalter einer Zeile der Import-Vorschau: Athlet, bei Staffeln der Verein (oder "ohne Verein"). --}}
@if(!empty($rec['athlete']))
    <span class="font-medium text-zinc-900 dark:text-zinc-100">
        {{ $rec['athlete']['last_name'] ?? '' }}, {{ $rec['athlete']['first_name'] ?? '' }}
    </span>
    @if(!empty($rec['club']['name']))
        <div>{{ $rec['club']['name'] }}</div>
    @endif
@elseif(!empty($rec['club']['name']))
    <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $rec['club']['name'] }}</span>
    <div>Staffel</div>
@else
    <span class="text-amber-600 dark:text-amber-400">Staffel ohne Verein</span>
@endif
