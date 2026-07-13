@extends('layouts.app')

@section('title', 'Kaderarten')

@section('content')
    <div class="max-w-2xl">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Kaderarten (Nationalkader)</h1>
            <flux:button href="{{ route('kader-types.create') }}" variant="primary" icon="plus">
                Neue Kaderart
            </flux:button>
        </div>

        @if(session('success'))
            <div
                class="mb-4 p-4 bg-green-50 dark:bg-green-950/20 border border-green-200 dark:border-green-800 rounded-xl text-sm text-green-700 dark:text-green-400">
                {{ session('success') }}
            </div>
        @endif
        @if(session('error'))
            <div
                class="mb-4 p-4 bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-800 rounded-xl text-sm text-red-700 dark:text-red-400">
                {{ session('error') }}
            </div>
        @endif

        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
            <flux:table
                class="[&_td:first-child]:ps-4 [&_th:first-child]:ps-4 [&_td:last-child]:pe-4 [&_th:last-child]:pe-4">
                <flux:table.columns>
                    <flux:table.column>Code</flux:table.column>
                    <flux:table.column>Bezeichnung</flux:table.column>
                    <flux:table.column>Athleten</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse($kaderTypes as $kaderType)
                        <flux:table.row>
                            <flux:table.cell class="font-mono text-xs">{{ $kaderType->code }}</flux:table.cell>
                            <flux:table.cell class="font-medium">{{ $kaderType->name_de }}</flux:table.cell>
                            <flux:table.cell>{{ $kaderType->memberships_count }}</flux:table.cell>
                            <flux:table.cell>
                                @if($kaderType->is_active)
                                    <flux:badge color="emerald">Aktiv</flux:badge>
                                @else
                                    <flux:badge color="zinc">Inaktiv</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex justify-end gap-2">
                                    <flux:button href="{{ route('kader-types.edit', $kaderType) }}"
                                                 variant="ghost" size="sm" icon="pencil"/>
                                    <form method="POST" action="{{ route('kader-types.destroy', $kaderType) }}"
                                          onsubmit="return confirm('Kaderart „{{ $kaderType->name_de }}“ wirklich löschen?');">
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
                                    Noch keine Kaderart angelegt.
                                </p>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    </div>
@endsection
