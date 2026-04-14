@php
    use App\Support\TimeParser;
@endphp

@extends('layouts.app')

@section('title', 'Meldung bearbeiten – ' . $meet->name)

@section('content')
    <!--suppress BadExpressionStatementJS -->
    <div class="max-w-2xl">

        {{-- Header --}}
        <div class="flex items-center gap-3 mb-6">
            <flux:button href="{{ route('club-entries.index', $meet) }}" variant="ghost" icon="arrow-left" size="sm"/>
            <div>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Meldung bearbeiten</h1>
                <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                    {{ $meet->name }} · {{ $club->display_name }}
                </p>
            </div>
        </div>

        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">

            {{-- Info-Block (nicht editierbar) --}}
            <div class="mb-6 p-4 rounded-lg bg-zinc-50 dark:bg-zinc-900/40 border border-zinc-200 dark:border-zinc-700">
                <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                    <div>
                        <dt class="text-xs text-zinc-400 uppercase tracking-wide">Event</dt>
                        <dd class="font-medium text-zinc-900 dark:text-zinc-100 mt-0.5">
                            {{ $entry->swimEvent->event_number ? 'Nr. '.$entry->swimEvent->event_number.' – ' : '' }}
                            {{ $entry->swimEvent->display_name }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-400 uppercase tracking-wide">Athlet</dt>
                        <dd class="font-medium text-zinc-900 dark:text-zinc-100 mt-0.5">
                            {{ $entry->athlete->last_name }}, {{ $entry->athlete->first_name }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-400 uppercase tracking-wide">Sportklasse</dt>
                        <dd class="mt-0.5">
                            <span class="inline-block px-2 py-0.5 text-xs font-mono rounded
                                         bg-zinc-100 dark:bg-zinc-700 text-zinc-600 dark:text-zinc-300">
                                {{ $entry->sport_class ?? '–' }}
                            </span>
                        </dd>
                    </div>
                </dl>
            </div>

            {{-- Bestzeiten --}}
            <div
                class="mb-5 p-3 rounded-lg bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-700 text-sm">
                <p class="text-xs font-medium text-blue-600 dark:text-blue-400 mb-2 uppercase tracking-wide">
                    Jahresbestzeit (Vorjahr bis Meet beginn)
                </p>
                <div class="flex gap-6">
                    <div>
                        <span class="text-xs text-zinc-400">LCM</span>
                        <p class="font-mono font-semibold text-zinc-900 dark:text-zinc-100">
                            {{ $bestTimes['LCM'] ? TimeParser::display($bestTimes['LCM']) : 'NT' }}
                        </p>
                    </div>
                    <div>
                        <span class="text-xs text-zinc-400">SCM</span>
                        <p class="font-mono font-semibold text-zinc-900 dark:text-zinc-100">
                            {{ $bestTimes['SCM'] ? TimeParser::display($bestTimes['SCM']) : 'NT' }}
                        </p>
                    </div>
                </div>
            </div>

            {{-- Formular --}}
            <form method="POST" action="{{ route('club-entries.update', [$meet, $entry]) }}"
                  x-data="{ entryTime: '{{ old('entry_time', $entry->formatted_entry_time !== 'NT' ? $entry->formatted_entry_time : '') }}' }">
                @csrf
                @method('PUT')

                @if($errors->any())
                    <div class="mb-4 p-3 bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-800
                                rounded-xl text-sm text-red-700 dark:text-red-400">
                        @foreach($errors->all() as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    </div>
                @endif

                <div class="grid grid-cols-2 gap-4 mb-6">
                    <flux:field>
                        <flux:label>Meldezeit</flux:label>
                        <flux:input
                            name="entry_time"
                            x-model="entryTime"
                            placeholder="MM:SS.hh oder NT"
                            autocomplete="off"/>
                        <flux:description>Format: 01:23.45 oder NT</flux:description>
                        <flux:error name="entry_time"/>
                    </flux:field>

                    <flux:field>
                        <flux:label>Kurs</flux:label>
                        <flux:select name="entry_course">
                            <option
                                value="LCM" @selected(old('entry_course', $entry->entry_course ?? $meet->course) === 'LCM')>
                                LCM (50m)
                            </option>
                            <option
                                value="SCM" @selected(old('entry_course', $entry->entry_course ?? $meet->course) === 'SCM')>
                                SCM (25m)
                            </option>
                            <option
                                value="SCY" @selected(old('entry_course', $entry->entry_course ?? $meet->course) === 'SCY')>
                                SCY (Yards)
                            </option>
                        </flux:select>
                        <flux:error name="entry_course"/>
                    </flux:field>
                </div>

                {{-- Bestzeit-Übernahme --}}
                @if($bestTimes[$meet->course])
                    <div class="mb-4">
                        <button type="button"
                                x-on:click="entryTime = '{{ TimeParser::display($bestTimes[$meet->course]) }}'"
                                class="text-sm text-blue-600 dark:text-blue-400 hover:underline">
                            Bestzeit übernehmen ({{ $meet->course }}:
                            {{ TimeParser::display($bestTimes[$meet->course]) }})
                        </button>
                    </div>
                @endif

                <div class="flex gap-3 pt-2">
                    <flux:button type="submit" variant="primary">
                        Speichern
                    </flux:button>
                    <flux:button href="{{ route('club-entries.index', $meet) }}" variant="ghost">
                        Abbrechen
                    </flux:button>
                </div>

            </form>
        </div>
    </div>
@endsection
