@extends('layouts.app')

@section('title', $classifier->full_name)

@section('content')

    <div class="flex items-start justify-between mb-6">
        <div class="flex items-center gap-3">
            <flux:button href="{{ route('classifiers.index') }}" variant="ghost" icon="arrow-left" size="sm"/>
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $classifier->full_name }}</h1>
                    <flux:badge color="{{ $classifier->type === 'MED' ? 'red' : 'blue' }}">
                        {{ $classifier->type_name }}
                    </flux:badge>
                    @if(!$classifier->is_active)
                        <flux:badge color="zinc">Inaktiv</flux:badge>
                    @endif
                </div>
                <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                    {{ match($classifier->gender ?? '') { 'M' => 'Herr', 'F' => 'Dame', 'N' => 'Nicht binär', default => '' } }}
                    @if($classifier->nation)
                        · {{ $classifier->nation->code }}
                    @endif
                </p>
            </div>
        </div>
        <flux:button href="{{ route('classifiers.edit', $classifier) }}" variant="ghost" icon="pencil" size="sm">
            Bearbeiten
        </flux:button>
    </div>

    <div class="grid grid-cols-3 gap-6 mb-6">

        {{-- Stammdaten --}}
        <div class="col-span-2 bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-5">
            <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-4">Stammdaten</h2>
            <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Typ</dt>
                    <dd class="font-medium mt-0.5">{{ $classifier->type_name }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Nation</dt>
                    <dd class="font-medium mt-0.5">{{ $classifier->nation?->code ?? '–' }}
                        – {{ $classifier->nation?->name_de ?? '' }}</dd>
                </div>
                @if($classifier->email)
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">E-Mail</dt>
                        <dd class="font-medium mt-0.5">
                            <a href="mailto:{{ $classifier->email }}" class="hover:text-blue-600 transition-colors">
                                {{ $classifier->email }}
                            </a>
                        </dd>
                    </div>
                @endif
                @if($classifier->phone)
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">Telefon</dt>
                        <dd class="font-medium mt-0.5">{{ $classifier->phone }}</dd>
                    </div>
                @endif
            </dl>

            @if($classifier->notes)
                <div class="mt-4 pt-4 border-t border-zinc-100 dark:border-zinc-700">
                    <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide mb-1">
                        Notizen
                    </dt>
                    <p class="text-sm text-zinc-700 dark:text-zinc-300 whitespace-pre-line">{{ $classifier->notes }}</p>
                </div>
            @endif
        </div>

        {{-- Statistik --}}
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-5">
            <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-4">Einsätze</h2>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <dt class="text-zinc-500 dark:text-zinc-400">Als med. Klassifizierer</dt>
                    <dd class="font-medium">{{ $classifier->classifications_as_med_count }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-zinc-500 dark:text-zinc-400">Als tech. Klassifizierer 1</dt>
                    <dd class="font-medium">{{ $classifier->classifications_as_tech1_count }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-zinc-500 dark:text-zinc-400">Als tech. Klassifizierer 2</dt>
                    <dd class="font-medium">{{ $classifier->classifications_as_tech2_count }}</dd>
                </div>
                <div class="flex justify-between border-t border-zinc-100 dark:border-zinc-700 pt-3">
                    <dt class="text-zinc-700 dark:text-zinc-300 font-medium">Gesamt</dt>
                    <dd class="font-bold">
                        {{ $classifier->classifications_as_med_count
                         + $classifier->classifications_as_tech1_count
                         + $classifier->classifications_as_tech2_count }}
                    </dd>
                </div>
            </dl>
        </div>
    </div>

    {{-- Klassifikations-History --}}
    <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100 mb-3">Klassifikationen</h2>

    <flux:table class="[&_td:first-child]:ps-4 [&_th:first-child]:ps-4 [&_td:last-child]:pe-4 [&_th:last-child]:pe-4">
        <flux:table.columns>
            <flux:table.column>Datum</flux:table.column>
            <flux:table.column>Athlet</flux:table.column>
            <flux:table.column>Ort</flux:table.column>
            <flux:table.column>Rolle</flux:table.column>
            <flux:table.column>Ergebnis</flux:table.column>
            <flux:table.column>Status</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse($classifications as $cl)
                <flux:table.row>
                    <flux:table.cell class="text-sm text-zinc-600 dark:text-zinc-400">
                        {{ $cl->classified_at->format('d.m.Y') }}
                    </flux:table.cell>
                    <flux:table.cell>
                        @if($cl->athlete)
                            <a href="{{ route('athletes.show', $cl->athlete) }}"
                               class="font-medium text-zinc-900 dark:text-zinc-100 hover:text-blue-600 transition-colors">
                                {{ $cl->athlete->display_name }}
                            </a>
                        @else
                            <span class="text-zinc-400">–</span>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ $cl->location ?? '–' }}
                    </flux:table.cell>
                    <flux:table.cell>
                        @if($cl->med_classifier_id === $classifier->id)
                            <flux:badge size="sm" color="red">Medizinisch</flux:badge>
                        @elseif($cl->tech1_classifier_id === $classifier->id)
                            <flux:badge size="sm" color="blue">Technisch 1</flux:badge>
                        @elseif($cl->tech2_classifier_id === $classifier->id)
                            <flux:badge size="sm" color="blue">Technisch 2</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="font-mono text-sm">
                        {{ $cl->sport_class_result ?? '–' }}
                    </flux:table.cell>
                    <flux:table.cell>
                        @if($cl->status)
                            <flux:badge size="sm" color="{{ match($cl->status) {
                                'CONFIRMED'   => 'emerald',
                                'NEW'         => 'blue',
                                'REVIEW'      => 'amber',
                                'OBSERVATION' => 'orange',
                                default       => 'zinc',
                            } }}">{{ $cl->status }}</flux:badge>
                        @else
                            <span class="text-zinc-400 text-sm">–</span>
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="text-center py-8 text-zinc-400">
                        Noch keine Klassifikationen vorhanden.
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <div class="mt-4">{{ $classifications->links() }}</div>

@endsection
