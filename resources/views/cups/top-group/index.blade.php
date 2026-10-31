@php use App\Enums\Module; @endphp

@extends('layouts.app')

@section('title', 'Top-Gruppe — '.$cup->name)

@section('content')
    <div class="max-w-6xl">
        <div class="mb-6">
            <div class="flex items-center gap-2">
                {{-- Zurück zur Gesamtwertungs-Übersicht (Einstieg für alle mit Leserecht), wie bei der Gesamtwertung. --}}
                <flux:button href="{{ route('cups.overall-ranking.index') }}" variant="primary" icon="arrow-left"
                             size="sm" title="Zurück" aria-label="Zurück"/>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Top-Gruppe</h1>
            </div>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                {{ $cup->name }} · Grenze {{ $cup->top_group_points_threshold }} Punkte
                @if($status['calculatedAt'])
                    · eingestuft am {{ $status['calculatedAt']->format('d.m.Y H:i') }} Uhr
                @endif
            </p>

            @can('module.write', Module::Cup)
                <div class="flex items-center flex-wrap justify-end gap-2 mt-4">
                    <flux:button href="{{ route('cups.top-group.create', $cup) }}" variant="filled" icon="plus"
                                 size="sm">
                        Athlet von Hand einstufen
                    </flux:button>
                    <form method="POST" action="{{ route('cups.classify-top-group', $cup) }}"
                          x-data="{ submit() { if (confirm('Top-Gruppe neu einstufen? Hand-Einstufungen bleiben erhalten.')) this.$el.submit() } }"
                          @submit.prevent="submit()">
                        @csrf
                        <flux:button type="submit" variant="primary" icon="arrow-path" size="sm">
                            Neu einstufen
                        </flux:button>
                    </form>
                </div>
            @endcan
        </div>

        @if($status['isStale'])
            <div
                class="mb-4 p-4 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800 rounded-xl text-sm text-amber-700 dark:text-amber-400 flex items-start gap-2">
                <flux:icon name="exclamation-triangle" class="w-4 h-4 mt-0.5 shrink-0"/>
                <span>{{ $status['reason'] }} Bitte neu einstufen.</span>
            </div>
        @endif

        @if($previousCupMeetsWithoutDaily > 0)
            <div
                class="mb-4 p-4 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800 rounded-xl text-sm text-amber-700 dark:text-amber-400 flex items-start gap-2">
                <flux:icon name="exclamation-triangle" class="w-4 h-4 mt-0.5 shrink-0"/>
                <span>
                    Im {{ $previousCup->name }} haben {{ $previousCupMeetsWithoutDaily }} Wettkampf/Wettkämpfe
                    Ergebnisse, aber keine Tageswertung. Diese Starts zählen für die Einstufung nicht.
                </span>
            </div>
        @endif

        <div x-data="topGroupFilter">
            <div class="flex flex-wrap items-end gap-4 mb-4">
                <flux:field class="w-56">
                    <flux:label>Anzeigen</flux:label>
                    <flux:select variant="listbox" x-model="filter">
                        <flux:select.option value="ALL">Alle</flux:select.option>
                        <flux:select.option value="TOP">Top-Gruppe</flux:select.option>
                        <flux:select.option value="NOT_TOP">nicht in der Top-Gruppe</flux:select.option>
                        <flux:select.option value="MANUAL">von Hand eingestuft</flux:select.option>
                    </flux:select>
                </flux:field>
                <p class="text-sm text-zinc-500 dark:text-zinc-400 pb-2">
                    {{ $classifications->where('is_top_group', true)->count() }} von {{ $classifications->count() }}
                    eingestuften Athleten in der Top-Gruppe
                </p>
            </div>

            <div
                class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden p-4 [--flux-bleed:1rem]">
                <flux:table bleed>
                    <flux:table.columns>
                        <flux:table.column>Athlet</flux:table.column>
                        <flux:table.column>Verein</flux:table.column>
                        <flux:table.column>Einstufung</flux:table.column>
                        <flux:table.column>Grund</flux:table.column>
                        <flux:table.column>Vorjahr</flux:table.column>
                        <flux:table.column>Letzter Start</flux:table.column>
                        <flux:table.column>Start davor</flux:table.column>
                        @can('module.write', Module::Cup)
                            <flux:table.column></flux:table.column>
                        @endcan
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse($classifications as $row)
                            <flux:table.row
                                x-show="matches({{ $row->is_top_group ? 'true' : 'false' }}, {{ $row->isManual() ? 'true' : 'false' }})">
                                <flux:table.cell class="font-medium">
                                    @can('module.read', Module::Athletes)
                                        <flux:link href="{{ route('athletes.show', $row->athlete) }}" variant="subtle">
                                            {{ $row->athlete->display_name }}
                                        </flux:link>
                                    @else
                                        {{ $row->athlete->display_name }}
                                    @endcan
                                </flux:table.cell>
                                <flux:table.cell>{{ $row->athlete->club?->display_name ?? '—' }}</flux:table.cell>
                                <flux:table.cell>
                                    @if($row->is_top_group)
                                        <flux:badge color="emerald" size="sm">Top-Gruppe</flux:badge>
                                    @else
                                        <flux:badge color="zinc" size="sm">nicht Top</flux:badge>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>
                                    @if($row->isManual())
                                        <div class="flex items-center gap-1.5">
                                            <flux:badge color="amber" size="sm">von Hand</flux:badge>
                                            <span class="text-sm text-zinc-600 dark:text-zinc-300">{{ $row->manual_note }}</span>
                                        </div>
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
                                            {{ $row->manualSetBy?->name ?? 'unbekannt' }},
                                            {{ $row->manual_set_at?->format('d.m.Y') }}
                                            · Regel: {{ $row->reason?->label() ?? 'noch nicht berechnet' }}
                                            ({{ $row->calculated_is_top_group ? 'Top' : 'nicht Top' }})
                                        </div>
                                    @else
                                        {{ $row->reason?->label() ?? '—' }}
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>
                                    @if($row->previous_is_top_group === null)
                                        —
                                    @else
                                        {{ $row->previous_is_top_group ? 'Top' : 'nicht Top' }}
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>
                                    @if($row->last_start_year)
                                        {{ $row->last_start_year }}:
                                        <span @class(['font-semibold' => $row->last_start_points > $cup->top_group_points_threshold])>
                                            {{ $row->last_start_points }}
                                        </span>
                                    @else
                                        —
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>
                                    @if($row->prior_start_year)
                                        {{ $row->prior_start_year }}:
                                        <span @class(['font-semibold' => $row->prior_start_points > $cup->top_group_points_threshold])>
                                            {{ $row->prior_start_points }}
                                        </span>
                                    @else
                                        —
                                    @endif
                                </flux:table.cell>
                                @can('module.write', Module::Cup)
                                    <flux:table.cell>
                                        <div class="flex justify-end">
                                            <flux:button href="{{ route('cups.top-group.edit', [$cup, $row->athlete]) }}"
                                                         variant="ghost" size="sm" icon="pencil"
                                                         class="text-amber-500!" tooltip="Von Hand einstufen"/>
                                        </div>
                                    </flux:table.cell>
                                @endcan
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="8">
                                    <p class="text-sm text-zinc-400 py-4 text-center">
                                        Noch keine Einstufung für diesen Cup.
                                    </p>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>
        </div>
    </div>
@endsection
