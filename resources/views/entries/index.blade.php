@extends('layouts.app')

@section('title', 'Meldungen')

@section('content')

    @php
        // Startwerte der Filter fürs Alpine-x-data. Die Selects werden darüber via x-model
        // vorbelegt (kein :selected). Einbindung unten über x-data='...(@json(...))' — einfach
        // anführen und @json (nicht @js) verwenden: PhpStorm kennt @json als Directive und
        // verzählt sich dann nicht bei den folgenden @foreach/@endforeach; @json' doppelte
        // Anführungszeichen brechen im einfach angeführten Attribut nichts (CLAUDE.md).
        $filterConfig = [
            'meet' => (string) request('meet_id', ''),
            'search' => (string) request('search', ''),
            'status' => (string) request('status', ''),
            'problem' => (string) request('problem', ''),
        ];
    @endphp

    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 p-4 mb-4">
        {{-- Kein Filtern-Button: jedes Feld löst bei Änderung sofort eine neue Suche aus.
             Selects über x-model + $watch (flux:select feuert change mit bubbles:false,
             deshalb kein onchange), Suche als natives Feld über x-model.debounce. Logik in
             resources/js/entries-cockpit-filters.js. --}}
        <form method="GET" action="{{ route('entries.index') }}" class="flex flex-wrap gap-3"
              x-data='entriesCockpitFilters(@json($filterConfig))'>
            <flux:select variant="listbox" searchable name="meet_id" x-model="meetFilter"
                         placeholder="Alle Wettkämpfe" clearable class="flex-1 min-w-48">
                @foreach($meets as $meet)
                    <flux:select.option value="{{ $meet->id }}">
                        {{ $meet->name }} ({{ $meet->start_date->format('d.m.Y') }})
                    </flux:select.option>
                @endforeach
            </flux:select>
            <flux:input name="search" x-model.debounce.500ms="searchFilter" placeholder="Athlet suchen…"
                        class="flex-1 min-w-48"/>
            {{-- Statusfilter: "NORMAL" ist ein echter Sentinel-Wert (Meldungen ohne
                 besonderen Status); ein leeres Select bedeutet "alle" (CLAUDE.md: leeres
                 value="" kommt bei Flux nicht zuverlässig im Request an). --}}
            <flux:select variant="listbox" name="status" x-model="statusFilter"
                         placeholder="Alle Status" clearable class="flex-1 min-w-40">
                <flux:select.option value="NORMAL">Normal (ohne Status)</flux:select.option>
                <flux:select.option value="WDR">WDR – Zurückgezogen</flux:select.option>
                <flux:select.option value="SICK">SICK – Krank</flux:select.option>
                <flux:select.option value="EXH">EXH – Außer Konkurrenz</flux:select.option>
                <flux:select.option value="RJC">RJC – Abgelehnt</flux:select.option>
            </flux:select>
            <flux:select variant="listbox" name="problem" x-model="problemFilter"
                         placeholder="Problemfilter" clearable class="flex-1 min-w-40">
                <flux:select.option value="no_time">Ohne Meldezeit</flux:select.option>
                <flux:select.option value="no_class">Ohne Sportklasse</flux:select.option>
            </flux:select>
            @if(request()->hasAny(['meet_id', 'search', 'status', 'problem']))
                <div class="ml-auto flex items-center">
                    <flux:button href="{{ route('entries.index') }}" variant="filled" icon="x-mark"
                                 class="text-red-500!">Zurücksetzen</flux:button>
                </div>
            @endif
        </form>
    </div>

    <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 overflow-hidden p-4 [--flux-bleed:1rem]">
        <flux:table bleed>
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
                            {{-- Seite ist admin-only (Cockpit); Bearbeiten/Löschen daher immer sichtbar. --}}
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
    </div>

    <div class="mt-4">
        {{ $entries->links() }}
    </div>

@endsection
