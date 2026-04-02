@extends('layouts.app')

@section('title', 'Nationen')

@section('content')
    <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100 mb-6">Nationen</h1>

    <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 overflow-hidden">
        <flux:table
            class="[&_td:first-child]:ps-4 [&_th:first-child]:ps-4 [&_td:last-child]:pe-4 [&_th:last-child]:pe-4">
            <flux:table.columns>
                <flux:table.column>Flagge</flux:table.column>
                <flux:table.column>Code</flux:table.column>
                <flux:table.column>Deutsch</flux:table.column>
                <flux:table.column>Englisch</flux:table.column>
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
                                         icon="pencil"/>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
@endsection
