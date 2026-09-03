@extends('layouts.app')

@section('title', "$category->label – $version->label")

@section('content')
    <div class="max-w-6xl">
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $category->label }}</h1>
            <p class="text-sm text-zinc-400">{{ $version->label }}</p>

            {{-- Kein rechtsbündiger Aktions-Block hier: "Exportieren" sitzt jetzt zusammen mit
                 "Neu berechnen" in der Aktionsleiste der Tabelle selbst (Erik, 2026-09-03 —
                 beide Buttons sollen in einer Zeile nebeneinander stehen, gleiche Höhe/Schriftgröße). --}}
            <div class="flex items-center flex-wrap gap-2 mt-4">
                <flux:button href="{{ route('base-times.categories.index', $version) }}" variant="filled"
                             icon="arrow-left" size="sm">
                    Zurück
                </flux:button>
            </div>
        </div>

        @livewire('admin.base-time-table', ['version' => $version, 'category' => $category])
    </div>
@endsection
