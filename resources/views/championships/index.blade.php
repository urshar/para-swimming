@extends('layouts.app')

@section('title', 'Meisterschaften')

@section('content')
    <div class="max-w-4xl">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Meisterschaften</h1>
            @if(auth()->user()?->is_admin)
                <flux:button href="{{ route('championships.create') }}" variant="primary" icon="plus">
                    Neue Meisterschaft
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
                    <flux:table.column>Meisterschaft</flux:table.column>
                    <flux:table.column>Qualifikationszeitraum</flux:table.column>
                    <flux:table.column>Normen</flux:table.column>
                    <flux:table.column>Offen</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse($championships as $championship)
                        <flux:table.row>
                            <flux:table.cell>
                                <span class="font-medium">{{ $championship->display_name }}</span>
                                <span class="block text-xs text-zinc-500">
                                    {{ $championship->type }} · {{ $championship->year }} · {{ $championship->course }}
                                </span>
                            </flux:table.cell>
                            <flux:table.cell class="font-mono text-xs whitespace-nowrap">
                                {{ $championship->qualification_start->format('d.m.Y') }} –
                                {{ $championship->qualification_end->format('d.m.Y') }}
                            </flux:table.cell>
                            <flux:table.cell>{{ $championship->standards_count }}</flux:table.cell>
                            <flux:table.cell>
                                @if($championship->open_standards_count > 0)
                                    <flux:badge color="amber"
                                                title="Zeilen ohne festgelegten ÖBSV-Prozentsatz">
                                        {{ $championship->open_standards_count }}
                                    </flux:badge>
                                @else
                                    <span class="text-zinc-400">–</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                @if($championship->is_active)
                                    <flux:badge color="emerald">Aktiv</flux:badge>
                                @else
                                    <flux:badge color="zinc">Inaktiv</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex justify-end gap-2">
                                    <flux:button href="{{ route('championships.show', $championship) }}"
                                                 variant="ghost" size="sm" icon="eye"/>
                                    @if(auth()->user()?->is_admin)
                                        <flux:button href="{{ route('championships.edit', $championship) }}"
                                                     variant="ghost" size="sm" icon="pencil"/>
                                        <form method="POST"
                                              action="{{ route('championships.destroy', $championship) }}"
                                              onsubmit="return confirm('{{ $championship->display_name }} inklusive aller Normen wirklich löschen?');">
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
                            <flux:table.cell colspan="6">
                                <span class="text-sm text-zinc-500 dark:text-zinc-400">
                                    Noch keine Meisterschaften angelegt.
                                </span>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>

        <p class="mt-4 text-xs text-zinc-500 dark:text-zinc-400">
            „Offen" zählt Normen, für die noch kein ÖBSV-Prozentsatz festgelegt wurde. Ein
            ausdrücklich auf 0 gesetzter Prozentsatz gilt als festgelegt und erscheint hier nicht.
        </p>
    </div>
@endsection
