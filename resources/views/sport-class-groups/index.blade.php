@extends('layouts.app')

@section('title', 'Sportklassengruppen')

@section('content')
    <div class="max-w-2xl">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Sportklassengruppen (Cupwertung)</h1>
            <flux:button href="{{ route('sport-class-groups.create') }}" variant="primary" icon="plus">
                Neue Gruppe
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
                    <flux:table.column>Art</flux:table.column>
                    <flux:table.column>Sportklassen</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse($groups as $group)
                        <flux:table.row>
                            <flux:table.cell class="font-mono text-xs">{{ $group->code }}</flux:table.cell>
                            <flux:table.cell class="font-medium">{{ $group->name_de }}</flux:table.cell>
                            <flux:table.cell>
                                @if($group->is_virtual)
                                    <flux:badge color="amber">Virtuell (z.B. Top-Gruppe)</flux:badge>
                                @else
                                    <flux:badge color="blue">Sportklassen-basiert</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>{{ $group->is_virtual ? '—' : $group->members_count }}</flux:table.cell>
                            <flux:table.cell>
                                @if($group->is_active)
                                    <flux:badge color="emerald">Aktiv</flux:badge>
                                @else
                                    <flux:badge color="zinc">Inaktiv</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex justify-end gap-2">
                                    <flux:button href="{{ route('sport-class-groups.edit', $group) }}"
                                                 variant="ghost" size="sm" icon="pencil"/>
                                    <form method="POST" action="{{ route('sport-class-groups.destroy', $group) }}"
                                          onsubmit="return confirm('Gruppe „{{ $group->name_de }}“ inkl. aller Zuordnungen wirklich löschen?');">
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
                                    Noch keine Sportklassengruppe angelegt.
                                </p>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    </div>
@endsection
