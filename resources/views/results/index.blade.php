@extends('layouts.app')

@section('title', 'Ergebnisse')

@section('content')

    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 p-4 mb-4">
        <form method="GET" class="flex flex-wrap gap-3">
            <flux:select name="meet_id" placeholder="Wettkampf wählen…" class="flex-1 min-w-48">
                <option value="">Alle Wettkämpfe</option>
                @foreach($meets as $meet)
                    <option value="{{ $meet->id }}" @selected(request('meet_id') == $meet->id)>
                        {{ $meet->name }} ({{ $meet->start_date->format('d.m.Y') }})
                    </option>
                @endforeach
            </flux:select>
            <flux:input name="search" placeholder="Athlet suchen…" value="{{ request('search') }}"
                        class="flex-1 min-w-48"/>
            <flux:select name="status" class="w-40">
                <option value="">Alle Status</option>
                <option value="valid" @selected(request('status') === 'valid')>Nur gültige</option>
                <option value="DSQ" @selected(request('status') === 'DSQ')>DSQ</option>
                <option value="DNS" @selected(request('status') === 'DNS')>DNS</option>
                <option value="DNF" @selected(request('status') === 'DNF')>DNF</option>
            </flux:select>
            <flux:button type="submit" variant="filled" size="sm">Filtern</flux:button>
            @if(request()->hasAny(['meet_id', 'search', 'status']))
                <flux:button href="{{ route('results.index') }}" size="sm">Zurücksetzen</flux:button>
            @endif
        </form>
    </div>

    <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 overflow-hidden">
        <flux:table
            class="[&_td:first-child]:ps-4 [&_th:first-child]:ps-4 [&_td:last-child]:pe-4 [&_th:last-child]:pe-4">
            <flux:table.columns>
                <flux:table.column>Athlet</flux:table.column>
                <flux:table.column>Disziplin</flux:table.column>
                <flux:table.column>Klasse</flux:table.column>
                <flux:table.column>Zeit</flux:table.column>
                <flux:table.column>Platz</flux:table.column>
                <flux:table.column>Rekorde</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse($results as $result)
                    <flux:table.row>
                        <flux:table.cell class="font-medium text-zinc-900 dark:text-white">
                            <a href="{{ route('athletes.show', $result->athlete) }}"
                               class="hover:text-blue-600 dark:hover:text-blue-400">
                                {{ $result->athlete?->display_name }}
                            </a>
                        </flux:table.cell>
                        <flux:table.cell class="text-sm text-zinc-500 dark:text-zinc-400">
                            {{ $result->swimEvent?->display_name }}
                        </flux:table.cell>
                        <flux:table.cell>
                            @if($result->sport_class)
                                <flux:badge size="sm">{{ $result->sport_class }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="font-mono text-sm font-semibold text-zinc-900 dark:text-white">
                            {{ $result->formatted_swim_time }}
                        </flux:table.cell>
                        <flux:table.cell class="text-zinc-500 dark:text-zinc-400 text-sm">
                            {{ $result->place ? '#' . $result->place : '–' }}
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex gap-1">
                                @if($result->is_world_record)
                                    <flux:badge size="sm" color="yellow">WR</flux:badge>
                                @endif
                                @if($result->is_european_record)
                                    <flux:badge size="sm" color="blue">ER</flux:badge>
                                @endif
                                @if($result->is_national_record)
                                    <flux:badge size="sm" color="green">NR</flux:badge>
                                @endif
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if($result->status)
                                <flux:badge size="sm"
                                            color="{{ in_array($result->status, ['DSQ','DNS','DNF']) ? 'red' : 'zinc' }}">
                                    {{ $result->status }}
                                </flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="text-right">
                            <flux:button href="{{ route('results.show', $result) }}" size="xs" variant="ghost"
                                         icon="eye"/>
                            <flux:button href="{{ route('results.edit', $result) }}" size="xs" variant="ghost"
                                         icon="pencil"/>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="8" class="text-center text-zinc-400 py-12">
                            Keine Ergebnisse gefunden.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
        @if($results->hasPages())
            <div class="p-4 border-t border-zinc-200 dark:border-zinc-800">{{ $results->links() }}</div>
        @endif
    </div>

@endsection
