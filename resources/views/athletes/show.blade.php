@extends('layouts.app')

@section('title', $athlete->display_name)

@section('content')

    <div class="flex items-start justify-between mb-6">
        <div class="flex items-center gap-3">
            <flux:button href="{{ route('athletes.index') }}" variant="ghost" icon="arrow-left" size="sm" />
            <div>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $athlete->full_name }}</h1>
                <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                    {{ match($athlete->gender) { 'M' => 'Herr', 'F' => 'Dame', default => 'Nicht binär' } }}
                    @if($athlete->birth_date) · *{{ $athlete->birth_date->format('d.m.Y') }} @endif
                    · {{ $athlete->nation?->code }}
                </p>
            </div>
        </div>
        <flux:button href="{{ route('athletes.edit', $athlete) }}" variant="ghost" icon="pencil" size="sm">
            Bearbeiten
        </flux:button>
    </div>

    <div class="grid grid-cols-3 gap-6 mb-6">

        {{-- Info --}}
        <div class="col-span-2 bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-5">
            <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-4">Stammdaten</h2>
            <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Verein</dt>
                    <dd class="font-medium mt-0.5">
                        @if($athlete->club)
                            <a href="{{ route('clubs.show', $athlete->club) }}" class="hover:text-blue-600 transition-colors">
                                {{ $athlete->club->display_name }}
                            </a>
                        @else
                            <span class="text-zinc-400">–</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Nation</dt>
                    <dd class="font-medium mt-0.5">{{ $athlete->nation?->name_de ?? '–' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Lizenznummer</dt>
                    <dd class="font-medium mt-0.5 font-mono">{{ $athlete->license ?? '–' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">SDMS ID</dt>
                    <dd class="font-medium mt-0.5 font-mono">{{ $athlete->license_ipc ?? '–' }}</dd>
                </div>
                @if($athlete->disability_type)
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">Behinderungsart</dt>
                        <dd class="font-medium mt-0.5">{{ ucfirst($athlete->disability_type) }}</dd>
                    </div>
                @endif
            </dl>
        </div>

        {{-- Sport-Klassen --}}
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-5">
            <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-4">Sport-Klassen</h2>
            @forelse($athlete->sportClasses as $sc)
                <div class="flex items-center justify-between py-2 border-b border-zinc-100 dark:border-zinc-700 last:border-0">
                <span class="font-mono font-bold text-lg text-zinc-900 dark:text-zinc-100">
                    {{ $sc->sport_class }}
                </span>
                    @if($sc->status)
                        <flux:badge size="sm" color="{{ match($sc->status) {
                        'CONFIRMED'        => 'emerald',
                        'NATIONAL'         => 'blue',
                        'NEW', 'REVIEW'    => 'amber',
                        'OBSERVATION'      => 'orange',
                        default            => 'zinc',
                    } }}">
                            {{ $sc->status }}
                        </flux:badge>
                    @endif
                </div>
            @empty
                <p class="text-sm text-zinc-400">Keine Klassen zugeordnet.</p>
            @endforelse

            @if($athlete->exceptions->isNotEmpty())
                <h3 class="font-medium text-zinc-700 dark:text-zinc-300 mt-4 mb-2 text-sm">Exceptions</h3>
                <div class="flex flex-wrap gap-1">
                    @foreach($athlete->exceptions as $exc)
                        <flux:badge size="sm" color="zinc" class="font-mono">{{ $exc->code }}</flux:badge>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- Ergebnisse --}}
    <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100 mb-3">Ergebnisse</h2>

    <flux:table class="[&_td:first-child]:ps-4 [&_th:first-child]:ps-4 [&_td:last-child]:pe-4 [&_th:last-child]:pe-4">
        <flux:table.columns>
            <flux:table.column>Wettkampf</flux:table.column>
            <flux:table.column>Disziplin</flux:table.column>
            <flux:table.column>Klasse</flux:table.column>
            <flux:table.column>Zeit</flux:table.column>
            <flux:table.column>Platz</flux:table.column>
            <flux:table.column>Rekorde</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse($results as $result)
                <flux:table.row>
                    <flux:table.cell class="text-sm">
                        <a href="{{ route('meets.show', $result->meet) }}"
                           class="hover:text-blue-600 transition-colors">
                            {{ $result->meet?->name }}
                        </a>
                        <div class="text-xs text-zinc-400">{{ $result->meet?->start_date?->format('d.m.Y') }}</div>
                    </flux:table.cell>
                    <flux:table.cell class="text-sm">{{ $result->swimEvent?->display_name }}</flux:table.cell>
                    <flux:table.cell>
                        @if($result->sport_class)
                            <flux:badge size="sm" color="blue">{{ $result->sport_class }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="font-mono font-medium">
                        @if($result->status)
                            <flux:badge size="sm" color="red">{{ $result->status }}</flux:badge>
                        @else
                            {{ $result->formatted_swim_time }}
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="text-sm text-zinc-500">
                        {{ $result->place ? $result->place . '.' : '–' }}
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex gap-1">
                            @if($result->is_world_record)    <flux:badge size="sm" color="yellow">WR</flux:badge> @endif
                            @if($result->is_european_record) <flux:badge size="sm" color="blue">ER</flux:badge> @endif
                            @if($result->is_national_record) <flux:badge size="sm" color="emerald">NR</flux:badge> @endif
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="text-center py-8 text-zinc-400">
                        Noch keine Ergebnisse vorhanden.
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <div class="mt-4">{{ $results->links() }}</div>

@endsection
