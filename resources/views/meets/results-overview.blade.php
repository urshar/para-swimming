@extends('layouts.app')

@section('title', 'Ergebnisse – ' . $meet->name)

@section('content')

    {{-- ── Kopf ──────────────────────────────────────────────────────────────── --}}
    <div class="mb-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <flux:button href="{{ route('meets.show', $meet) }}" variant="primary" icon="arrow-left"
                             size="sm" title="Zurück" aria-label="Zurück"/>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Ergebnisse</h1>
            </div>

            <div class="flex flex-wrap gap-2">
                <form method="POST" action="{{ route('records.check', $meet) }}"
                      x-data="{ submit() { if (confirm('Alle Ergebnisse auf Rekorde prüfen?')) this.$el.submit() } }"
                      @submit.prevent="submit()">
                    @csrf
                    <flux:button type="submit" variant="filled" icon="star" size="sm" class="text-blue-500!">
                        Rekorde prüfen
                    </flux:button>
                </form>
                @if($meet->hasWpsPointsEnabled())
                    <form method="POST" action="{{ route('meets.wps-points.recalculate', $meet) }}"
                          x-data="{ submit() { if (confirm('WPS-Punkte für alle Ergebnisse neu berechnen?')) this.$el.submit() } }"
                          @submit.prevent="submit()">
                        @csrf
                        <flux:button type="submit" variant="filled" icon="calculator" size="sm" class="text-blue-500!">
                            WPS-Punkte berechnen
                        </flux:button>
                    </form>
                @endif
                {{-- Eigener Tab, damit die Sammelansicht erhalten bleibt; übernimmt einen gesetzten Disziplin-Filter. --}}
                <flux:button href="{{ route('meets.results-overview.pdf', array_filter(['meet' => $meet, 'event_id' => $filterConfig['event_id']])) }}"
                             target="_blank" variant="filled" icon="document-text" size="sm" class="text-blue-500!">
                    Ergebnisliste (PDF)
                </flux:button>
                <flux:button href="{{ route('meets.results.create', $meet) }}" variant="primary" icon="plus" size="sm">
                    Ergebnis erfassen
                </flux:button>
            </div>
        </div>
        <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
            {{ $meet->name }} · {{ $meet->start_date?->format('d.m.Y') }} · {{ $total }} {{ $total === 1 ? 'Ergebnis' : 'Ergebnisse' }}
        </p>
    </div>

    {{-- Flash (das Layout zeigt Flash-Meldungen nicht an) --}}
    @if(session('success'))
        <div class="mb-4 p-3 bg-green-50 dark:bg-green-950/20 border border-green-200 dark:border-green-800
                    rounded-xl text-sm text-green-700 dark:text-green-400" role="status">
            {{ session('success') }}
        </div>
    @endif
    @if($errors->any())
        <div class="mb-4 p-3 bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-800
                    rounded-xl text-sm text-red-700 dark:text-red-400" role="alert">
            {{ $errors->first() }}
        </div>
    @endif

    @if(session('record_check_result'))
        <div class="mb-6">
            @include('records.check-result', ['checkResult' => session('record_check_result')])
        </div>
    @endif

    {{-- ── Filter (greifen direkt bei Änderung, generische Komponente index-filters.js) ── --}}
    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 p-4 mb-6">
        <form method="GET" class="flex flex-wrap gap-3" x-data='indexFilters(@json($filterConfig))'>
            <flux:select variant="listbox" searchable name="event_id" x-model="event_id"
                         placeholder="Alle Disziplinen" clearable class="flex-1 min-w-56">
                @foreach($events as $event)
                    <flux:select.option value="{{ $event->id }}">
                        {{ $event->event_number ? 'Nr. ' . $event->event_number . ' – ' : '' }}{{ $event->display_name }}
                    </flux:select.option>
                @endforeach
            </flux:select>
            <flux:select variant="listbox" searchable name="club_id" x-model="club_id"
                         placeholder="Alle Vereine" clearable class="flex-1 min-w-48">
                @foreach($clubs as $club)
                    <flux:select.option value="{{ $club->id }}">{{ $club->display_name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input name="search" x-model.debounce.500ms="search" placeholder="Athlet suchen…"
                        class="flex-1 min-w-48"/>
            @if($isFiltered)
                <div class="ml-auto flex items-center">
                    <flux:button href="{{ route('meets.results-overview', $meet) }}" variant="filled" icon="x-mark"
                                 class="text-red-500!">Zurücksetzen</flux:button>
                </div>
            @endif
        </form>
    </div>

    {{-- ── Ergebnisse je Disziplin ───────────────────────────────────────────── --}}
    @php $anyResults = false; @endphp
    @foreach($events as $event)
        @php $eventResults = $resultsByEvent[$event->id] ?? null; @endphp
        @if($eventResults)
            @php $anyResults = true; @endphp
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 overflow-hidden mb-4">
                <div class="px-4 py-2.5 bg-zinc-50 dark:bg-zinc-800/60 border-b border-zinc-200 dark:border-zinc-700
                            flex flex-wrap items-center gap-2">
                    <h2 class="font-semibold text-zinc-800 dark:text-zinc-100">
                        {{ $event->event_number ? 'Nr. ' . $event->event_number . ' – ' : '' }}{{ $event->display_name }}
                    </h2>
                    <x-gender-icon :gender="$event->gender"/>
                    <span class="ml-auto text-xs text-zinc-400">{{ $eventResults->count() }} {{ $eventResults->count() === 1 ? 'Ergebnis' : 'Ergebnisse' }}</span>
                    <flux:button href="{{ route('meets.results.create', ['meet' => $meet, 'swim_event_id' => $event->id]) }}"
                                 size="xs" variant="ghost" icon="plus" class="text-blue-500!"
                                 title="Ergebnis in dieser Disziplin erfassen"
                                 aria-label="Ergebnis in dieser Disziplin erfassen"/>
                    @php
                        $eventTotal = (int) ($eventTotals[$event->id] ?? 0);
                        $deleteAllConfirm = 'Alle ' . $eventTotal . ' ' . ($eventTotal === 1 ? 'Ergebnis' : 'Ergebnisse')
                            . ' von "' . $event->display_name . '" löschen' . ($eventTotal > $eventResults->count() ? ' (auch die durch den Filter ausgeblendeten)' : '')
                            . '? Das kann nicht rückgängig gemacht werden.';
                    @endphp
                    <form method="POST"
                          action="{{ route('meets.results-overview.destroy-event', ['meet' => $meet, 'swimEvent' => $event]) }}"
                          data-confirm="{{ $deleteAllConfirm }}"
                          x-data="{ submit() { if (confirm(this.$el.dataset.confirm)) this.$el.submit() } }"
                          @submit.prevent="submit()">
                        @csrf @method('DELETE')
                        <flux:button type="submit" size="xs" variant="ghost" icon="trash" class="text-red-500!"
                                     title="Alle Ergebnisse dieser Disziplin löschen"
                                     aria-label="Alle Ergebnisse dieser Disziplin löschen"/>
                    </form>
                </div>
                <div class="p-4 [--flux-bleed:1rem]">
                    <flux:table bleed>
                        <flux:table.columns>
                            <flux:table.column>Platz</flux:table.column>
                            <flux:table.column>Athlet</flux:table.column>
                            <flux:table.column>Verein</flux:table.column>
                            <flux:table.column>Klasse</flux:table.column>
                            <flux:table.column>Zeit</flux:table.column>
                            @if($pointColumns['points'])
                                <flux:table.column>Punkte</flux:table.column>
                            @endif
                            @if($pointColumns['wps'])
                                <flux:table.column>WPS</flux:table.column>
                            @endif
                            <flux:table.column>Rekorde</flux:table.column>
                            <flux:table.column>Status</flux:table.column>
                            <flux:table.column>Herkunft</flux:table.column>
                            <flux:table.column></flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach($eventResults as $result)
                                <flux:table.row>
                                    <flux:table.cell class="text-sm text-zinc-500 dark:text-zinc-400 tabular-nums">
                                        {{ $result->place ?: '–' }}
                                    </flux:table.cell>
                                    <flux:table.cell class="font-medium text-zinc-900 dark:text-white">
                                        <a href="{{ route('athletes.show', $result->athlete) }}"
                                           class="hover:text-blue-600 dark:hover:text-blue-400">
                                            {{ $result->athlete?->display_name }}
                                        </a>
                                    </flux:table.cell>
                                    <flux:table.cell class="text-sm text-zinc-500 dark:text-zinc-400">
                                        {{ $result->club?->display_name }}
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        @if($result->sport_class)
                                            <flux:badge size="sm">{{ $result->sport_class }}</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell class="font-mono text-sm font-semibold text-zinc-900 dark:text-white">
                                        {{ $result->formatted_swim_time }}
                                    </flux:table.cell>
                                    @if($pointColumns['points'])
                                        <flux:table.cell class="text-sm text-zinc-500 dark:text-zinc-400 tabular-nums">
                                            {{ $result->points ?? '–' }}
                                        </flux:table.cell>
                                    @endif
                                    @if($pointColumns['wps'])
                                        {{-- Geschätzte WPS-Punkte (abgeleitete Kurzbahn-Parameter, nicht offiziell)
                                             mit * wie in der globalen Ergebnisliste. --}}
                                        <flux:table.cell class="text-sm text-zinc-500 dark:text-zinc-400 tabular-nums">
                                            @if($result->hasWpsPoints())
                                                @if($result->hasEstimatedWpsPoints())
                                                    <span class="text-amber-600 dark:text-amber-400"
                                                          title="Geschätzt, nicht offiziell (abgeleitete Kurzbahn-Parameter)">{{ $result->wps_points }}*</span>
                                                @else
                                                    {{ $result->wps_points }}
                                                @endif
                                            @else
                                                –
                                            @endif
                                        </flux:table.cell>
                                    @endif
                                    <flux:table.cell>
                                        <div class="flex gap-1">
                                            @if($result->is_world_record)
                                                <flux:badge size="sm" color="yellow">WR</flux:badge>
                                            @endif
                                            @if($result->is_european_record)
                                                <flux:badge size="sm" color="blue">ER</flux:badge>
                                            @endif
                                            @if($result->is_national_record)
                                                <flux:badge size="sm" color="green">NR</flux:badge>
                                            @endif
                                            @if($result->is_junior_record)
                                                <flux:badge size="sm" color="violet">JR</flux:badge>
                                            @endif
                                            @if($result->is_regional_record || $result->is_regional_junior_record)
                                                <flux:badge size="sm" color="teal">LR</flux:badge>
                                            @endif
                                        </div>
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        @if($result->status === 'EXH')
                                            <flux:badge size="sm" color="violet" title="Außer Konkurrenz">AK</flux:badge>
                                        @elseif($result->status)
                                            <flux:badge size="sm"
                                                        color="{{ in_array($result->status, ['DSQ', 'DNS', 'DNF']) ? 'red' : 'zinc' }}">
                                                {{ $result->status }}
                                            </flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell class="text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ $result->lenex_result_id ? 'LENEX' : 'manuell' }}
                                    </flux:table.cell>
                                    <flux:table.cell class="text-right whitespace-nowrap">
                                        <flux:button href="{{ route('results.show', $result) }}" size="xs" variant="ghost"
                                                     icon="eye" title="Ansehen" aria-label="Ansehen"/>
                                        <flux:button href="{{ route('results.edit', $result) }}" size="xs" variant="ghost"
                                                     icon="pencil" class="text-amber-500!"
                                                     title="Bearbeiten" aria-label="Bearbeiten"/>
                                        <form method="POST" action="{{ route('results.destroy', $result) }}" class="inline"
                                              x-data="{ submit() { if (confirm('Ergebnis löschen?')) this.$el.submit() } }"
                                              @submit.prevent="submit()">
                                            @csrf @method('DELETE')
                                            <flux:button type="submit" size="xs" variant="ghost" icon="trash"
                                                         class="text-red-500!" title="Löschen" aria-label="Löschen"/>
                                        </form>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            </div>
        @endif
    @endforeach

    @if($anyResults && $pointColumns['wps'])
        <p class="text-xs text-zinc-500 dark:text-zinc-400 mb-4">
            Punkte = ÖBSV-Punkte (World-Aquatics-Formel). WPS = World-Para-Swimming-Punkte;
            <span class="text-amber-600 dark:text-amber-400">*</span> geschätzt, nicht offiziell (abgeleitete Kurzbahn-Parameter).
        </p>
    @endif

    @unless($anyResults)
        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 p-10 text-center">
            <p class="text-sm text-zinc-400 dark:text-zinc-500">
                {{ $isFiltered ? 'Keine Ergebnisse für diese Filter.' : 'Noch keine Ergebnisse erfasst.' }}
            </p>
            @unless($isFiltered)
                <flux:button href="{{ route('meets.results.create', $meet) }}" variant="ghost" icon="plus" class="mt-3">
                    Erstes Ergebnis erfassen
                </flux:button>
            @endunless
        </div>
    @endunless

@endsection
