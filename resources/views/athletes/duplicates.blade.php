@php use App\Services\AthleteDuplicateFinder; use App\Support\ListUrl; @endphp

@extends('layouts.app')

@section('title', 'Dubletten')

@section('content')
    @php
        $strengths = [
            AthleteDuplicateFinder::STRONG => ['stark', 'red'],
            AthleteDuplicateFinder::MEDIUM => ['mittel', 'amber'],
            AthleteDuplicateFinder::WEAK => ['schwach', 'zinc'],
        ];
    @endphp

    <div class="max-w-5xl">
        <div class="mb-6">
            <div class="flex items-center gap-2">
                <flux:button href="{{ ListUrl::to('athletes') }}" variant="primary" icon="arrow-left"
                             size="sm" title="Zurück" aria-label="Zurück"/>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Dubletten</h1>
            </div>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                Athleten, die vermutlich dieselbe Person sind. Vorbelegt bleibt der Athlet mit mehr Daten; in der
                Vorschau lässt sich die Richtung tauschen.
            </p>
            <div class="flex items-center flex-wrap justify-end gap-2 mt-4">
                @if($withDismissed)
                    <flux:button href="{{ route('athletes.duplicates.index') }}" variant="filled" icon="eye-slash" size="sm">
                        Ausgeblendete verbergen
                    </flux:button>
                @else
                    <flux:button href="{{ route('athletes.duplicates.index', ['dismissed' => 1]) }}" variant="filled"
                                 icon="eye" size="sm">
                        Ausgeblendete anzeigen
                    </flux:button>
                @endif
            </div>
        </div>

        <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-4" role="status">
            {{ count($pairs) === 1 ? '1 Vorschlag' : count($pairs).' Vorschläge' }}
        </p>

        @forelse($pairs as $pair)
            @php
                [$strengthLabel, $strengthColor] = $strengths[$pair['strength']];
            @endphp
            <article class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-5 mb-4"
                     aria-label="Vorschlag: #{{ $pair['keep']->id }} und #{{ $pair['drop']->id }}">
                <div class="flex flex-wrap items-center gap-2 mb-3">
                    <flux:badge :color="$strengthColor" size="sm">{{ $strengthLabel }}</flux:badge>
                    <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ implode(' · ', $pair['reasons']) }}</span>
                    @if($pair['dismissal'])
                        <flux:badge color="zinc" size="sm">als "kein Duplikat" markiert</flux:badge>
                    @endif
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    @foreach(['keep' => 'Bleibt', 'drop' => 'Wird aufgelöst'] as $role => $roleLabel)
                        @php
                            $athlete = $pair[$role];
                        @endphp
                        <div class="text-sm">
                            <p class="text-zinc-500 dark:text-zinc-400">{{ $roleLabel }}</p>
                            <a href="{{ route('athletes.show', $athlete) }}"
                               class="font-semibold text-zinc-900 dark:text-zinc-100 hover:text-blue-600">
                                #{{ $athlete->id }} {{ $athlete->display_name }}
                            </a>
                            <p class="text-slate-700 dark:text-slate-300">
                                {{ $athlete->birth_date?->format('d.m.Y') ?? 'ohne Geburtsdatum' }}
                                · {{ $athlete->club?->display_name ?? 'ohne Verein' }}
                                · {{ $athlete->license ?? 'ohne Lizenz' }}
                            </p>
                            <p class="text-zinc-500 dark:text-zinc-400">
                                {{ $athlete->results_count }} Ergebnisse · {{ $athlete->swim_records_count }} Rekorde
                                · {{ $athlete->entries_count }} Meldungen
                            </p>
                        </div>
                    @endforeach
                </div>

                <div class="flex flex-wrap gap-2">
                    <flux:button href="{{ route('athletes.merge.preview', [$pair['keep'], $pair['drop'], 'from' => 'duplicates']) }}"
                                 variant="primary" icon="arrows-pointing-in" size="sm">
                        Vorschau
                    </flux:button>
                    @if($pair['dismissal'])
                        <form method="POST" action="{{ route('athletes.duplicates.restore', $pair['dismissal']) }}">
                            @csrf
                            @method('DELETE')
                            <flux:button type="submit" variant="filled" icon="arrow-uturn-left" size="sm">
                                Markierung zurücknehmen
                            </flux:button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('athletes.duplicates.dismiss') }}">
                            @csrf
                            <input type="hidden" name="athlete_id" value="{{ $pair['keep']->id }}">
                            <input type="hidden" name="other_athlete_id" value="{{ $pair['drop']->id }}">
                            <flux:button type="submit" variant="filled" icon="eye-slash" size="sm">
                                Kein Duplikat
                            </flux:button>
                        </form>
                    @endif
                </div>
            </article>
        @empty
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-5 text-sm text-zinc-500 dark:text-zinc-400">
                Keine Dublettenvorschläge.
            </div>
        @endforelse
    </div>
@endsection
