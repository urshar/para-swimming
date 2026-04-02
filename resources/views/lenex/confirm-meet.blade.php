@php use Carbon\Carbon; @endphp
@extends('layouts.app')

@section('title', 'LENEX Import — Wettkampf zuordnen')

@section('content')
    <div class="max-w-2xl">
        <div class="flex items-center gap-3 mb-6">
            <flux:button href="{{ route('lenex.import') }}" variant="ghost" icon="arrow-left" size="sm"/>
            <div>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Wettkampf zuordnen</h1>
                <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                    {{ $type === 'entries' ? 'Meldungen' : 'Ergebnisse' }} werden importiert
                </p>
            </div>
        </div>

        {{-- Erkannter Wettkampf aus der Datei --}}
        <div class="bg-zinc-50 dark:bg-zinc-800/60 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 mb-5">
            <p class="text-xs font-semibold text-zinc-400 uppercase tracking-wider mb-2">Erkannt in der LENEX-Datei</p>
            <p class="font-medium text-zinc-900 dark:text-zinc-100">{{ $meta['name'] }}</p>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                {{ $meta['city'] ?? '–' }}
                @if($meta['start_date'] ?? null)
                    · {{ Carbon::parse($meta['start_date'])->format('d.m.Y') }}
                @endif
                @if($meta['course'] ?? null)
                    · {{ $meta['course'] }}
                @endif
            </p>
        </div>

        <form method="POST" action="{{ route('lenex.import.run') }}">
            @csrf
            <input type="hidden" name="import_session" value="{{ $importSession }}">

            <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-3">
                Zu welchem Wettkampf sollen die {{ $type === 'entries' ? 'Meldungen' : 'Ergebnisse' }} importiert
                werden?
            </p>

            <div class="space-y-2">

                {{-- Vorhandene Wettkämpfe --}}
                @foreach($candidates as $meet)
                    <label class="flex items-start gap-3 p-4 rounded-xl border cursor-pointer transition-colors
                                  border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800
                                  hover:border-blue-400 dark:hover:border-blue-500
                                  has-checked:border-blue-500 has-checked:bg-blue-50 dark:has-checked:bg-blue-950/30">
                        <input type="radio" name="meet_id" value="{{ $meet->id }}"
                               class="mt-1 accent-blue-600" required>
                        <div class="flex-1 min-w-0">
                            <p class="font-medium text-zinc-900 dark:text-zinc-100">{{ $meet->name }}</p>
                            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                                {{ $meet->city ?? '–' }}
                                @if($meet->start_date)
                                    · {{ Carbon::parse($meet->start_date)->format('d.m.Y') }}
                                @endif
                                @if($meet->course)
                                    · {{ $meet->course }}
                                @endif
                            </p>
                            <div class="flex gap-2 mt-1.5 flex-wrap">
                                <flux:badge size="sm" color="zinc">
                                    {{ $meet->swim_events_count ?? $meet->swimEvents()->count() }} Disziplinen
                                </flux:badge>
                                @if($meet->entries_count ?? $meet->entries()->count())
                                    <flux:badge size="sm" color="zinc">
                                        {{ $meet->entries_count ?? $meet->entries()->count() }} Meldungen
                                    </flux:badge>
                                @endif
                            </div>
                        </div>
                        {{-- Datum-Match-Indikator --}}
                        @if($meta['start_date'] && $meet->start_date?->format('Y-m-d') === $meta['start_date'])
                            <flux:badge size="sm" color="green">Datum stimmt überein</flux:badge>
                        @endif
                    </label>
                @endforeach

                {{-- Option: Neues Meet anlegen --}}
                <label class="flex items-start gap-3 p-4 rounded-xl border cursor-pointer transition-colors
                              border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800
                              hover:border-blue-400 dark:hover:border-blue-500
                              has-checked:border-blue-500 has-checked:bg-blue-50 dark:has-checked:bg-blue-950/30">
                    <input type="radio" name="meet_id" value=""
                           class="mt-1 accent-blue-600" {{ $candidates->isEmpty() ? 'checked' : '' }}>
                    <div>
                        <p class="font-medium text-zinc-900 dark:text-zinc-100">Als neuen Wettkampf importieren</p>
                        <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                            Legt „{{ $meta['name'] }}" neu in der Datenbank an
                        </p>
                    </div>
                </label>

            </div>

            @error('import')
            <p class="text-sm text-red-500 mt-3">{{ $message }}</p>
            @enderror

            <div class="flex gap-3 mt-6">
                <flux:button type="submit" variant="primary" icon="arrow-up-tray">
                    Import starten
                </flux:button>
                <flux:button href="{{ route('lenex.import') }}" variant="ghost">
                    Abbrechen
                </flux:button>
            </div>
        </form>
    </div>
@endsection
