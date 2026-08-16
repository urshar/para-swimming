@extends('layouts.app')

@section('title', $athlete->full_name)

@section('content')
    <div class="max-w-6xl">
        <div class="flex items-start justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $athlete->full_name }}</h1>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    @if($athlete->birth_date)
                        Jg. {{ $athlete->birth_date->format('Y') }} ·
                    @endif
                    {{ $athlete->club?->display_name }}
                </p>
            </div>
            <flux:button href="{{ route('athletes.show', $athlete) }}" variant="ghost" size="sm">
                Zum Athleten
            </flux:button>
        </div>

        @livewire('wps-athlete-analysis', ['athlete' => $athlete])
    </div>
@endsection
