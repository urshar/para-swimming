@extends('layouts.app')

@section('title', $mode === 'relay' ? 'Staffelmeldungen – Wettkampf wählen' : 'Einzelmeldungen – Wettkampf wählen')

@section('content')
    <div class="max-w-xl">

        <div class="mb-6">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                {{ $mode === 'relay' ? 'Staffelmeldungen' : 'Einzelmeldungen' }}
            </h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">
                Wähle einen Wettkampf um die Meldungen zu verwalten.
            </p>
        </div>

        @auth
            @if(auth()->user()->is_admin && $clubs)
                {{-- Admin: Vereins-Auswahl --}}
                <div class="mb-6 p-4 rounded-xl border border-blue-200 dark:border-blue-800
                        bg-blue-50 dark:bg-blue-950/20">
                    <p class="text-xs font-semibold text-blue-600 dark:text-blue-400 uppercase tracking-wide mb-2">
                        Verein (Admin)
                    </p>
                    <select name="club_id"
                            onchange="window.location.href = '{{ request()->url() }}?club_id=' + this.value"
                            class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600
                               bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100
                               px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none">
                        <option value="">Verein wählen…</option>
                        @foreach($clubs as $club)
                            <option value="{{ $club->id }}"
                                {{ request()->integer('club_id') === $club->id ? 'selected' : '' }}>
                                {{ $club->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif
        @endauth

        @if($meets->isEmpty())
            <div class="text-center py-16 text-zinc-400 dark:text-zinc-500">
                <svg class="w-10 h-10 mx-auto mb-3 opacity-40" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                          d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
                <p class="font-medium">Keine offenen Wettkämpfe</p>
                <p class="text-sm mt-1">Aktuell ist kein Wettkampf für Meldungen geöffnet.</p>
            </div>
        @else
            @php
                $clubId = request()->integer('club_id');
                $isAdmin = auth()->user()->is_admin;
            @endphp

            <div class="space-y-3">
                @foreach($meets as $meet)
                    @php
                        $params = array_merge(['meet' => $meet], $isAdmin && $clubId ? ['club_id' => $clubId] : []);
                        $route  = $mode === 'relay'
                            ? route('club-entries.relay.index', $params)
                            : route('club-entries.index', $params);
                        $disabled = $isAdmin && ! $clubId;
                    @endphp

                    <a href="{{ $disabled ? '#' : $route }}"
                       @class([
                           'block p-4 rounded-xl border transition-all',
                           'border-dashed border-zinc-300 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/40 opacity-50 cursor-not-allowed pointer-events-none' => $disabled,
                           'border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 hover:border-blue-400 dark:hover:border-blue-500 hover:shadow-sm group' => ! $disabled,
                       ])
                       @if($disabled) tabindex="-1" aria-disabled="true" @endif>
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <p @class([
                                    'font-semibold text-zinc-900 dark:text-zinc-100 transition-colors',
                                    'group-hover:text-blue-600 dark:group-hover:text-blue-400' => ! $disabled,
                                ])>
                                    {{ $meet->name }}
                                </p>
                                <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                                    {{ $meet->city ?? '–' }}
                                    @if($meet->start_date)
                                        · {{ $meet->start_date->format('d.m.Y') }}
                                    @endif
                                    @if($meet->course)
                                        ·
                                        <flux:badge color="zinc" size="sm">{{ $meet->course }}</flux:badge>
                                    @endif
                                </p>
                                @if($meet->entries_deadline)
                                    <p @class([
                                        'text-xs mt-1',
                                        'text-red-500 dark:text-red-400' => $meet->isDeadlinePassed(),
                                        'text-zinc-400 dark:text-zinc-500' => ! $meet->isDeadlinePassed(),
                                    ])>
                                        Meldeschluss: {{ $meet->entries_deadline->format('d.m.Y') }}
                                        {{ $meet->isDeadlinePassed() ? '(abgelaufen)' : '' }}
                                    </p>
                                @endif
                            </div>
                            @if(! $disabled)
                                <svg class="w-5 h-5 text-zinc-300 dark:text-zinc-600 group-hover:text-blue-400
                                            transition-colors shrink-0"
                                     fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                                </svg>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>

            @if($isAdmin && ! $clubId)
                <p class="text-xs text-amber-600 dark:text-amber-400 mt-3 text-center">
                    Bitte zuerst einen Verein auswählen.
                </p>
            @endif
        @endif

    </div>
@endsection
