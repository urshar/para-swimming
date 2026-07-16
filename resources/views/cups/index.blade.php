@extends('layouts.app')

@section('title', 'Cup-Konfiguration')

@section('content')
    <div class="max-w-4xl">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">ÖBSV Cup — Konfiguration</h1>
            <flux:button href="{{ route('cups.create') }}" variant="primary" icon="plus">
                Neuer Cup
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
                    <flux:table.column>Jahr</flux:table.column>
                    <flux:table.column>Name</flux:table.column>
                    <flux:table.column>Punktetabelle</flux:table.column>
                    <flux:table.column>Beste X</flux:table.column>
                    <flux:table.column>Meets</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column>Klassifizierung</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse($cups as $cup)
                        @php($classification = $classificationStatus[$cup->id])
                        <flux:table.row>
                            <flux:table.cell class="font-medium">{{ $cup->year }}</flux:table.cell>
                            <flux:table.cell>{{ $cup->name }}</flux:table.cell>
                            <flux:table.cell>{{ $cup->baseTimeVersion?->label ?? '—' }}</flux:table.cell>
                            <flux:table.cell>{{ $cup->best_of_count }}</flux:table.cell>
                            <flux:table.cell>{{ $cup->meets_count }}</flux:table.cell>
                            <flux:table.cell>
                                @if($cup->is_active)
                                    <flux:badge color="emerald">Aktiv</flux:badge>
                                @else
                                    <flux:badge color="zinc">Inaktiv</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                @if(! $classification['calculatedAt'])
                                    <flux:badge color="zinc">Noch nicht berechnet</flux:badge>
                                @elseif($classification['isStale'])
                                    <flux:badge color="amber" title="{{ $classification['reason'] }}">Veraltet</flux:badge>
                                @else
                                    <flux:badge color="emerald"
                                                title="Berechnet am {{ $classification['calculatedAt']->format('d.m.Y H:i') }} Uhr">
                                        Aktuell
                                    </flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex justify-end gap-2">
                                    <flux:button href="{{ route('cups.overall-ranking.show', $cup) }}"
                                                 variant="ghost" size="sm" icon="trophy">
                                        Gesamtwertung
                                    </flux:button>
                                    <form method="POST" action="{{ route('cups.classify-top-group', $cup) }}"
                                          x-data="{ submit() { if (confirm('Top-Gruppen-Klassifizierung berechnen? Sollte zu Saisonbeginn und vor der Tageswertung laufen.')) this.$el.submit() } }"
                                          @submit.prevent="submit()">
                                        @csrf
                                        <flux:button type="submit" variant="ghost" size="sm" icon="arrow-trending-up">
                                            Top-Gruppe klassifizieren
                                        </flux:button>
                                    </form>
                                    <flux:button href="{{ route('cups.edit', $cup) }}"
                                                 variant="ghost" size="sm" icon="pencil"/>
                                    <form method="POST" action="{{ route('cups.destroy', $cup) }}"
                                          onsubmit="return confirm('Cup „{{ $cup->name }}“ wirklich löschen?');">
                                        @csrf
                                        @method('DELETE')
                                        <flux:button type="submit" variant="ghost" size="sm" icon="trash"/>
                                    </form>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="8">
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
