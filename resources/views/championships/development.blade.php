@extends('layouts.app')

@section('title', 'Förderansicht')

@section('content')
    <div class="max-w-6xl">
        <div class="mb-6">
            <div class="flex items-center gap-2">
                <flux:button href="{{ route('championships.index') }}" variant="primary" icon="arrow-left"
                             size="sm" title="Zur Übersicht" aria-label="Zur Übersicht"/>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Förderansicht</h1>
            </div>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                {{ $championship->display_name }} ·
                Qualifikationszeitraum {{ $championship->qualification_start->format('d.m.Y') }}
                bis {{ $championship->qualification_end->format('d.m.Y') }}
            </p>

            <div class="flex items-center flex-wrap justify-end gap-2 mt-4">
                <flux:button href="{{ route('championships.qualified', $championship) }}" variant="filled"
                             size="sm" class="text-blue-500!">Qualifikanten</flux:button>
                <flux:button href="{{ route('championships.selection', $championship) }}" variant="filled"
                             size="sm" class="text-blue-500!">Auswahl</flux:button>
                <flux:button href="{{ route('championships.show', $championship) }}" variant="filled"
                             size="sm" class="text-blue-500!">Normen</flux:button>
            </div>
        </div>

        @livewire('admin.championship-development-table', [
            'championship' => $championship,
            'clubId' => $clubId,
        ])
    </div>
@endsection
