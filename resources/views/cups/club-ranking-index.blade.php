@extends('layouts.app')

@section('title', 'Vereinswertung')

@section('content')
    <div class="max-w-2xl">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100 mb-2">ÖBSV Cup — Vereinswertung</h1>
        <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-6">
            Vereinswertung je Cup-Jahr — wahlweise als klassische Startwertung oder als leistungsorientierte
            Wertung. Wähle ein Cup-Jahr, um die Wertung anzuzeigen.
        </p>

        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
            <flux:table
                class="[&_td:first-child]:ps-4 [&_th:first-child]:ps-4 [&_td:last-child]:pe-4 [&_th:last-child]:pe-4">
                <flux:table.columns>
                    <flux:table.column>Jahr</flux:table.column>
                    <flux:table.column>Name</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse($cups as $cup)
                        <flux:table.row>
                            <flux:table.cell class="font-medium">{{ $cup->year }}</flux:table.cell>
                            <flux:table.cell>{{ $cup->name }}</flux:table.cell>
                            <flux:table.cell>
                                @if($cup->is_active)
                                    <flux:badge color="emerald">Aktiv</flux:badge>
                                @else
                                    <flux:badge color="zinc">Inaktiv</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex justify-end">
                                    <flux:button href="{{ route('cups.club-ranking.show', $cup) }}"
                                                 variant="ghost" size="sm" icon="user-group"
                                                 class="text-blue-500!" tooltip="Vereinswertung"/>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="4">
                                <p class="text-sm text-zinc-400 py-4 text-center">
                                    Noch kein Cup-Jahr konfiguriert.
                                </p>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    </div>
@endsection
