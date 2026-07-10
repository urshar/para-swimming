@extends('layouts.app')

@section('title', "$category->label – $version->label")

@section('content')
    <div class="max-w-6xl">
        <div class="flex items-center gap-3 mb-6">
            <flux:button href="{{ route('base-times.categories.index', $version) }}" variant="ghost" icon="arrow-left"
                         size="sm"/>
            <div class="flex-1">
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $category->label }}</h1>
                <p class="text-sm text-zinc-400">{{ $version->label }}</p>
            </div>
            <flux:button href="{{ route('base-times.export', $version) }}" variant="ghost" icon="arrow-down-tray">
                Exportieren
            </flux:button>
        </div>

        @livewire('admin.base-time-table', ['version' => $version, 'category' => $category])
    </div>
@endsection
