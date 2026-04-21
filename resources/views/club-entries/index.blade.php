@php
    use Carbon\Carbon;
@endphp

@extends('layouts.app')

@section('title', 'Meldungen – ' . $meet->name)

@section('content')
    @php $clubParam = auth()->user()->is_admin && request('club_id') ? ['club_id' => request()->integer('club_id')] : []; @endphp
    <div class="max-w-5xl">

        {{-- Header --}}
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-3">
                <flux:button href="{{ route('meets.show', $meet) }}" variant="ghost" icon="arrow-left" size="sm"/>
                <flux:button href="{{ route('club-entries.relay.index', array_merge(['meet' => $meet], $clubParam)) }}"
                             variant="ghost" size="sm">Staffelmeldungen
                </flux:button>
                <div>
                    <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Einzelmeldungen</h1>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                        {{ $meet->name }} · {{ $meet->city }} ·
                        {{ $meet->start_date->format('d.m.Y') }}
                        · {{ $club->display_name }}
                    </p>
                </div>
            </div>

            @if($canManage)
                <flux:button href="{{ route('club-entries.create', array_merge(['meet' => $meet], $clubParam)) }}"
                             variant="primary" icon="plus">
                    Neue Meldung
                </flux:button>
            @endif
        </div>

        {{-- Flash --}}
        @if(session('success'))
            <div class="mb-4 p-3 bg-green-50 dark:bg-green-950/20 border border-green-200 dark:border-green-800
                        rounded-xl text-sm text-green-700 dark:text-green-400">
                {{ session('success') }}
            </div>
        @endif

        {{-- Meldeschluss-Hinweis --}}
        @if(!$canManage && $meet->entries_deadline)
            <div class="mb-4 p-3 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-700
                        rounded-xl text-sm text-amber-700 dark:text-amber-400">
                Meldeschluss war am {{ Carbon::parse($meet->entries_deadline)->format('d.m.Y') }}.
                Änderungen sind nicht mehr möglich.
            </div>
        @endif

        {{-- Tabelle --}}
        @if($entries->isEmpty())
            <div
                class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-10 text-center">
                <p class="text-zinc-400 dark:text-zinc-500 text-sm">Noch keine Meldungen vorhanden.</p>
                @if($canManage)
                    <flux:button href="{{ route('club-entries.create', array_merge(['meet' => $meet], $clubParam)) }}"
                                 variant="ghost" icon="plus"
                                 class="mt-3">
                        Erste Meldung anlegen
                    </flux:button>
                @endif
            </div>
        @else
            <div
                class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                    <tr class="border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900/40">
                        <th class="text-left px-4 py-3 font-medium text-zinc-600 dark:text-zinc-400">Nr.</th>
                        <th class="text-left px-4 py-3 font-medium text-zinc-600 dark:text-zinc-400">Event</th>
                        <th class="text-left px-4 py-3 font-medium text-zinc-600 dark:text-zinc-400">Athlet</th>
                        <th class="text-left px-4 py-3 font-medium text-zinc-600 dark:text-zinc-400">Klasse</th>
                        <th class="text-right px-4 py-3 font-medium text-zinc-600 dark:text-zinc-400">Meldezeit</th>
                        <th class="text-left px-4 py-3 font-medium text-zinc-600 dark:text-zinc-400">Kurs</th>
                        @if($canManage)
                            <th class="px-4 py-3"></th>
                        @endif
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700/50">
                    @foreach($entries as $entry)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-700/30 transition-colors">
                            <td class="px-4 py-3 text-zinc-400 dark:text-zinc-500 tabular-nums">
                                {{ $entry->swimEvent->event_number ?? '–' }}
                            </td>
                            <td class="px-4 py-3 text-zinc-900 dark:text-zinc-100">
                                {{ $entry->swimEvent->display_name }}
                                <span class="text-xs text-zinc-400 dark:text-zinc-500 ml-1">
                                        {{ $entry->swimEvent->gender === 'M' ? '♂' : ($entry->swimEvent->gender === 'F' ? '♀' : '⚥') }}
                                    </span>
                            </td>
                            <td class="px-4 py-3 text-zinc-900 dark:text-zinc-100">
                                {{ $entry->athlete->last_name }}, {{ $entry->athlete->first_name }}
                            </td>
                            <td class="px-4 py-3">
                                    <span class="inline-block px-2 py-0.5 text-xs font-mono rounded
                                                 bg-zinc-100 dark:bg-zinc-700 text-zinc-600 dark:text-zinc-300">
                                        {{ $entry->sport_class ?? '–' }}
                                    </span>
                            </td>
                            <td class="px-4 py-3 text-right font-mono text-zinc-900 dark:text-zinc-100">
                                {{ $entry->formatted_entry_time }}
                            </td>
                            <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400 text-xs">
                                {{ $entry->entry_course ?? $meet->course }}
                            </td>
                            @if($canManage)
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2 justify-end">
                                        <flux:button
                                            href="{{ route('club-entries.edit', array_merge(['meet' => $meet, 'entry' => $entry], $clubParam)) }}"
                                            variant="ghost"
                                            icon="pencil"
                                            size="sm"/>
                                        <form method="POST"
                                              action="{{ route('club-entries.destroy', array_merge(['meet' => $meet, 'entry' => $entry], $clubParam)) }}"
                                              x-data="{ submit(f){ if(confirm('Meldung wirklich löschen?')) f.submit() } }"
                                              @submit.prevent="submit($el)">
                                            @csrf
                                            @method('DELETE')
                                            <flux:button type="submit" variant="ghost" icon="trash" size="sm"/>
                                        </form>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-zinc-400 mt-3 text-right">{{ $entries->count() }} Meldung(en)</p>
        @endif

    </div>
@endsection
