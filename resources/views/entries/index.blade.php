@extends('layouts.app')

@section('title', 'Meldungen')

@section('content')

    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 p-4 mb-4">
        <form method="GET" class="flex flex-wrap gap-3">
            <flux:select variant="listbox" searchable name="meet_id" placeholder="Alle Wettkämpfe" clearable class="flex-1 min-w-48">
                @foreach($meets as $meet)
                    <flux:select.option value="{{ $meet->id }}" :selected="request('meet_id') == $meet->id">
                        {{ $meet->name }} ({{ $meet->start_date->format('d.m.Y') }})
                    </flux:select.option>
                @endforeach
            </flux:select>
            <flux:input name="search" placeholder="Athlet suchen…" value="{{ request('search') }}"
                        class="flex-1 min-w-48"/>
            <div class="ml-auto flex items-center gap-3">
                @if(request()->hasAny(['meet_id', 'search']))
                    <flux:button href="{{ route('entries.index') }}" variant="ghost" icon="x-mark">Zurücksetzen</flux:button>
                @endif
                <flux:button type="submit" variant="primary" icon="funnel">Filtern</flux:button>
            </div>
        </form>
    </div>

    <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 overflow-hidden">
        <flux:table
            class="[&_td:first-child]:ps-4 [&_th:first-child]:ps-4 [&_td:last-child]:pe-4 [&_th:last-child]:pe-4">
            <flux:table.columns>
                <flux:table.column>Athlet</flux:table.column>
                <flux:table.column>Wettkampf</flux:table.column>
                <flux:table.column>Disziplin</flux:table.column>
                <flux:table.column>Klasse</flux:table.column>
                <flux:table.column>Meldezeit</flux:table.column>
                <flux:table.column>Club</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse($entries as $entry)
                    <flux:table.row>
                        <flux:table.cell class="font-medium text-zinc-900 dark:text-white">
                            <a href="{{ route('athletes.show', $entry->athlete) }}"
                               class="hover:text-blue-600 dark:hover:text-blue-400">
                                {{ $entry->athlete?->display_name }}
                            </a>
                        </flux:table.cell>
                        <flux:table.cell class="text-sm text-zinc-500 dark:text-zinc-400">
                            {{ $entry->meet?->name }}
                        </flux:table.cell>
                        <flux:table.cell class="text-sm text-zinc-500 dark:text-zinc-400">
                            {{ $entry->swimEvent?->display_name }}
                        </flux:table.cell>
                        <flux:table.cell>
                            @if($entry->sport_class)
                                <flux:badge size="sm">{{ $entry->sport_class }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="font-mono text-sm">
                            {{ $entry->formatted_entry_time }}
                        </flux:table.cell>
                        <flux:table.cell class="text-sm text-zinc-500 dark:text-zinc-400">
                            {{ $entry->club?->display_name }}
                        </flux:table.cell>
                        <flux:table.cell>
                            @if($entry->status)
                                <flux:badge size="sm" color="{{ $entry->status === 'WDR' ? 'zinc' : 'yellow' }}">
                                    {{ $entry->status }}
                                </flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="text-right">
                            <flux:button href="{{ route('entries.edit', $entry) }}" size="xs" variant="ghost"
                                         icon="pencil" class="text-amber-500!"/>
                            <form method="POST" action="{{ route('entries.destroy', $entry) }}" class="inline">
                                @csrf @method('DELETE')
                                <flux:button type="submit" size="xs" variant="ghost" icon="trash" class="text-red-500!"
                                             onclick="return confirm('Meldung löschen?')"/>
                            </form>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="8" class="text-center text-zinc-400 py-12">
                            Keine Meldungen gefunden.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
        @if($entries->hasPages())
            <div class="p-4 border-t border-zinc-200 dark:border-zinc-800">{{ $entries->links() }}</div>
        @endif
    </div>

@endsection
