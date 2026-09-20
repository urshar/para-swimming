@extends('layouts.app')

@section('title', "$category->label – $version->label")

@section('content')
    <div class="max-w-6xl">
        <div class="mb-6">
            <div class="flex items-center gap-2">
                <flux:button href="{{ route('base-times.categories.index', $version) }}" variant="primary"
                             icon="arrow-left" size="sm" title="Zurück" aria-label="Zurück"/>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $category->label }}</h1>
            </div>
            <p class="text-sm text-zinc-400">{{ $version->label }}</p>
            {{-- Kein rechtsbündiger Aktions-Block hier: "Exportieren" sitzt jetzt zusammen mit
                 "Neu berechnen" in der Aktionsleiste der Tabelle selbst (Erik, 2026-09-03 —
                 beide Buttons sollen in einer Zeile nebeneinander stehen, gleiche Höhe/Schriftgröße). --}}
        </div>

        @livewire('admin.base-time-table', ['version' => $version, 'category' => $category])
    </div>
@endsection
