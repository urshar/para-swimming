@extends('layouts.app')

@section('title', $athlete->full_name)

@section('content')
    <div class="max-w-6xl">
        {{-- Rückweg-Button in derselben Zeile wie der Titel, rechtsbündig statt der sonst
             üblichen zweiten Zeile (Design-Feedback Erik, 15.09.2026) - Ziel und Beschriftung
             ($backUrl/$backLabel) kommen aus WpsAthleteAnalysisController::show(): zur
             Athletenauswahl, außer die Seite wurde über den Athleten selbst erreicht
             (?from=athlete), dann zurück zu dessen Detailseite. --}}
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
            <flux:button href="{{ $backUrl }}" variant="filled" icon="arrow-left" size="sm">
                {{ $backLabel }}
            </flux:button>
        </div>

        @livewire('wps-athlete-analysis', ['athlete' => $athlete])
    </div>
@endsection
