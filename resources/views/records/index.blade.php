@extends('layouts.app')

@section('title', 'Rekordlisten')

@section('content')

    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Rekordlisten</h1>
        <flux:button href="{{ route('records.create') }}" variant="primary" icon="plus">Rekord eintragen</flux:button>
    </div>

    {{-- Typ-Tabs --}}
    <div class="flex gap-2 mb-4 flex-wrap">
        @foreach(['WR' => 'Weltrekorde', 'ER' => 'Europarekorde', 'NR' => 'Nationalrekorde', 'OR' => 'Olympische Rekorde'] as $type => $label)
            <a href="{{ route('records.index', ['type' => $type] + request()->except('type')) }}"
               class="px-4 py-2 rounded-lg text-sm font-medium transition-colors
               {{ $recordType === $type
                   ? 'bg-blue-600 text-white'
                   : 'bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-400 hover:border-blue-400' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>

    {{-- Filter --}}
    <form method="GET" class="flex flex-wrap gap-3 mb-4">
        <input type="hidden" name="type" value="{{ $recordType }}">
        <flux:input name="sport_class" value="{{ request('sport_class') }}" placeholder="Klasse z.B. S4" class="w-28" />
        <flux:select name="gender" placeholder="Geschlecht" class="w-36">
            <option value="">Alle</option>
            <option value="M" @selected(request('gender') === 'M')>Herren</option>
            <option value="F" @selected(request('gender') === 'F')>Damen</option>
        </flux:select>
        <flux:select name="course" placeholder="Bahn" class="w-36">
            <option value="">Alle Bahnen</option>
            <option value="LCM" @selected(request('course') === 'LCM')>LCM (50m)</option>
            <option value="SCM" @selected(request('course') === 'SCM')>SCM (25m)</option>
        </flux:select>
        <flux:button type="submit" icon="funnel">Filtern</flux:button>
    </form>

    <flux:table class="[&_td:first-child]:ps-4 [&_th:first-child]:ps-4 [&_td:last-child]:pe-4 [&_th:last-child]:pe-4">
        <flux:table.columns>
            <flux:table.column>Klasse</flux:table.column>
            <flux:table.column>Geschlecht</flux:table.column>
            <flux:table.column>Disziplin</flux:table.column>
            <flux:table.column>Bahn</flux:table.column>
            <flux:table.column>Zeit</flux:table.column>
            <flux:table.column>Athlet</flux:table.column>
            <flux:table.column>Datum</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse($records as $record)
                <flux:table.row>
                    <flux:table.cell>
                        <flux:badge size="sm" color="blue" class="font-mono">{{ $record->sport_class }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" color="{{ $record->gender === 'M' ? 'blue' : 'pink' }}">
                            {{ $record->gender === 'M' ? 'Herren' : 'Damen' }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell class="font-medium text-sm">
                        {{ $record->distance }}m {{ $record->strokeType?->name_de }}
                        @if($record->relay_count > 1)
                            <span class="text-zinc-400">{{ $record->relay_count }}x</span>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" color="zinc">{{ $record->course }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell class="font-mono font-bold text-zinc-900 dark:text-zinc-100">
                        {{ $record->formatted_swim_time }}
                    </flux:table.cell>
                    <flux:table.cell class="text-sm text-zinc-600 dark:text-zinc-400">
                        @if($record->athlete)
                            <a href="{{ route('athletes.show', $record->athlete) }}" class="hover:text-blue-600 transition-colors">
                                {{ $record->athlete->display_name }}
                            </a>
                            <span class="text-zinc-400">({{ $record->athlete->nation?->code }})</span>
                        @else
                            <span class="text-zinc-400">–</span>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="text-sm text-zinc-500">
                        {{ $record->set_date?->format('d.m.Y') ?? '–' }}
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:button href="{{ route('records.show', $record) }}" size="sm" variant="ghost" icon="eye" />
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="8" class="text-center py-12 text-zinc-400">
                        Keine Rekorde gefunden.
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <div class="mt-4">{{ $records->links() }}</div>

@endsection
