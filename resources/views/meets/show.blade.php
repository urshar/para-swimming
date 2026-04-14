@extends('layouts.app')

@section('title', $meet->name)

@section('content')
    {{-- Header --}}
    <div class="flex items-start justify-between mb-6">
        <div class="flex items-center gap-3">
            <flux:button href="{{ route('meets.index') }}" variant="ghost" icon="arrow-left" size="sm"/>
            <div>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $meet->name }}</h1>
                <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                    {{ $meet->date_range }} · {{ $meet->city }}, {{ $meet->nation?->code }} · {{ $meet->course }}
                </p>
            </div>
        </div>
        <div class="flex items-center gap-2">

            {{-- Vereinsmeldungen — für Club-User und Admins, nur wenn Meet offen --}}
            @if(auth()->check() && (auth()->user()->is_admin || (auth()->user()->club_id && $meet->is_open)))
                <flux:button href="{{ route('club-entries.index', $meet) }}" variant="ghost"
                             icon="pencil-square" size="sm">
                    Meldungen
                </flux:button>
            @endif

            <flux:button href="{{ route('lenex.export') }}?meet_id={{ $meet->id }}" variant="ghost"
                         icon="arrow-down-tray" size="sm">
                LENEX Export
            </flux:button>
            <form method="POST" action="{{ route('records.check', $meet) }}"
                  x-data="{ submit() { if (confirm('Alle Ergebnisse auf Rekorde prüfen?')) this.$el.submit() } }"
                  @submit.prevent="submit()">
                @csrf
                <flux:button type="submit" variant="ghost" icon="star" size="sm">
                    Rekorde prüfen
                </flux:button>
            </form>
            <flux:button href="{{ route('meets.edit', $meet) }}" variant="ghost" icon="pencil" size="sm">
                Bearbeiten
            </flux:button>
        </div>
    </div>

    {{-- Flash-Messages --}}
    @if(session('success'))
        <div
            class="mb-4 p-4 bg-green-50 dark:bg-green-950/20 border border-green-200 dark:border-green-800 rounded-xl text-sm text-green-700 dark:text-green-400">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->has('check'))
        <div
            class="mb-4 p-4 bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-800 rounded-xl text-sm text-red-700 dark:text-red-400">
            {{ $errors->first('check') }}
        </div>
    @endif

    {{-- Rekord-Check Ergebnis --}}
    @if(session('record_check_result'))
        @include('records.check-result', ['checkResult' => session('record_check_result')])
    @endif

    {{-- Stats --}}
    <div class="grid grid-cols-3 gap-4 mb-6">
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 text-center">
            <div class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $meet->swim_events_count }}</div>
            <div class="text-sm text-zinc-500 dark:text-zinc-400">Disziplinen</div>
        </div>
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 text-center">
            <div class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $meet->entries_count }}</div>
            <div class="text-sm text-zinc-500 dark:text-zinc-400">Meldungen</div>
        </div>
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 text-center">
            <div class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $meet->results_count }}</div>
            <div class="text-sm text-zinc-500 dark:text-zinc-400">Ergebnisse</div>
        </div>
    </div>

    {{-- Events --}}
    <div class="flex items-center justify-between mb-3">
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">Disziplinen</h2>
        <flux:button href="{{ route('meets.events.create', $meet) }}" variant="ghost" icon="plus" size="sm">
            Disziplin hinzufügen
        </flux:button>
    </div>

    @if($swimEvents->isEmpty())
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-8 text-center">
            <p class="text-zinc-400 text-sm mb-3">Noch keine Disziplinen angelegt.</p>
            <flux:button href="{{ route('meets.events.create', $meet) }}" variant="primary" icon="plus" size="sm">
                Erste Disziplin anlegen
            </flux:button>
        </div>
    @else
        @foreach($swimEvents->groupBy('session_number') as $session => $events)
            <div class="mb-4">
                <div class="text-xs font-semibold text-zinc-400 dark:text-zinc-500 uppercase tracking-wider mb-2 px-1">
                    Session {{ $session }}
                </div>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Nr.</flux:table.column>
                        <flux:table.column>Disziplin</flux:table.column>
                        <flux:table.column>Geschlecht</flux:table.column>
                        <flux:table.column>Runde</flux:table.column>
                        <flux:table.column>Klassen</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach($events as $event)
                            <flux:table.row>
                                <flux:table.cell class="text-zinc-400 text-sm">
                                    {{ $event->event_number ?? '–' }}
                                </flux:table.cell>
                                <flux:table.cell class="font-medium">
                                    {{ $event->display_name }}
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm"
                                                color="{{ match($event->gender) { 'M' => 'blue', 'F' => 'pink', default => 'zinc' } }}">
                                        {{ match($event->gender) { 'M' => 'Herren', 'F' => 'Damen', 'X' => 'Mixed', default => 'Offen' } }}
                                    </flux:badge>
                                </flux:table.cell>
                                <flux:table.cell class="text-zinc-500 text-sm">
                                    {{ $event->round !== 'TIM' ? $event->round : '–' }}
                                </flux:table.cell>
                                <flux:table.cell class="text-zinc-500 text-sm">
                                    {{ $event->sport_classes ?? '–' }}
                                </flux:table.cell>
                                <flux:table.cell>
                                    <div class="flex items-center gap-1 justify-end">
                                        <flux:button href="{{ route('events.edit', $event) }}" size="sm"
                                                     variant="ghost" icon="pencil"/>
                                        <form method="POST" action="{{ route('events.destroy', $event) }}"
                                              x-data="{ submit() { if (confirm('Disziplin löschen?')) this.$el.submit() } }"
                                              @submit.prevent="submit()">
                                            @csrf @method('DELETE')
                                            <flux:button type="submit" size="sm" variant="ghost" icon="trash"
                                                         class="text-red-500"/>
                                        </form>
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        @endforeach
    @endif

    {{-- Clubs --}}
    @if($meet->clubs->isNotEmpty())
        <div class="mt-6">
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100 mb-3">Teilnehmende Vereine</h2>
            <div class="flex flex-wrap gap-2">
                @foreach($meet->clubs as $club)
                    <a href="{{ route('clubs.show', $club) }}">
                        <flux:badge color="zinc" size="sm">{{ $club->display_name }}</flux:badge>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

@endsection
