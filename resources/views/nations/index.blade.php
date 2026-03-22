@extends('layouts.app')

@section('title', 'Nationen')

@section('content')
    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 overflow-hidden">
        <flux:table>
            <flux:table.head>
                <flux:table.row>
                    <flux:table.cell heading>Code</flux:table.cell>
                    <flux:table.cell heading>Deutsch</flux:table.cell>
                    <flux:table.cell heading>Englisch</flux:table.cell>
                    <flux:table.cell heading>Status</flux:table.cell>
                    <flux:table.cell heading></flux:table.cell>
                </flux:table.row>
            </flux:table.head>
            <flux:table.body>
                @foreach($nations as $nation)
                    <flux:table.row>
                        <flux:table.cell class="font-mono font-semibold text-zinc-900 dark:text-white">
                            {{ $nation->code }}
                        </flux:table.cell>
                        <flux:table.cell>{{ $nation->name_de }}</flux:table.cell>
                        <flux:table.cell
                            class="text-zinc-500 dark:text-zinc-400">{{ $nation->name_en }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" color="{{ $nation->is_active ? 'green' : 'zinc' }}">
                                {{ $nation->is_active ? 'Aktiv' : 'Inaktiv' }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell class="text-right">
                            <flux:button href="{{ route('nations.edit', $nation) }}" size="xs" variant="ghost"
                                         icon="pencil"/>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.body>
        </flux:table>
    </div>
@endsection
