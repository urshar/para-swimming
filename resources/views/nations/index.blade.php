@extends('layouts.app')

@section('title', 'Nationen')

@section('content')
    <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100 mb-6">Nationen</h1>

    @php
        // Sortierbare Spalten-Header ohne Livewire: flux:table.sortable rendert einen <button> ohne
        // eigene Navigation (für wire:click gedacht) — <a> darin wäre ungültiges HTML (interaktiver
        // Inhalt in interaktivem Inhalt). Header daher als eigener Link gebaut, optisch an
        // flux:table.sortable angelehnt (gleicher Chevron, gleiche Hover-Farbe).
        $sortLink = fn (string $column) => route('nations.index', array_merge(
            request()->except('page'),
            ['sort' => $column, 'direction' => $sort === $column && $direction === 'asc' ? 'desc' : 'asc']
        ));
    @endphp

    <flux:table class="[&_td:first-child]:ps-4 [&_th:first-child]:ps-4 [&_td:last-child]:pe-4 [&_th:last-child]:pe-4">
        <flux:table.columns>
            <flux:table.column>Flagge</flux:table.column>
            <flux:table.column>
                <a href="{{ $sortLink('code') }}"
                   class="group flex items-center gap-1 hover:text-zinc-800 dark:hover:text-white">
                    Code
                    <flux:icon.chevron-down variant="micro"
                        class="{{ $sort === 'code' ? ($direction === 'desc' ? 'rotate-180' : '') : 'opacity-0 group-hover:opacity-100' }}"/>
                </a>
            </flux:table.column>
            <flux:table.column>
                <a href="{{ $sortLink('name_de') }}"
                   class="group flex items-center gap-1 hover:text-zinc-800 dark:hover:text-white">
                    Deutsch
                    <flux:icon.chevron-down variant="micro"
                        class="{{ $sort === 'name_de' ? ($direction === 'desc' ? 'rotate-180' : '') : 'opacity-0 group-hover:opacity-100' }}"/>
                </a>
            </flux:table.column>
            <flux:table.column>
                <a href="{{ $sortLink('name_en') }}"
                   class="group flex items-center gap-1 hover:text-zinc-800 dark:hover:text-white">
                    Englisch
                    <flux:icon.chevron-down variant="micro"
                        class="{{ $sort === 'name_en' ? ($direction === 'desc' ? 'rotate-180' : '') : 'opacity-0 group-hover:opacity-100' }}"/>
                </a>
            </flux:table.column>
            <flux:table.column>Status</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach($nations as $nation)
                <flux:table.row>
                    <flux:table.cell>
                        <x-flag code="{{ $nation->code }}" :label="$nation->name_de" class="w-7 h-5"/>
                    </flux:table.cell>
                    <flux:table.cell class="font-mono font-semibold text-zinc-900 dark:text-white">
                        {{ $nation->code }}
                    </flux:table.cell>
                    <flux:table.cell>{{ $nation->name_de }}</flux:table.cell>
                    <flux:table.cell
                        class="text-zinc-500 dark:text-zinc-400">{{ $nation->name_en }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" color="{{ $nation->is_active ? 'emerald' : 'zinc' }}">
                            {{ $nation->is_active ? 'Aktiv' : 'Inaktiv' }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell class="text-right">
                        <flux:button href="{{ route('nations.edit', $nation) }}" size="xs" variant="ghost"
                                     icon="pencil" class="text-amber-500!"/>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <div class="mt-4">{{ $nations->links() }}</div>
@endsection
