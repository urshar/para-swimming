@extends('layouts.app')

@section('title', 'Gesamtwertung — '.$cup->name)

@section('content')
    <div class="max-w-6xl">
        <div class="flex items-start justify-between mb-6">
            <div class="flex items-center gap-3">
                <flux:button href="{{ route('cups.index') }}" variant="ghost" icon="arrow-left" size="sm"/>
                <div>
                    <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Gesamtwertung</h1>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                        {{ $cup->name }} · beste {{ $cup->best_of_count }} Tageswertungen
                        @if($calculatedAt)
                            · berechnet am {{ $calculatedAt->format('d.m.Y H:i') }} Uhr
                        @endif
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <flux:button href="{{ route('cups.overall-ranking.pdf', $cup) }}" variant="ghost"
                             icon="printer" size="sm" target="_blank">
                    PDF / Drucken
                </flux:button>
                @if(auth()->user()?->is_admin)
                    <form method="POST" action="{{ route('cups.overall-ranking.calculate', $cup) }}"
                          x-data="{ submit() { if (confirm('Gesamtwertung neu berechnen? Der bisherige Snapshot wird ersetzt.')) this.$el.submit() } }"
                          @submit.prevent="submit()">
                        @csrf
                        <flux:button type="submit" variant="primary" icon="arrow-path" size="sm">
                            Neu berechnen
                        </flux:button>
                    </form>
                @endif
            </div>
        </div>

        @if($isStale)
            <div
                class="mb-4 p-4 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800 rounded-xl text-sm text-amber-700 dark:text-amber-400 flex items-start gap-2">
                <flux:icon name="exclamation-triangle" class="w-4 h-4 mt-0.5 shrink-0"/>
                <span>{{ $staleReason }} Bitte neu berechnen.</span>
            </div>
        @endif

        @if(session('success'))
            <div
                class="mb-4 p-4 bg-green-50 dark:bg-green-950/20 border border-green-200 dark:border-green-800 rounded-xl text-sm text-green-700 dark:text-green-400">
                {{ session('success') }}
            </div>
        @endif

        @if($meets->isNotEmpty())
            <p class="text-xs text-zinc-400 mb-4">
                <span class="text-emerald-700 dark:text-emerald-400 font-semibold">Grün/fett</span> = zählt zu den
                besten {{ $cup->best_of_count }} Runden. Format je Runde: Punkte/Sportklasse.
            </p>
        @endif

        @forelse($brackets as $bracket)
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden mb-4">
                <div class="px-4 py-3 border-b border-zinc-100 dark:border-zinc-700 flex items-center justify-between">
                    <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">
                        {{ $bracket['gender'] === null ? 'Damen & Herren' : ($bracket['gender'] === 'F' ? 'Damen' : 'Herren') }}
                        — {{ $bracket['group']->name_de }}
                        @if($bracket['ageGroup'])
                            — {{ $bracket['ageGroup']->name_de }}
                        @endif
                    </h2>
                    <span class="text-xs text-zinc-400">{{ $bracket['results']->count() }} Athlet(en)</span>
                </div>

                <div class="overflow-x-auto">
                    <flux:table
                        class="table-fixed w-full min-w-[720px] [&_td:first-child]:ps-4 [&_th:first-child]:ps-4 [&_td:last-child]:pe-4 [&_th:last-child]:pe-4">
                        <flux:table.columns>
                            <flux:table.column class="w-12">Rang</flux:table.column>
                            <flux:table.column class="w-56">Athlet</flux:table.column>
                            <flux:table.column class="w-48">Verein</flux:table.column>
                            @foreach($meets as $index => $meet)
                                <flux:table.column class="w-20">
                                    <span title="{{ $meet->name }} ({{ $meet->start_date->format('d.m.Y') }})">
                                        R.{{ $index + 1 }}
                                    </span>
                                </flux:table.column>
                            @endforeach
                            <flux:table.column class="w-28">Gesamtpunkte</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach($bracket['results'] as $row)
                                <flux:table.row>
                                    <flux:table.cell class="font-medium">{{ $row->rank }}</flux:table.cell>
                                    <flux:table.cell>
                                        <a href="{{ route('athletes.show', $row->athlete) }}" class="hover:underline">
                                            {{ $row->athlete->last_name }}, {{ $row->athlete->first_name }}
                                        </a>
                                    </flux:table.cell>
                                    <flux:table.cell>{{ $row->club?->display_name }}</flux:table.cell>
                                    @foreach($row->rounds as $round)
                                        <flux:table.cell class="font-mono text-xs">
                                            <span style="{{ $round['counted'] ? 'color: #047857; font-weight: 600;' : 'color: #a1a1aa;' }}">
                                                {{ $round['points'] ?? '—' }}{{ $round['sport_class'] ? '/'.$round['sport_class'] : '' }}
                                            </span>
                                        </flux:table.cell>
                                    @endforeach
                                    <flux:table.cell class="font-mono font-semibold">{{ $row->total_points }}</flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            </div>
        @empty
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-8 text-center">
                <p class="text-sm text-zinc-400">
                    Für diesen Cup wurde noch keine Gesamtwertung berechnet.
                </p>
            </div>
        @endforelse
    </div>
@endsection
