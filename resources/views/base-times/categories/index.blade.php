@extends('layouts.app')

@section('title', "Basiswerte – $version->label")

@section('content')
    <div class="max-w-4xl">
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $version->label }}</h1>
            <p class="text-sm text-zinc-400">
                Gültig ab {{ $version->valid_from->format('d.m.Y') }}
                @if($version->valid_until) bis {{ $version->valid_until->format('d.m.Y') }} @endif
            </p>

            <div class="flex items-center flex-wrap gap-2 mt-4">
                <flux:button href="{{ route('base-times.versions.index') }}" variant="filled" icon="arrow-left"
                             size="sm">
                    Zurück
                </flux:button>

                <div class="ml-auto flex items-center flex-wrap gap-2">
                    <flux:button href="{{ route('base-times.import', ['version' => $version->id]) }}"
                                 variant="filled" icon="arrow-up-tray" size="sm" class="text-blue-500!">
                        Importieren
                    </flux:button>
                    <flux:button href="{{ route('base-times.export', $version) }}"
                                 variant="filled" icon="arrow-down-tray" size="sm" class="text-emerald-500!">
                        Exportieren
                    </flux:button>
                </div>
            </div>
        </div>

        @if(session('success'))
            <div
                class="mb-4 p-4 bg-green-50 dark:bg-green-950/20 border border-green-200 dark:border-green-800 rounded-xl text-sm text-green-700 dark:text-green-400">
                {{ session('success') }}
            </div>
        @endif

        <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
            @foreach($categories as $category)
                <a href="{{ route('base-times.categories.show', [$version, $category]) }}"
                   class="block bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-5
                          hover:border-blue-400 dark:hover:border-blue-600 transition-colors">
                    <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-1">{{ $category->label }}</h2>
                    <p class="text-xs text-zinc-400 mb-3">{{ $category->course }} · {{ $category->gender }}</p>
                    <div class="flex gap-2 text-xs">
                        <flux:badge color="zinc">{{ $category->manual_count }} manuell</flux:badge>
                        <flux:badge color="orange">{{ $category->calculated_count }} berechnet</flux:badge>
                    </div>
                </a>
            @endforeach
        </div>
    </div>
@endsection
