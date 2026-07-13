@extends('layouts.app')

@section('title', 'Altersgruppen')

@section('content')
    <div class="max-w-2xl">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Altersgruppen (Cupwertung)</h1>
            <flux:button href="{{ route('age-groups.create') }}" variant="primary" icon="plus">
                Neue Altersgruppe
            </flux:button>
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
                    <flux:table.column>Code</flux:table.column>
                    <flux:table.column>Bezeichnung</flux:table.column>
                    <flux:table.column>Min. Alter</flux:table.column>
                    <flux:table.column>Max. Alter</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse($ageGroups as $ageGroup)
                        <flux:table.row>
                            <flux:table.cell class="font-mono text-xs">{{ $ageGroup->code }}</flux:table.cell>
                            <flux:table.cell class="font-medium">{{ $ageGroup->name_de }}</flux:table.cell>
                            <flux:table.cell>{{ $ageGroup->min_age ?? '—' }}</flux:table.cell>
                            <flux:table.cell>{{ $ageGroup->max_age ?? '—' }}</flux:table.cell>
                            <flux:table.cell>
                                @if($ageGroup->is_active)
                                    <flux:badge color="emerald">Aktiv</flux:badge>
                                @else
                                    <flux:badge color="zinc">Inaktiv</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex justify-end gap-2">
                                    <flux:button href="{{ route('age-groups.edit', $ageGroup) }}"
                                                 variant="ghost" size="sm" icon="pencil"/>
                                    <form method="POST" action="{{ route('age-groups.destroy', $ageGroup) }}"
                                          onsubmit="return confirm('Altersgruppe „{{ $ageGroup->name_de }}“ wirklich löschen?');">
                                        @csrf
                                        @method('DELETE')
                                        <flux:button type="submit" variant="ghost" size="sm" icon="trash"/>
                                    </form>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="6">
                                <p class="text-sm text-zinc-400 py-4 text-center">
                                    Noch keine Altersgruppe angelegt.
                                </p>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    </div>
@endsection
