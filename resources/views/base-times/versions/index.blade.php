@extends('layouts.app')

@section('title', 'Basiswert-Versionen')

@section('content')
    <div class="max-w-4xl">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Basiswert-Versionen</h1>
            <div class="flex gap-3">
                <flux:button href="{{ route('base-times.import') }}" variant="ghost" icon="arrow-up-tray">
                    Importieren
                </flux:button>
                <flux:button href="{{ route('base-times.versions.create') }}" variant="primary" icon="plus">
                    Neue Version
                </flux:button>
            </div>
        </div>

        @if(session('success'))
            <div
                class="mb-4 p-4 bg-green-50 dark:bg-green-950/20 border border-green-200 dark:border-green-800 rounded-xl text-sm text-green-700 dark:text-green-400">
                {{ session('success') }}
            </div>
        @endif

        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Bezeichnung</flux:table.column>
                    <flux:table.column>Gültig ab</flux:table.column>
                    <flux:table.column>Gültig bis</flux:table.column>
                    <flux:table.column>Basiswerte</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse($versions as $version)
                        <flux:table.row>
                            <flux:table.cell>
                                <a href="{{ route('base-times.categories.index', $version) }}"
                                   class="font-medium text-blue-600 dark:text-blue-400 hover:underline">
                                    {{ $version->label }}
                                </a>
                            </flux:table.cell>
                            <flux:table.cell>{{ $version->valid_from->format('d.m.Y') }}</flux:table.cell>
                            <flux:table.cell>{{ $version->valid_until?->format('d.m.Y') ?? '—' }}</flux:table.cell>
                            <flux:table.cell>{{ $version->base_times_count }}</flux:table.cell>
                            <flux:table.cell>
                                <div class="flex justify-end gap-2">
                                    <flux:button href="{{ route('base-times.export', $version) }}"
                                                 variant="ghost" size="sm" icon="arrow-down-tray"/>
                                    <flux:button href="{{ route('base-times.versions.edit', $version) }}"
                                                 variant="ghost" size="sm" icon="pencil"/>
                                    <form method="POST" action="{{ route('base-times.versions.destroy', $version) }}"
                                          onsubmit="return confirm('Version „{{ $version->label }}“ inkl. aller {{ $version->base_times_count }} Basiswerte wirklich löschen?');">
                                        @csrf
                                        @method('DELETE')
                                        <flux:button type="submit" variant="ghost" size="sm" icon="trash"/>
                                    </form>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="5">
                                <p class="text-sm text-zinc-400 py-4 text-center">
                                    Noch keine Basiswert-Version vorhanden.
                                </p>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    </div>
@endsection
