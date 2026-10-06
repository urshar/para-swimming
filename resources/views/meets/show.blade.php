@php
    use App\Models\MeetSession;
    use App\Support\ListUrl;
@endphp

@extends('layouts.app')

@section('title', $meet->name)

@section('content')
    {{-- Header --}}
    <div class="mb-6">
        <div class="flex items-center gap-2">
            <flux:button href="{{ ListUrl::to('meets') }}" variant="primary" icon="arrow-left" size="sm"
                         title="Zurück" aria-label="Zurück"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $meet->name }}</h1>
        </div>
        <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
            {{ $meet->date_range }} · {{ $meet->city }}, {{ $meet->nation?->code }} · {{ $meet->course }}
            @if($meet->cup)
                · <flux:badge color="amber" size="sm">{{ $meet->cup->name }}</flux:badge>
            @endif
            @if($meet->qualifyingTimeList)
                · <flux:badge color="blue" size="sm">Richtzeiten {{ $meet->qualifyingTimeList->year }}</flux:badge>
            @endif
        </p>

        <div class="flex items-center flex-wrap justify-end gap-2 mt-4">
            {{-- Vereinsmeldungen — für Club-User und Admins, nur wenn Meet offen --}}
            @if(auth()->check() && (auth()->user()->is_admin || (auth()->user()->club_id && $meet->is_open)))
                <flux:button href="{{ route('club-entries.index', $meet) }}" variant="filled"
                             icon="pencil-square" size="sm" class="text-blue-500!">
                    Meldungen
                </flux:button>
            @endif

            {{-- Meet-weite Gesamtübersicht aller Meldungen (Einzel + Staffel, alle Vereine) — nur Admin --}}
            @if(auth()->user()?->is_admin)
                <flux:button href="{{ route('meets.entries-overview', $meet) }}" variant="filled"
                             icon="list-bullet" size="sm" class="text-blue-500!">
                    Alle Meldungen
                </flux:button>
            @endif

            {{-- Sammelansicht aller Ergebnisse (Erfassen, Bearbeiten, Löschen) — nur Admin --}}
            @if(auth()->user()?->is_admin)
                <flux:button href="{{ route('meets.results-overview', $meet) }}" variant="filled"
                             icon="list-bullet" size="sm" class="text-blue-500!">
                    Ergebnisse
                </flux:button>
            @endif

            @if($meet->cup_id)
                <flux:button href="{{ route('meets.cup-daily-ranking.show', $meet) }}" variant="filled"
                             icon="trophy" size="sm" class="text-blue-500!">
                    Cup-Tageswertung
                </flux:button>
            @endif

            @if($meet->qualifying_time_list_id)
                <flux:button href="{{ route('qualifying-time-lists.show', $meet->qualifying_time_list_id) }}"
                             variant="filled" icon="flag" size="sm" class="text-blue-500!">
                    Richtzeiten anzeigen
                </flux:button>
            @endif

            @if(auth()->user()?->is_admin)
                <flux:button href="{{ route('admin.meets.documents.index', $meet) }}" variant="filled"
                             icon="document-text" size="sm" class="text-blue-500!">
                    Dokumente ({{ $meet->documents_count }})
                </flux:button>
            @endif

            <flux:button href="{{ route('lenex.export') }}?meet_id={{ $meet->id }}" variant="filled"
                         icon="arrow-down-tray" size="sm" class="text-blue-500!">
                LENEX Export
            </flux:button>
            @if(auth()->user()?->is_admin)
            <form method="POST" action="{{ route('records.check', $meet) }}"
                  x-data="{ submit() { if (confirm('Alle Ergebnisse auf Rekorde prüfen?')) this.$el.submit() } }"
                  @submit.prevent="submit()">
                @csrf
                <flux:button type="submit" variant="filled" icon="star" size="sm" class="text-blue-500!">
                    Rekorde prüfen
                </flux:button>
            </form>
            @endif
            @if($meet->hasWpsPointsEnabled() && auth()->user()?->is_admin)
                <form method="POST" action="{{ route('meets.wps-points.recalculate', $meet) }}"
                      x-data="{ submit() { if (confirm('WPS-Punkte für alle Ergebnisse neu berechnen?')) this.$el.submit() } }"
                      @submit.prevent="submit()">
                    @csrf
                    <flux:button type="submit" variant="filled" icon="calculator" size="sm" class="text-blue-500!">
                        WPS-Punkte berechnen
                    </flux:button>
                </form>
            @endif
            @if(auth()->user()?->is_admin)
            <flux:button href="{{ route('meets.edit', $meet) }}" variant="filled" icon="pencil" size="sm"
                         class="text-amber-500!">
                Bearbeiten
            </flux:button>
            @endif
        </div>
    </div>

    {{-- Ohne diesen Block scheitert die WPS-Berechnung lautlos: der Controller meldet
         über withErrors('wps') zurück, und die Seite zeigte davon nichts an.
         Die Meldung nennt jeweils auch den Weg zur Behebung — eine Fehlermeldung ohne
         Handlungsmöglichkeit zwingt sonst zur Suche im Menü. Deshalb bewusst nicht über
         das zentrale x-flash (session('error')), das nur den Text zeigt. --}}
    @if($errors->has('wps'))
        <div
            class="mb-4 p-4 bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-800 rounded-xl text-sm text-red-700 dark:text-red-400">
            <p>{{ $errors->first('wps') }}</p>

            <p class="mt-2 flex flex-wrap gap-x-4 gap-y-1">
                @if(auth()->user()?->is_admin)
                <a href="{{ route('meets.edit', $meet) }}" class="font-medium underline">
                    Punkteberechnung dieses Wettkampfs bearbeiten
                </a>
                @endif
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

    @if(auth()->user()?->is_admin && $meet->isDeadlinePassed())
        @include('meets._entries-reopen')
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
        @if(auth()->user()?->is_admin)
            <a href="{{ route('meets.results-overview', $meet) }}"
               class="block bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 text-center
                      hover:border-blue-400 dark:hover:border-blue-500 transition-colors">
                <div class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $meet->results_count }}</div>
                <div class="text-sm text-blue-600 dark:text-blue-400">Ergebnisse</div>
            </a>
        @else
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 text-center">
                <div class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $meet->results_count }}</div>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Ergebnisse</div>
            </div>
        @endif
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
        <div class="flex items-center gap-2">
            @if($swimEvents->isNotEmpty())
                @if(auth()->user()?->is_admin)
                <flux:button href="{{ route('meets.sessions.edit', $meet) }}" variant="ghost" icon="calendar-days" size="sm">
                    Abschnitte bearbeiten
                </flux:button>
                @endif
                @if(auth()->user()?->is_admin)
                    <flux:button href="{{ route('meets.fees.edit', $meet) }}" variant="ghost" icon="banknotes" size="sm">
                        Meldegelder
                    </flux:button>
                @endif
            @endif
            @if(auth()->user()?->is_admin)
            <flux:button href="{{ route('meets.events.create', $meet) }}" variant="ghost" icon="plus" size="sm">
                Disziplin hinzufügen
            </flux:button>
            @endif
        </div>
    </div>

    @if($swimEvents->isEmpty())
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-8 text-center">
            <p class="text-zinc-400 text-sm mb-3">Noch keine Disziplinen angelegt.</p>
            @if(auth()->user()?->is_admin)
            <flux:button href="{{ route('meets.events.create', $meet) }}" variant="primary" icon="plus" size="sm">
                Erste Disziplin anlegen
            </flux:button>
            @endif
        </div>
    @else
        @foreach($swimEvents->groupBy('session_number') as $session => $events)
            @php
                // Datum/Startzeit aus meet_sessions (gepflegt über "Abschnitte bearbeiten" bzw. LENEX-Import).
                /** @var MeetSession|null $sessionInfo */
                $sessionInfo = $sessions->get($session);
            @endphp
            <div class="mb-4">
                <div class="text-xs font-semibold text-zinc-400 dark:text-zinc-500 uppercase tracking-wider mb-2 px-1">
                    Session {{ $session }}
                    @if($sessionInfo?->date)
                        <span class="normal-case tracking-normal font-normal">
                            · {{ $sessionInfo->date->locale('de')->translatedFormat('l, j. F Y') }}@if($sessionInfo->daytime_short), {{ $sessionInfo->daytime_short }} Uhr @endif
                        </span>
                    @endif
                </div>
                <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden p-4 [--flux-bleed:1rem]">
                {{-- Feste Spaltenbreiten (table-fixed), damit die Tabellen der Abschnitte untereinander gleich ausgerichtet
                     sind; die Disziplin nimmt den Rest. --}}
                <flux:table bleed class="w-full min-w-160">
                    <flux:table.columns>
                        <flux:table.column class="w-14">Nr.</flux:table.column>
                        <flux:table.column>Disziplin</flux:table.column>
                        <flux:table.column class="w-28">Geschlecht</flux:table.column>
                        <flux:table.column class="w-20">Runde</flux:table.column>
                        <flux:table.column class="w-48">Klassen</flux:table.column>
                        <flux:table.column class="w-24"></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach($events as $event)
                            <flux:table.row>
                                <flux:table.cell class="text-zinc-400 text-sm">
                                    {{ $event->event_number ?? '–' }}
                                </flux:table.cell>
                                <flux:table.cell class="font-medium truncate">
                                    {{ $event->display_name }}
                                    @unless($event->is_scored)
                                        <flux:badge size="sm" color="amber" class="ml-1"
                                                    title="Rahmenbewerb: Ergebnisse und Meldungen werden nicht importiert">nicht gewertet</flux:badge>
                                    @endunless
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
                                <flux:table.cell class="text-zinc-500 text-sm truncate" title="{{ $event->sport_classes }}">
                                    {{ $event->sport_classes ?? '–' }}
                                </flux:table.cell>
                                <flux:table.cell>
                                    <div class="flex items-center gap-1 justify-end">
                                        @if(auth()->user()?->is_admin)
                                        <flux:button href="{{ route('events.edit', $event) }}" size="sm"
                                                     variant="ghost" icon="pencil" class="text-amber-500!"/>
                                        <form method="POST" action="{{ route('events.destroy', $event) }}"
                                              x-data="{ submit() { if (confirm('Disziplin löschen?')) this.$el.submit() } }"
                                              @submit.prevent="submit()">
                                            @csrf @method('DELETE')
                                            <flux:button type="submit" size="sm" variant="ghost" icon="trash"
                                                         class="text-red-500!"/>
                                        </form>
                                        @endif
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
                </div>
            </div>
        @endforeach
    @endif

    {{-- Clubs --}}
    @if($participatingClubs->isNotEmpty())
        <div class="mt-6">
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100 mb-3">Teilnehmende Vereine</h2>
            <div class="flex flex-wrap gap-2">
                @foreach($participatingClubs as $club)
                    <a href="{{ route('clubs.show', $club) }}">
                        <flux:badge color="zinc" size="sm">{{ $club->display_name }}</flux:badge>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

@endsection
