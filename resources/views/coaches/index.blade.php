@php use App\Enums\CoachLevel;use App\Models\Coach; @endphp

{{-- coaches/index.blade.php — Trainer (Spec club-permissions §4.6, Phase 8) --}}
@extends('layouts.app')

@section('title', 'Trainer')

@section('content')

    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Trainer</h1>
        @can('create', Coach::class)
            <flux:button href="{{ route('coaches.create') }}" variant="primary" icon="plus">Neuer Trainer</flux:button>
        @endcan
    </div>

    @php
        // Startwerte der Filter fürs Alpine-x-data (indexFilters, Submit bei jeder Änderung), siehe clubs/index.
        $filterConfig = [
            'search' => (string) request('search', ''),
            'club_id' => (string) request('club_id', ''),
            'level' => (string) request('level', ''),
            'active' => (string) request('active', ''),
        ];
    @endphp
    <form method="GET" class="flex flex-wrap items-center gap-3 mb-4"
          x-data='indexFilters(@json($filterConfig))'>
        <div class="w-48 shrink-0">
            <flux:input name="search" x-model.debounce.500ms="search" placeholder="Name…" icon="magnifying-glass"/>
        </div>
        @if($clubs->isNotEmpty())
            <flux:select variant="listbox" searchable name="club_id" x-model="club_id" placeholder="Verein" clearable class="w-56">
                @foreach($clubs as $club)
                    <flux:select.option value="{{ $club->id }}">{{ $club->display_name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
        <flux:select variant="listbox" name="level" x-model="level" placeholder="Stufe" clearable class="w-52">
            @foreach(CoachLevel::cases() as $option)
                <flux:select.option value="{{ $option->value }}">{{ $option->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select variant="listbox" name="active" x-model="active" placeholder="Aktive und inaktive" clearable class="w-52">
            <flux:select.option value="1">Nur aktive</flux:select.option>
        </flux:select>
        @if(request()->hasAny(['search', 'club_id', 'level', 'active']))
            <div class="ml-auto flex items-center">
                <flux:button href="{{ route('coaches.index') }}" variant="filled" icon="x-mark"
                             class="text-red-500!">Zurücksetzen</flux:button>
            </div>
        @endif
    </form>

    <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden p-4 [--flux-bleed:1rem]">
    <flux:table bleed>
        <flux:table.columns>
            <flux:table.column>Trainer</flux:table.column>
            <flux:table.column>Verein</flux:table.column>
            <flux:table.column>Stufe</flux:table.column>
            <flux:table.column>Status</flux:table.column>
            <flux:table.column>Login</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse($coaches as $coach)
                <flux:table.row>
                    <flux:table.cell>
                        <a href="{{ route('coaches.show', $coach) }}"
                           class="font-medium text-zinc-900 dark:text-zinc-100 hover:text-blue-600 transition-colors">
                            {{ $coach->display_name }}
                        </a>
                    </flux:table.cell>
                    <flux:table.cell class="text-sm text-zinc-500">{{ $coach->club?->display_name ?? '–' }}</flux:table.cell>
                    <flux:table.cell class="text-sm">{{ $coach->level->label() }}</flux:table.cell>
                    <flux:table.cell>
                        @if($coach->is_active)
                            <flux:badge size="sm" color="green">aktiv</flux:badge>
                        @else
                            <flux:badge size="sm" color="zinc">inaktiv</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="text-sm text-zinc-500">{{ $coach->user ? 'ja' : '–' }}</flux:table.cell>
                    <flux:table.cell>
                        <div class="flex items-center gap-1 justify-end">
                            <flux:button href="{{ route('coaches.show', $coach) }}" size="sm" variant="ghost" icon="eye"
                                         aria-label="Anzeigen"/>
                            @can('update', $coach)
                                <flux:button href="{{ route('coaches.edit', $coach) }}" size="sm" variant="ghost"
                                             icon="pencil" class="text-amber-500!" aria-label="Bearbeiten"/>
                            @endcan
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="text-center py-12 text-zinc-400">Keine Trainer gefunden.
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
    </div>

    <div class="mt-4">{{ $coaches->links() }}</div>

@endsection
