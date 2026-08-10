@extends('layouts.app')

@section('title', 'Qualifikanten')

@section('content')
    <div class="max-w-6xl">
        <div class="flex items-start justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Qualifikanten</h1>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    {{ $championship->display_name }} ·
                    Qualifikationszeitraum {{ $championship->qualification_start->format('d.m.Y') }}
                    bis {{ $championship->qualification_end->format('d.m.Y') }}
                </p>
            </div>
            <div class="flex gap-2">
                <flux:button href="{{ route('championships.selection', $championship) }}"
                             variant="ghost" size="sm">Auswahl
                </flux:button>
                <flux:button href="{{ route('championships.development', $championship) }}"
                             variant="ghost" size="sm">Förderansicht
                </flux:button>
                <flux:button href="{{ route('championships.show', $championship) }}"
                             variant="ghost" size="sm">Normen
                </flux:button>
            </div>
        </div>

        @livewire('admin.championship-qualification-table', [
            'championship' => $championship,
            'clubId' => $clubId,
        ])
    </div>
@endsection
