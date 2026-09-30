@php use App\Models\RelayEntry; @endphp

@extends('layouts.app')

@section('title', 'Meldungen — Staffel')

@section('content')

    @include('entries._tabs')

    @php
        // Startwerte der Filter fürs Alpine-x-data (dieselbe generische Komponente wie das
        // Einzel-Cockpit: meet/search/status/problem). Einbindung unten über
        // x-data='...(@json(...))' — einfach anführen und @json (nicht @js), siehe CLAUDE.md.
        $filterConfig = [
            'meet' => (string) request('meet_id', ''),
            'search' => (string) request('search', ''),
            'status' => (string) request('status', ''),
            'problem' => (string) request('problem', ''),
        ];

        // Kennzahlen-Kacheln als klickbare Schnellfilter. Jede behält Wettkampf-/Suchkontext
        // und setzt genau einen Status-/Problemfilter ("Gesamt" löscht beide). $counts kommt
        // aus dem RelayEntryController.
        $baseParams = array_filter([
            'meet_id' => request('meet_id'),
            'search' => request('search'),
        ], fn ($v) => $v !== null && $v !== '');

        $activeStatus = request('status');
        $activeProblem = request('problem');

        $tiles = [
            ['label' => 'Gesamt', 'count' => $counts['total'], 'params' => [],
                'active' => ! $activeStatus && ! $activeProblem, 'accent' => 'text-zinc-900 dark:text-white'],
            ['label' => 'Ausstehend', 'count' => $counts['pending'], 'params' => ['status' => 'pending'],
                'active' => $activeStatus === 'pending', 'accent' => 'text-amber-600 dark:text-amber-400'],
            ['label' => 'Bestätigt', 'count' => $counts['confirmed'], 'params' => ['status' => 'confirmed'],
                'active' => $activeStatus === 'confirmed', 'accent' => 'text-emerald-600 dark:text-emerald-400'],
            ['label' => 'Unvollständig', 'count' => $counts['incomplete'], 'params' => ['problem' => 'incomplete'],
                'active' => $activeProblem === 'incomplete', 'accent' => 'text-orange-600 dark:text-orange-400'],
            ['label' => 'Ohne Meldezeit', 'count' => $counts['no_time'], 'params' => ['problem' => 'no_time'],
                'active' => $activeProblem === 'no_time', 'accent' => 'text-orange-600 dark:text-orange-400'],
        ];
    @endphp

    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 mb-4">
        @foreach($tiles as $tile)
            <a href="{{ route('relay-entries.index', array_merge($baseParams, $tile['params'])) }}"
               class="rounded-xl border p-4 transition {{ $tile['active']
                   ? 'border-blue-500 ring-1 ring-blue-500 bg-blue-50 dark:bg-blue-950/30'
                   : 'border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 hover:border-zinc-300 dark:hover:border-zinc-700' }}">
                <div class="text-2xl font-bold {{ $tile['accent'] }}">{{ $tile['count'] }}</div>
                <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">{{ $tile['label'] }}</div>
            </a>
        @endforeach
    </div>

    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 p-4 mb-4">
        {{-- Kein Filtern-Button: jedes Feld löst bei Änderung sofort eine neue Suche aus.
             Selects über x-model + $watch, Suche als natives Feld über x-model.debounce.
             Generische Alpine-Komponente in resources/js/entries-cockpit-filters.js. --}}
        <form method="GET" action="{{ route('relay-entries.index') }}" class="flex flex-wrap gap-3"
              x-data='entriesCockpitFilters(@json($filterConfig))'>
            <flux:select variant="listbox" searchable name="meet_id" x-model="meetFilter"
                         placeholder="Alle Wettkämpfe" clearable class="flex-1 min-w-48">
                @foreach($meets as $meet)
                    <flux:select.option value="{{ $meet->id }}">
                        {{ $meet->name }} ({{ $meet->start_date->format('d.m.Y') }})
                    </flux:select.option>
                @endforeach
            </flux:select>
            <flux:input name="search" x-model.debounce.500ms="searchFilter" placeholder="Verein suchen…"
                        class="flex-1 min-w-48"/>
            <flux:select variant="listbox" name="status" x-model="statusFilter"
                         placeholder="Alle Status" clearable class="flex-1 min-w-40">
                <flux:select.option value="pending">Ausstehend</flux:select.option>
                <flux:select.option value="confirmed">Bestätigt</flux:select.option>
            </flux:select>
            <flux:select variant="listbox" name="problem" x-model="problemFilter"
                         placeholder="Problemfilter" clearable class="flex-1 min-w-40">
                <flux:select.option value="incomplete">Unvollständig</flux:select.option>
                <flux:select.option value="no_time">Ohne Meldezeit</flux:select.option>
            </flux:select>
            @if(request()->hasAny(['meet_id', 'search', 'status', 'problem']))
                <div class="ml-auto flex items-center">
                    <flux:button href="{{ route('relay-entries.index') }}" variant="filled" icon="x-mark"
                                 class="text-red-500!">Zurücksetzen</flux:button>
                </div>
            @endif
        </form>
    </div>

    <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 overflow-hidden p-4 [--flux-bleed:1rem]">
        <flux:table bleed>
            <flux:table.columns>
                <flux:table.column>Verein</flux:table.column>
                <flux:table.column>Wettkampf</flux:table.column>
                <flux:table.column>Disziplin</flux:table.column>
                <flux:table.column>Staffelklasse</flux:table.column>
                <flux:table.column>Meldezeit</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column>Mitglieder</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse($relayEntries as $relay)
                    @php
                        /** @var RelayEntry $relay */
                        $required = $relay->swimEvent?->relay_count ?? 4;
                        $memberCount = $relay->members->count();
                        $incomplete = $memberCount < $required;
                    @endphp
                    <flux:table.row>
                        <flux:table.cell class="font-medium text-zinc-900 dark:text-white">
                            {{ $relayNames[$relay->id] }}
                            @if($relay->name)
                                {{-- Eigener Name: Verein zusätzlich nennen, sonst ist er nicht erkennbar. --}}
                                <div class="text-xs font-normal text-zinc-500 dark:text-zinc-400">{{ $relay->club?->display_name }}</div>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="text-sm text-zinc-500 dark:text-zinc-400">
                            {{ $relay->meet?->name }}
                        </flux:table.cell>
                        <flux:table.cell class="text-sm text-zinc-500 dark:text-zinc-400">
                            {{ $relay->swimEvent?->display_name }}
                        </flux:table.cell>
                        <flux:table.cell>
                            @if($relay->relay_class)
                                <flux:badge size="sm" class="font-mono">{{ $relay->relay_class }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="font-mono text-sm">
                            {{ $relay->formatted_entry_time }}
                        </flux:table.cell>
                        <flux:table.cell>
                            @if($relay->status === 'confirmed')
                                <flux:badge size="sm" color="emerald">Bestätigt</flux:badge>
                            @else
                                <flux:badge size="sm" color="amber">Ausstehend</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="text-sm {{ $incomplete ? 'text-red-500 font-medium' : 'text-zinc-500 dark:text-zinc-400' }}">
                            {{ $memberCount }}/{{ $required }}
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="7" class="text-center text-zinc-400 py-12">
                            Keine Staffelmeldungen gefunden.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <div class="mt-4">
        {{ $relayEntries->links() }}
    </div>

@endsection
