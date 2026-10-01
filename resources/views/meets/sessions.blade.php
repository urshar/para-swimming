@php use App\Models\MeetSession; @endphp

@extends('layouts.app')

@section('title', 'Abschnitte bearbeiten – ' . $meet->name)

@section('content')
    <div class="max-w-2xl">
        <div class="mb-6">
            <div class="flex items-center gap-2">
                <flux:button href="{{ route('meets.show', $meet) }}" variant="primary" icon="arrow-left" size="sm"
                             title="Zurück" aria-label="Zurück"/>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Abschnitte bearbeiten</h1>
            </div>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">
                {{ $meet->name }} ·
                {{ $meet->start_date->format('d.m.Y') }}@if($meet->end_date && ! $meet->end_date->isSameDay($meet->start_date)) – {{ $meet->end_date->format('d.m.Y') }}@endif
            </p>
        </div>

        @if($numbers->isEmpty())
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 text-sm text-zinc-500 dark:text-zinc-400">
                Diese Veranstaltung hat noch keine Disziplinen — Abschnitte ergeben sich aus der Session-Nummer der
                Disziplinen.
            </div>
        @else
            <form method="POST" action="{{ route('meets.sessions.update', $meet) }}">
                @csrf
                @method('PUT')

                <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-5">
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        Datum und Startzeit sind optional. Ohne Datum zeigt die Meldeliste nach Bewerben nur
                        "Abschnitt N" (bei eintägigen Veranstaltungen das Veranstaltungsdatum).
                    </p>

                    @foreach($numbers as $number)
                        @php
                            /** @var MeetSession|null $session */
                            $session = $sessions->get($number);
                            $dateValue = old("sessions.$number.date", $session?->date?->format('Y-m-d'));
                            $timeValue = old("sessions.$number.daytime", $session?->daytime_short);
                        @endphp
                        <fieldset class="grid grid-cols-1 sm:grid-cols-[8rem_1fr_1fr] gap-4 items-start">
                            <legend class="sr-only">Abschnitt {{ $number }}</legend>
                            <div class="pt-2 font-medium text-zinc-900 dark:text-zinc-100" aria-hidden="true">
                                Abschnitt {{ $number }}
                            </div>
                            <flux:field>
                                <flux:label>Datum</flux:label>
                                {{-- Flux-Picker statt nativer date/time-Felder: Die nativen Felder zeigen das Format der
                                     Browser-Sprache (z. B. mm/dd/yyyy und AM/PM); so bleibt es europäisch (TT.MM.JJJJ, 24 h). --}}
                                <flux:date-picker type="input" locale="de-AT" selectable-header clearable
                                                  name="sessions[{{ $number }}][date]" value="{{ $dateValue }}"
                                                  min="{{ $meet->start_date->format('Y-m-d') }}"
                                                  max="{{ ($meet->end_date ?? $meet->start_date)->format('Y-m-d') }}"/>
                                <flux:error name="sessions.{{ $number }}.date"/>
                            </flux:field>
                            <flux:field>
                                <flux:label>Startzeit</flux:label>
                                <flux:time-picker type="input" time-format="24-hour" locale="de-AT" clearable
                                                  name="sessions[{{ $number }}][daytime]" value="{{ $timeValue }}"/>
                                <flux:error name="sessions.{{ $number }}.daytime"/>
                            </flux:field>
                        </fieldset>
                    @endforeach

                    <div class="flex gap-3 pt-2">
                        <flux:button type="submit" variant="primary">Speichern</flux:button>
                        <flux:button href="{{ route('meets.show', $meet) }}" variant="ghost">Abbrechen</flux:button>
                    </div>
                </div>
            </form>
        @endif
    </div>
@endsection
