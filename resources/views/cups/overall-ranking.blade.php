@php use Illuminate\Support\Carbon; @endphp
@extends('layouts.app')

@section('title', 'Gesamtwertung — '.$cup->name)

@section('content')
    <div class="max-w-4xl">
        <div class="flex items-start justify-between mb-6">
            <div class="flex items-center gap-3">
                <flux:button href="{{ route('cups.index') }}" variant="ghost" icon="arrow-left" size="sm"/>
                <div>
                    <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Gesamtwertung</h1>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                        {{ $cup->name }} · beste {{ $cup->best_of_count }} Tageswertungen
                        @if($calculatedAt)
                            · berechnet am {{ Carbon::parse($calculatedAt)->format('d.m.Y H:i') }} Uhr
                        @endif
                    </p>
                </div>
            </div>

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

        @if(session('success'))
            <div
                class="mb-4 p-4 bg-green-50 dark:bg-green-950/20 border border-green-200 dark:border-green-800 rounded-xl text-sm text-green-700 dark:text-green-400">
                {{ session('success') }}
            </div>
        @endif

        @forelse($brackets as $bracket)
            <div
                class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden mb-4">
                <div class="px-4 py-3 border-b border-zinc-100 dark:border-zinc-700 flex items-center justify-between">
                    <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">
                        {{ $bracket['gender'] === 'F' ? 'Damen' : 'Herren' }}
                        — {{ $bracket['group']->name_de }}
                        @if($bracket['ageGroup'])
                            — {{ $bracket['ageGroup']->name_de }}
                        @endif
                    </h2>
                    <span class="text-xs text-zinc-400">{{ $bracket['results']->count() }} Athlet(en)</span>
                </div>

                <flux:table
                    class="[&_td:first-child]:ps-4 [&_th:first-child]:ps-4 [&_td:last-child]:pe-4 [&_th:last-child]:pe-4">
                    <flux:table.columns>
                        <flux:table.column>Rang</flux:table.column>
                        <flux:table.column>Athlet</flux:table.column>
                        <flux:table.column>Verein</flux:table.column>
                        <flux:table.column>Runden</flux:table.column>
                        <flux:table.column>Gesamtpunkte</flux:table.column>
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
                                <flux:table.cell>{{ $row->club?->name }}</flux:table.cell>
                                <flux:table.cell>{{ $row->rounds_counted }}</flux:table.cell>
                                <flux:table.cell class="font-mono">{{ $row->total_points }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        @empty
            <div
                class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-8 text-center">
                <p class="text-sm text-zinc-400">
                    Für diesen Cup wurde noch keine Gesamtwertung berechnet.
                </p>
            </div>
        @endforelse
    </div>
@endsection
