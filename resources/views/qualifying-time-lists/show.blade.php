@extends('layouts.app')

@section('title', "Richtzeiten $list->year")

@section('content')
    <div class="max-w-3xl">
        <div class="flex items-center gap-3 mb-6">
            <flux:button href="{{ route('qualifying-time-lists.index') }}" variant="ghost" icon="arrow-left" size="sm"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Richtzeiten {{ $list->year }}</h1>
            @if($list->is_active)
                <flux:badge color="emerald">Aktiv</flux:badge>
            @else
                <flux:badge color="zinc">Inaktiv</flux:badge>
            @endif
            @if($list->isLatest())
                <flux:badge color="blue">Aktuell</flux:badge>
            @else
                <flux:badge color="zinc">Historisiert — schreibgeschützt</flux:badge>
            @endif
        </div>

        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 mb-6">
            <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-4">Zielpunkte je Sportklasse</h2>
            <p class="text-xs text-zinc-400 mb-4">Standard: 100 Punkte. Nur abweichende Sportklassen sind hier gelistet.</p>

            @if($list->targetPoints->isNotEmpty())
                <div class="flex flex-wrap gap-2">
                    @foreach($list->targetPoints->sortBy('sport_class') as $tp)
                        <span
                            class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-zinc-100 dark:bg-zinc-700 text-sm text-zinc-800 dark:text-zinc-200">
                            {{ $tp->sport_class }}: {{ $tp->points }} Pkt.
                        </span>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-zinc-400">Keine Overrides — für alle Sportklassen gelten 100 Punkte.</p>
            @endif
        </div>

        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
            <div class="p-6 pb-0">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">Richtzeiten</h2>
            </div>
            <flux:table
                class="mt-4 [&_td:first-child]:ps-4 [&_th:first-child]:ps-4 [&_td:last-child]:pe-4 [&_th:last-child]:pe-4">
                <flux:table.columns>
                    <flux:table.column>Bewerb</flux:table.column>
                    <flux:table.column>Geschlecht</flux:table.column>
                    <flux:table.column>Sportklasse</flux:table.column>
                    <flux:table.column>Richtzeit</flux:table.column>
                    <flux:table.column>Quelle</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse($list->times->sortBy(['distance', 'gender', 'sport_class']) as $time)
                        <flux:table.row>
                            <flux:table.cell>{{ $time->distance }}m {{ $time->strokeType?->name_de }}</flux:table.cell>
                            <flux:table.cell>{{ $time->gender }}</flux:table.cell>
                            <flux:table.cell class="font-mono">{{ $time->sport_class }}</flux:table.cell>
                            <flux:table.cell class="font-mono">{{ $time->formatted_value ?? '–' }}</flux:table.cell>
                            <flux:table.cell>
                                @if($time->isManual())
                                    <flux:badge color="amber">Manuell</flux:badge>
                                @else
                                    <flux:badge color="blue">Berechnet</flux:badge>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="5">
                                <p class="text-sm text-zinc-400 py-4 text-center">Noch keine Richtzeiten hinterlegt.</p>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    </div>
@endsection
