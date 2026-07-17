@extends('layouts.app')

@section('title', 'Ausgeschlossene Bewerbe — Richtzeiten ÖSTM & ÖM')

@section('content')
    <div class="max-w-2xl">
        <div class="flex items-center gap-3 mb-2">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Ausgeschlossene Bewerbe</h1>
        </div>
        <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-6">
            Bewerbe, die hier als „ausgeschlossen" markiert sind, werden bei der automatischen Richtzeiten-Berechnung
            (Phase 2) übersprungen — z.B. weil sie bei ÖSTM & ÖM nicht ausgetragen werden (25m-Bewerbe, 800m/1500m
            Freistil). Gilt jahresübergreifend für alle Richtzeitenlisten.
        </p>

        @if(session('success'))
            <div
                class="mb-4 p-4 bg-green-50 dark:bg-green-950/20 border border-green-200 dark:border-green-800 rounded-xl text-sm text-green-700 dark:text-green-400">
                {{ session('success') }}
            </div>
        @endif

        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
            <flux:table
                class="[&_td:first-child]:ps-4 [&_th:first-child]:ps-4 [&_td:last-child]:pe-4 [&_th:last-child]:pe-4">
                <flux:table.columns>
                    <flux:table.column>Bewerb</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse($disciplines as $discipline)
                        <flux:table.row>
                            <flux:table.cell class="font-medium">{{ $discipline->display_name }}</flux:table.cell>
                            <flux:table.cell>
                                @if($discipline->qualifyingExclusion)
                                    <flux:badge color="red">Ausgeschlossen</flux:badge>
                                @else
                                    <flux:badge color="emerald">Wird berechnet</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                @if($discipline->qualifyingExclusion)
                                    <form method="POST"
                                          action="{{ route('qualifying-excluded-disciplines.destroy', $discipline) }}">
                                        @csrf
                                        @method('DELETE')
                                        <flux:button type="submit" variant="ghost" size="sm">
                                            Wieder zulassen
                                        </flux:button>
                                    </form>
                                @else
                                    <form method="POST"
                                          action="{{ route('qualifying-excluded-disciplines.store', $discipline) }}">
                                        @csrf
                                        <flux:button type="submit" variant="ghost" size="sm">
                                            Ausschließen
                                        </flux:button>
                                    </form>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="3">
                                <p class="text-sm text-zinc-400 py-4 text-center">
                                    Keine Basiswert-Bewerbe vorhanden.
                                </p>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    </div>
@endsection
