@extends('layouts.app')

@section('title', $club->name)

@section('content')
    <div class="flex items-start justify-between mb-6">
        <div class="flex items-center gap-3">
            <flux:button href="{{ route('clubs.index') }}" variant="ghost" icon="arrow-left" size="sm"/>
            <div>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $club->name }}</h1>
                <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                    {{ $club->code ? $club->code . ' · ' : '' }}{{ $club->nation?->name_de }}
                    @if($club->type !== 'CLUB')
                        · {{ $club->type }}
                    @endif
                </p>
            </div>
        </div>
        <div class="flex gap-2">
            <flux:button href="{{ route('clubs.edit', $club) }}" variant="ghost" icon="pencil" size="sm">Bearbeiten
            </flux:button>
        </div>
    </div>

    <div class="grid grid-cols-3 gap-4 mb-6">
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 text-center">
            <div class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $club->athletes_count }}</div>
            <div class="text-sm text-zinc-500 dark:text-zinc-400">Athleten</div>
        </div>
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 text-center">
            <div class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $club->entries_count }}</div>
            <div class="text-sm text-zinc-500 dark:text-zinc-400">Meldungen</div>
        </div>
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 text-center">
            <div class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $club->results_count }}</div>
            <div class="text-sm text-zinc-500 dark:text-zinc-400">Ergebnisse</div>
        </div>
    </div>

    <div class="flex items-center justify-between mb-3">
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">Athleten</h2>
        <flux:button href="{{ route('athletes.create') }}?club_id={{ $club->id }}" variant="ghost" icon="plus"
                     size="sm">
            Athlet anlegen
        </flux:button>
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>Athlet</flux:table.column>
            <flux:table.column>Geschlecht</flux:table.column>
            <flux:table.column>Sport-Klassen</flux:table.column>
            <flux:table.column>Geburtsdatum</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse($athletes as $athlete)
                <flux:table.row>
                    <flux:table.cell>
                        <a href="{{ route('athletes.show', $athlete) }}"
                           class="font-medium text-zinc-900 dark:text-zinc-100 hover:text-blue-600 transition-colors">
                            {{ $athlete->display_name }}
                        </a>
                    </flux:table.cell>
                    <flux:table.cell class="text-sm text-zinc-500">
                        {{ match($athlete->gender) { 'M' => 'Herr', 'F' => 'Dame', default => 'Nicht binär' } }}
                    </flux:table.cell>
                    <flux:table.cell class="text-sm text-zinc-500 font-mono">
                        {{ $athlete->sport_classes_display ?: '–' }}
                    </flux:table.cell>
                    <flux:table.cell class="text-sm text-zinc-500">
                        {{ $athlete->birth_date?->format('d.m.Y') ?? '–' }}
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:button href="{{ route('athletes.show', $athlete) }}" size="sm" variant="ghost"
                                     icon="eye"/>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="text-center py-8 text-zinc-400">
                        Noch keine Athleten in diesem Verein.
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <div class="mt-4">{{ $athletes->links() }}</div>
@endsection
