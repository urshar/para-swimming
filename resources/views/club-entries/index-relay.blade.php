@php use App\Support\TimeParser; @endphp

@extends('layouts.app')

@section('title', 'Staffelmeldungen – ' . $meet->name)

@section('content')
    @php $clubParam = auth()->user()->is_admin && request('club_id') ? ['club_id' => request()->integer('club_id')] : []; @endphp

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Staffelmeldungen</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                {{ $meet->name }} · {{ $club->display_name }}
            </p>
        </div>
        <div class="flex items-center gap-2">
            <flux:button href="{{ route('club-entries.index', array_merge(['meet' => $meet], $clubParam)) }}"
                         variant="ghost" size="sm">
                Einzelmeldungen
            </flux:button>
            @if($canManage)
                <flux:button href="{{ route('club-entries.relay.create', array_merge(['meet' => $meet], $clubParam)) }}"
                             variant="primary" icon="plus">
                    Neue Staffelmeldung
                </flux:button>
            @endif
        </div>
    </div>

    @if(session('success'))
        <div class="mb-4 p-3 bg-green-50 dark:bg-green-950/20 border border-green-200 dark:border-green-800
                    rounded-xl text-sm text-green-700 dark:text-green-400">
            {{ session('success') }}
        </div>
    @endif

    @if($relayEntries->isEmpty())
        <div class="text-center py-16 text-zinc-400 dark:text-zinc-500">
            <svg class="w-10 h-10 mx-auto mb-3 opacity-40" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                      d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0"/>
            </svg>
            <p class="font-medium">Noch keine Staffelmeldungen</p>
            @if($canManage)
                <p class="text-sm mt-1">
                    <a href="{{ route('club-entries.relay.create', array_merge(['meet' => $meet], $clubParam)) }}"
                       class="text-blue-600 dark:text-blue-400 hover:underline">
                        Erste Staffelmeldung anlegen
                    </a>
                </p>
            @endif
        </div>
    @else
        <div class="space-y-4">
            @foreach($relayEntries as $relay)
                @php
                    $required = $relay->swimEvent->relay_count ?? 4;
                    $memberCount = $relay->members->count();
                    $isComplete  = $memberCount === $required;
                @endphp

                <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-5">

                    {{-- Header --}}
                    <div class="flex items-start justify-between gap-4 mb-4">
                        <div class="flex items-center gap-3 flex-wrap">
                            {{-- Staffelklasse --}}
                            @if($relay->relay_class)
                                <flux:badge color="blue"
                                            class="font-mono text-sm">{{ $relay->relay_class }}</flux:badge>
                            @else
                                <flux:badge color="amber" class="text-sm">Klasse unbekannt</flux:badge>
                            @endif

                            {{-- Event-Name --}}
                            <span class="font-medium text-zinc-900 dark:text-zinc-100 text-sm">
                                {{ $relay->swimEvent->event_number ? 'Nr. '.$relay->swimEvent->event_number.' – ' : '' }}
                                {{ $relay->swimEvent->relay_count }}×{{ $relay->swimEvent->distance }}m
                                {{ $relay->swimEvent->strokeType?->name_de }}
                                @php
                                    $gLabel = match($relay->swimEvent->gender) {
                                        'M'       => 'Männer',
                                        'F'       => 'Frauen',
                                        'X','MX'  => 'Mixed',
                                        default   => 'Offen',
                                    };
                                @endphp
                                ({{ $gLabel }})
                            </span>

                            {{-- Vollständigkeit --}}
                            @if($isComplete)
                                <flux:badge color="green" size="sm">Vollständig</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">{{ $memberCount }}/{{ $required }}Athleten
                                </flux:badge>
                            @endif
                        </div>

                        {{-- Aktionen --}}
                        @if($canManage)
                            <div class="flex items-center gap-1 shrink-0">
                                <flux:button
                                    href="{{ route('club-entries.relay.edit', array_merge(['meet' => $meet, 'relayEntry' => $relay], $clubParam)) }}"
                                    size="sm" variant="ghost" icon="pencil"/>
                                <form method="POST"
                                      action="{{ route('club-entries.relay.destroy', array_merge(['meet' => $meet, 'relayEntry' => $relay], $clubParam)) }}"
                                      x-data="{ submit(f){ if(confirm('Staffelmeldung wirklich löschen?')) f.submit() } }">
                                    @csrf @method('DELETE')
                                    <flux:button type="submit" size="sm" variant="ghost" icon="trash"
                                                 class="text-red-500"/>
                                </form>
                            </div>
                        @endif
                    </div>

                    {{-- Members --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        @forelse($relay->members as $member)
                            <div class="flex items-center gap-3 p-2 rounded-lg
                                        bg-zinc-50 dark:bg-zinc-900/40 text-sm">
                                <span class="w-5 h-5 rounded-full bg-blue-100 dark:bg-blue-900/40 text-blue-700
                                             dark:text-blue-300 text-xs font-bold flex items-center justify-center shrink-0">
                                    {{ $member->position }}
                                </span>
                                <div class="flex-1 min-w-0">
                                    <span class="font-medium text-zinc-900 dark:text-zinc-100 truncate block">
                                        {{ $member->athlete?->last_name }}, {{ $member->athlete?->first_name }}
                                    </span>
                                    <span class="text-xs text-zinc-400">
                                        {{ $member->sport_class ?? '–' }}
                                        @if($member->athlete?->birth_date)
                                            · *{{ $member->athlete->birth_date->format('Y') }}
                                        @endif
                                    </span>
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-zinc-400 col-span-2 italic">Noch keine Athleten eingetragen.</p>
                        @endforelse

                        {{-- Leere Plätze --}}
                        @for($i = $memberCount + 1; $i <= $required; $i++)
                            <div class="flex items-center gap-3 p-2 rounded-lg border border-dashed
                                        border-zinc-200 dark:border-zinc-700 text-sm">
                                <span class="w-5 h-5 rounded-full bg-zinc-100 dark:bg-zinc-800 text-zinc-400
                                             text-xs font-bold flex items-center justify-center shrink-0">
                                    {{ $i }}
                                </span>
                                <span class="text-zinc-400 italic">freier Platz</span>
                            </div>
                        @endfor
                    </div>

                    {{-- Meldezeit --}}
                    @if($relay->entry_time || $relay->entry_time_code)
                        <div
                            class="mt-3 pt-3 border-t border-zinc-100 dark:border-zinc-700/50 flex items-center gap-2 text-sm">
                            <span class="text-zinc-400">Meldezeit:</span>
                            <span class="font-mono font-semibold text-zinc-900 dark:text-zinc-100">
                                {{ $relay->entry_time
                                    ? TimeParser::display($relay->entry_time)
                                    : ($relay->entry_time_code ?? '–') }}
                            </span>
                            @if($relay->entry_course)
                                <flux:badge color="zinc" size="sm">{{ $relay->entry_course }}</flux:badge>
                            @endif
                        </div>
                    @endif

                </div>
            @endforeach
        </div>
    @endif

@endsection
