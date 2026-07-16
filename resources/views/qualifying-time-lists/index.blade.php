@extends('layouts.app')

@section('title', 'Richtzeitenlisten ÖSTM & ÖM')

@section('content')
    <div class="max-w-2xl">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Richtzeitenlisten ÖSTM & ÖM</h1>
            @if(auth()->user()?->is_admin)
                <flux:button href="{{ route('qualifying-time-lists.create') }}" variant="primary" icon="plus">
                    Neue Liste
                </flux:button>
            @endif
        </div>

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
                    <flux:table.column>Jahr</flux:table.column>
                    <flux:table.column>Zielpunkte-Overrides</flux:table.column>
                    <flux:table.column>Richtzeiten</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse($lists as $list)
                        <flux:table.row>
                            <flux:table.cell class="font-mono font-medium">{{ $list->year }}</flux:table.cell>
                            <flux:table.cell>{{ $list->target_points_count }}</flux:table.cell>
                            <flux:table.cell>{{ $list->times_count }}</flux:table.cell>
                            <flux:table.cell>
                                @if($list->is_active)
                                    <flux:badge color="emerald">Aktiv</flux:badge>
                                @else
                                    <flux:badge color="zinc">Inaktiv</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex justify-end gap-2">
                                    <flux:button href="{{ route('qualifying-time-lists.show', $list) }}"
                                                 variant="ghost" size="sm" icon="eye"/>
                                    @if(auth()->user()?->is_admin)
                                        <flux:button href="{{ route('qualifying-time-lists.edit', $list) }}"
                                                     variant="ghost" size="sm" icon="pencil"/>
                                        <form method="POST"
                                              action="{{ route('qualifying-time-lists.destroy', $list) }}"
                                              onsubmit="return confirm('Richtzeitenliste {{ $list->year }} inkl. aller Zielpunkte und Richtzeiten wirklich löschen?');">
                                            @csrf
                                            @method('DELETE')
                                            <flux:button type="submit" variant="ghost" size="sm" icon="trash"/>
                                        </form>
                                    @endif
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="5">
                                <p class="text-sm text-zinc-400 py-4 text-center">
                                    Noch keine Richtzeitenliste angelegt.
                                </p>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    </div>
@endsection
