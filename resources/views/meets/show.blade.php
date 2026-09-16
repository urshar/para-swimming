@extends('layouts.app')

@section('title', $meet->name)

@section('content')
    {{-- Header --}}
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $meet->name }}</h1>
        <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
            {{ $meet->date_range }} · {{ $meet->city }}, {{ $meet->nation?->code }} · {{ $meet->course }}
            @if($meet->cup)
                · <flux:badge color="amber" size="sm">{{ $meet->cup->name }}</flux:badge>
            @endif
            @if($meet->qualifyingTimeList)
                · <flux:badge color="blue" size="sm">Richtzeiten {{ $meet->qualifyingTimeList->year }}</flux:badge>
            @endif
        </p>

        <div class="flex items-center flex-wrap gap-2 mt-4">
            <flux:button href="{{ route('meets.index') }}" variant="filled" icon="arrow-left" size="sm">
                Zurück
            </flux:button>

            <div class="ml-auto flex items-center flex-wrap gap-2">
                {{-- Vereinsmeldungen — für Club-User und Admins, nur wenn Meet offen --}}
                @if(auth()->check() && (auth()->user()->is_admin || (auth()->user()->club_id && $meet->is_open)))
                    <flux:button href="{{ route('club-entries.index', $meet) }}" variant="filled"
                                 icon="pencil-square" size="sm">
                        Meldungen
                    </flux:button>
                @endif

                @if(auth()->user()?->is_admin)
                    <flux:button href="{{ route('meets.results.create', $meet) }}" variant="filled"
                                 icon="plus" size="sm">
                        Ergebnis erfassen
                    </flux:button>
                @endif

                @if($meet->cup_id)
                    <flux:button href="{{ route('meets.cup-daily-ranking.show', $meet) }}" variant="filled"
                                 icon="trophy" size="sm">
                        Cup-Tageswertung
                    </flux:button>
                @endif

                @if($meet->qualifying_time_list_id)
                    <flux:button href="{{ route('qualifying-time-lists.show', $meet->qualifying_time_list_id) }}"
                                 variant="filled" icon="flag" size="sm">
                        Richtzeiten anzeigen
                    </flux:button>
                @endif

                @if(auth()->user()?->is_admin)
                    <flux:button href="{{ route('admin.meets.documents.index', $meet) }}" variant="filled"
                                 icon="document-text" size="sm">
                        Dokumente ({{ $meet->documents_count }})
                    </flux:button>
                @endif

                <flux:button href="{{ route('lenex.export') }}?meet_id={{ $meet->id }}" variant="filled"
                             icon="arrow-down-tray" size="sm">
                    LENEX Export
                </flux:button>
                <form method="POST" action="{{ route('records.check', $meet) }}"
                      x-data="{ submit() { if (confirm('Alle Ergebnisse auf Rekorde prüfen?')) this.$el.submit() } }"
                      @submit.prevent="submit()">
                    @csrf
                    <flux:button type="submit" variant="filled" icon="star" size="sm">
                        Rekorde prüfen
                    </flux:button>
                </form>
                @if($meet->hasWpsPointsEnabled() && auth()->user()?->can('manageEntries', $meet))
                    <form method="POST" action="{{ route('meets.wps-points.recalculate', $meet) }}"
                          x-data="{ submit() { if (confirm('WPS-Punkte für alle Ergebnisse neu berechnen?')) this.$el.submit() } }"
                          @submit.prevent="submit()">
                        @csrf
                        <flux:button type="submit" variant="filled" icon="calculator" size="sm">
                            WPS-Punkte berechnen
                        </flux:button>
                    </form>
                @endif
                <flux:button href="{{ route('meets.edit', $meet) }}" variant="filled" icon="pencil" size="sm">
                    Bearbeiten
                </flux:button>
            </div>
        </div>
    </div>

    {{-- Flash-Messages --}}
    @if(session('success'))
        <div
            class="mb-4 p-4 bg-green-50 dark:bg-green-950/20 border border-green-200 dark:border-green-800 rounded-xl text-sm text-green-700 dark:text-green-400">
            {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div
            class="mb-4 p-4 bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-800 rounded-xl text-sm text-red-700 dark:text-red-400">
            {{ session('error') }}
        </div>
    @endif

    @if($errors->has('check'))
        <div
            class="mb-4 p-4 bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-800 rounded-xl text-sm text-red-700 dark:text-red-400">
            {{ $errors->first('check') }}
        </div>
    @endif

    {{-- Ohne diesen Block scheitert die WPS-Berechnung lautlos: der Controller meldet
         über withErrors('wps') zurück, und die Seite zeigte davon nichts an.
         Die Meldung nennt jeweils auch den Weg zur Behebung — eine Fehlermeldung ohne
         Handlungsmöglichkeit zwingt sonst zur Suche im Menü. --}}
    @if($errors->has('wps'))
        <div
            class="mb-4 p-4 bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-800 rounded-xl text-sm text-red-700 dark:text-red-400">
            <p>{{ $errors->first('wps') }}</p>

            <p class="mt-2 flex flex-wrap gap-x-4 gap-y-1">
                <a href="{{ route('meets.edit', $meet) }}" class="font-medium underline">
                    Punkteberechnung dieses Wettkampfs bearbeiten
                </a>
                @if(auth()->user()?->is_admin)
                    <a href="{{ route('wps.versions.index') }}" class="font-medium underline">
                        WPS-Versionen verwalten
                    </a>
                    <a href="{{ route('wps.import') }}" class="font-medium underline">
                        Version importieren
                    </a>
                @endif
            </p>
        </div>
    @endif

    {{-- Rekord-Check Ergebnis --}}
    @if(session('record_check_result'))
        @include('records.check-result', ['checkResult' => session('record_check_result')])
    @endif

    {{-- Stats --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 text-center">
            <div class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $meet->swim_events_count }}</div>
            <div class="text-sm text-zinc-500 dark:text-zinc-400">Disziplinen</div>
        </div>
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 text-center">
            <div class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $meet->entries_count }}</div>
            <div class="text-sm text-zinc-500 dark:text-zinc-400">Einzelmeldungen</div>
        </div>
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 text-center">
            <div class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $meet->relay_entries_count }}</div>
            <div class="text-sm text-zinc-500 dark:text-zinc-400">Staffelmeldungen</div>
        </div>
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 text-center">
            <div class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $meet->results_count }}</div>
            <div class="text-sm text-zinc-500 dark:text-zinc-400">Ergebnisse</div>
        </div>
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 text-center">
            <div class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $participantsCount }}</div>
            <div class="text-sm text-zinc-500 dark:text-zinc-400">Teilnehmer</div>
        </div>
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 text-center">
            <div class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $participatingClubsCount }}</div>
            <div class="text-sm text-zinc-500 dark:text-zinc-400">Clubs</div>
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
                                                     variant="ghost" icon="pencil" class="text-amber-500!"/>
                                        <form method="POST" action="{{ route('events.destroy', $event) }}"
                                              x-data="{ submit() { if (confirm('Disziplin löschen?')) this.$el.submit() } }"
                                              @submit.prevent="submit()">
                                            @csrf @method('DELETE')
                                            <flux:button type="submit" size="sm" variant="ghost" icon="trash"
                                                         class="text-red-500!"/>
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
