@extends('layouts.app')

@section('title', 'Wettkämpfe')

@section('content')

    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Wettkämpfe</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">Nationale Para-Swimming Datenbank</p>
        </div>
        <flux:button href="{{ route('meets.create') }}" variant="primary" icon="plus">
            Neuer Wettkampf
        </flux:button>
    </div>

    {{-- Stats --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
            <div class="text-2xl font-bold text-blue-600 dark:text-blue-400">{{ $meetCount }}</div>
            <div class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">Wettkämpfe</div>
        </div>
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
            <div class="text-2xl font-bold text-emerald-600 dark:text-emerald-400">{{ $athleteCount }}</div>
            <div class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">Athleten</div>
        </div>
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
            <div class="text-2xl font-bold text-violet-600 dark:text-violet-400">{{ $clubCount }}</div>
            <div class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">Vereine</div>
        </div>
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
            <div class="text-2xl font-bold text-amber-600 dark:text-amber-400">{{ $resultCount }}</div>
            <div class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">Ergebnisse</div>
        </div>
    </div>

    {{-- Filter --}}
    <form method="GET" class="flex flex-wrap gap-3 mb-4">
        <flux:input
            name="search"
            value="{{ request('search') }}"
            placeholder="Name oder Stadt suchen…"
            icon="magnifying-glass"
            class="w-64"
        />
        <flux:select name="course" placeholder="Alle Bahnen" class="w-40">
            <option value="">Alle Bahnen</option>
            <option value="LCM" @selected(request('course') === 'LCM')>LCM (50m)</option>
            <option value="SCM" @selected(request('course') === 'SCM')>SCM (25m)</option>
            <option value="SCY" @selected(request('course') === 'SCY')>SCY (Yards)</option>
            <option value="OPEN" @selected(request('course') === 'OPEN')>Freiwasser</option>
        </flux:select>
        <flux:input
            name="year"
            value="{{ request('year') }}"
            placeholder="Jahr"
            type="number"
            class="w-28"
        />
        <flux:button type="submit" icon="funnel">Filtern</flux:button>
        @if(request()->hasAny(['search', 'course', 'year']))
            <flux:button href="{{ route('meets.index') }}" variant="ghost" icon="x-mark">Zurücksetzen</flux:button>
        @endif
    </form>

    {{-- Table --}}
    <flux:table>
        <flux:table.columns>
            <flux:table.column>Wettkampf</flux:table.column>
            <flux:table.column>Datum</flux:table.column>
            <flux:table.column>Ort</flux:table.column>
            <flux:table.column>Bahn</flux:table.column>
            <flux:table.column>Status</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse($meets as $meet)
                <flux:table.row>
                    <flux:table.cell>
                        <a href="{{ route('meets.show', $meet) }}"
                           class="font-medium text-zinc-900 dark:text-zinc-100 hover:text-blue-600 dark:hover:text-blue-400 transition-colors">
                            {{ $meet->name }}
                        </a>
                        @if($meet->is_open)
                            <flux:badge size="sm" color="emerald" class="ml-2">Offen</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="text-zinc-500 dark:text-zinc-400 text-sm">
                        {{ $meet->date_range }}
                    </flux:table.cell>
                    <flux:table.cell class="text-zinc-500 dark:text-zinc-400 text-sm">
                        {{ $meet->city }}, {{ $meet->nation?->code }}
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" color="zinc">{{ $meet->course }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>
                        @if($meet->lenex_status)
                            <flux:badge size="sm" color="{{ match($meet->lenex_status) {
                            'OFFICIAL'  => 'emerald',
                            'RUNNING'   => 'blue',
                            'SEEDED'    => 'amber',
                            default     => 'zinc',
                        } }}">
                                {{ $meet->lenex_status }}
                            </flux:badge>
                        @else
                            <span class="text-zinc-400 text-sm">–</span>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex items-center gap-2 justify-end">
                            <flux:button href="{{ route('meets.show', $meet) }}" size="sm" variant="ghost" icon="eye"/>
                            <flux:button href="{{ route('meets.edit', $meet) }}" size="sm" variant="ghost"
                                         icon="pencil"/>
                            <form method="POST" action="{{ route('meets.destroy', $meet) }}"
                                  x-data
                                  @submit.prevent="if(confirm('Wettkampf wirklich löschen?')) $el.submit()">
                                @csrf @method('DELETE')
                                <flux:button type="submit" size="sm" variant="ghost" icon="trash"
                                             class="text-red-500 hover:text-red-700"/>
                            </form>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="text-center py-12 text-zinc-400">
                        Keine Wettkämpfe gefunden.
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <div class="mt-4">
        {{ $meets->links() }}
    </div>

@endsection
