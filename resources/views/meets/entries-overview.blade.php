@php use App\Support\TimeParser; @endphp

@extends('layouts.app')

@section('title', 'Alle Meldungen – ' . $meet->name)

@section('content')

    {{-- ── Kopf ──────────────────────────────────────────────────────────────── --}}
    <div class="mb-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <flux:button href="{{ route('meets.show', $meet) }}" variant="primary" icon="arrow-left"
                             size="sm" title="Zurück" aria-label="Zurück"/>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Alle Meldungen</h1>
            </div>

            <div class="flex flex-wrap gap-2">
                {{-- Meldebasierte Listen (Teilnehmerliste alle Vereine, Sportpasskontrolle) --}}
                <flux:dropdown>
                    <flux:button variant="filled" size="sm" icon="document-arrow-down"
                                 icon:trailing="chevron-down" class="text-blue-500!">Listen</flux:button>
                    {{-- Listen in einem eigenen Tab öffnen (target=_blank), damit die
                         "Alle Meldungen"-Ansicht erhalten bleibt. --}}
                    <flux:menu>
                        <flux:menu.item icon="document-text" target="_blank"
                                        href="{{ route('meets.entry-lists.teilnehmer.pdf', $meet) }}">
                            Teilnehmerliste (PDF)
                        </flux:menu.item>
                        <flux:menu.item icon="table-cells" target="_blank"
                                        href="{{ route('meets.entry-lists.teilnehmer.xlsx', $meet) }}">
                            Teilnehmerliste (Excel)
                        </flux:menu.item>
                        <flux:menu.separator/>
                        <flux:menu.item icon="document-text" target="_blank"
                                        href="{{ route('meets.entry-lists.nach-namen.pdf', $meet) }}">
                            Meldeliste nach Namen (PDF)
                        </flux:menu.item>
                        <flux:menu.item icon="document-text" target="_blank"
                                        href="{{ route('meets.entry-lists.nach-bewerben.pdf', $meet) }}">
                            Meldeliste nach Bewerben – 2-spaltig (PDF)
                        </flux:menu.item>
                        <flux:menu.item icon="document-text" target="_blank"
                                        href="{{ route('meets.entry-lists.nach-bewerben.pdf', ['meet' => $meet, 'columns' => 1]) }}">
                            Meldeliste nach Bewerben – 1-spaltig (PDF)
                        </flux:menu.item>
                        <flux:menu.separator/>
                        <flux:menu.item icon="document-text" target="_blank"
                                        href="{{ route('meets.entry-lists.sportpass.pdf', $meet) }}">
                            Sportpasskontrolle (PDF)
                        </flux:menu.item>
                        <flux:menu.item icon="table-cells" target="_blank"
                                        href="{{ route('meets.entry-lists.sportpass.xlsx', $meet) }}">
                            Sportpasskontrolle (Excel)
                        </flux:menu.item>
                        <flux:menu.separator/>
                        {{-- Online-Abrechnung bewusst ohne target=_blank: normale Seite mit Zurück-Navigation. --}}
                        <flux:menu.item icon="banknotes" href="{{ route('meets.fees.index', $meet) }}">
                            Meldegeld-Abrechnung
                        </flux:menu.item>
                        <flux:menu.item icon="document-text" target="_blank"
                                        href="{{ route('meets.entry-lists.meldegeld.pdf', $meet) }}">
                            Meldegeld-Abrechnung (PDF)
                        </flux:menu.item>
                    </flux:menu>
                </flux:dropdown>

                {{-- Anlegen direkt von hier (nur bei offenem Meet). Einzel führt ins
                     Admin-Formular; Staffel über die Relay-Liste mit Vereinsauswahl, da es
                     keinen direkten Admin-Weg gibt, eine Staffel ohne gewählten Verein
                     anzulegen (createRelay bricht ohne club_id mit 400 ab). --}}
                @if($meet->is_open)
                    <flux:button href="{{ route('meets.entries.create', ['meet' => $meet, 'return_to' => url()->full()]) }}"
                                 variant="primary" icon="plus" size="sm">Neue Einzelmeldung</flux:button>
                    <flux:button href="{{ route('club-entries.relay.create', ['meet' => $meet, 'return_to' => url()->full()]) }}"
                                 variant="filled" icon="plus" size="sm" class="text-blue-500!">Neue Staffelmeldung</flux:button>
                @endif
            </div>
        </div>
        <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
            {{ $meet->name }} · {{ $meet->start_date?->format('d.m.Y') }}
            · {{ $einzelTotal }} Einzel-, {{ $staffelTotal }} Staffelmeldungen · alle Vereine
        </p>
    </div>

    @php
        // Bei aktivem Disziplin-Filter nur den passenden Abschnitt zeigen: eine
        // Disziplin ist immer entweder Einzel- ODER Staffelbewerb, der jeweils andere
        // Abschnitt (samt "keine …"-Hinweis) wäre dann nur Rauschen.
        $filteredEvent = $eventFilter !== null ? $events->firstWhere('id', $eventFilter) : null;
        $showEinzel = $filteredEvent === null || $filteredEvent->relay_count <= 1;
        $showStaffel = $filteredEvent === null || $filteredEvent->relay_count > 1;
    @endphp

    {{-- ── Disziplin-Filter (greift direkt bei Auswahl, ohne "Filtern"-Button) ───── --}}
    {{-- flux:select bubbelt sein Change-Event nicht (CLAUDE.md) — daher x-model auf
         eine Alpine-Variable + $watch, der das GET-Formular absendet. Das leere
         (clearable) Feld sendet event_id="" → Controller wertet das als "alle". --}}
    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 p-4 mb-6">
        @php $eventIdValue = $eventFilter !== null ? (string) $eventFilter : ''; @endphp
        <div x-data='{ eventId: @json($eventIdValue) }'
             x-init="$watch('eventId', () => $el.querySelector('form').submit())">
            <form method="GET">
                <div class="max-w-md">
                    <flux:label>Disziplin</flux:label>
                    <flux:select variant="listbox" searchable name="event_id" placeholder="Alle Disziplinen"
                                 clearable x-model="eventId">
                        @foreach($events as $event)
                            <flux:select.option value="{{ $event->id }}">
                                {{ $event->event_number ? 'Nr. ' . $event->event_number . ' – ' : '' }}{{ $event->display_name }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            </form>
        </div>
    </div>

    {{-- ── Einzelmeldungen ───────────────────────────────────────────────────── --}}
    @if($showEinzel)
    <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100 mb-3">Einzelmeldungen</h2>

    @php $anyEinzel = false; @endphp
    @foreach($events as $event)
        @php $eventEntries = $entriesByEvent[$event->id] ?? null; @endphp
        @if($eventEntries)
            @php $anyEinzel = true; @endphp
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 overflow-hidden mb-4">
                <div class="px-4 py-2.5 bg-zinc-50 dark:bg-zinc-800/60 border-b border-zinc-200 dark:border-zinc-700
                            flex items-center gap-2">
                    <h3 class="font-semibold text-zinc-800 dark:text-zinc-100">
                        {{ $event->event_number ? 'Nr. ' . $event->event_number . ' – ' : '' }}{{ $event->display_name }}
                    </h3>
                    <x-gender-icon :gender="$event->gender"/>
                    <span class="ml-auto text-xs text-zinc-400">{{ $eventEntries->count() }} Meldungen</span>
                </div>
                <div class="p-4 [--flux-bleed:1rem]">
                    <flux:table bleed>
                        <flux:table.columns>
                            <flux:table.column>Athlet</flux:table.column>
                            <flux:table.column>Verein</flux:table.column>
                            <flux:table.column>Klasse</flux:table.column>
                            <flux:table.column>Meldezeit</flux:table.column>
                            <flux:table.column>Status</flux:table.column>
                            <flux:table.column></flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach($eventEntries as $entry)
                                <flux:table.row>
                                    <flux:table.cell class="font-medium text-zinc-900 dark:text-white">
                                        <a href="{{ route('athletes.show', $entry->athlete) }}"
                                           class="hover:text-blue-600 dark:hover:text-blue-400">
                                            {{ $entry->athlete?->display_name }}
                                        </a>
                                    </flux:table.cell>
                                    <flux:table.cell class="text-sm text-zinc-500 dark:text-zinc-400">
                                        {{ $entry->club?->display_name }}
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        @if($entry->sport_class)
                                            <flux:badge size="sm">{{ $entry->sport_class }}</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell class="font-mono text-sm">
                                        {{ $entry->formatted_entry_time }}
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        @if($entry->status === 'EXH')
                                            <flux:badge size="sm" color="violet" title="Außer Konkurrenz">AK</flux:badge>
                                        @elseif($entry->status)
                                            <flux:badge size="sm" color="{{ $entry->status === 'WDR' ? 'zinc' : 'yellow' }}">
                                                {{ $entry->status }}
                                            </flux:badge>
                                        @endif
                                        @if($entry->is_late_entry)
                                            <flux:badge size="sm" color="orange">Nachmeldung</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell class="text-right">
                                        <flux:button href="{{ route('entries.edit', $entry) }}" size="xs" variant="ghost"
                                                     icon="pencil" class="text-amber-500!"/>
                                        <form method="POST" action="{{ route('entries.destroy', $entry) }}" class="inline">
                                            @csrf @method('DELETE')
                                            <flux:button type="submit" size="xs" variant="ghost" icon="trash"
                                                         class="text-red-500!"
                                                         onclick="return confirm('Meldung löschen?')"/>
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

    @unless($anyEinzel)
        <p class="text-sm text-zinc-400 dark:text-zinc-500 py-4">
            Keine Einzelmeldungen{{ $eventFilter !== null ? ' in dieser Disziplin' : '' }}.
        </p>
    @endunless
    @endif {{-- showEinzel --}}

    {{-- ── Staffelmeldungen ──────────────────────────────────────────────────── --}}
    @if($showStaffel)
    <h2 id="staffelmeldungen" class="scroll-mt-20 text-lg font-semibold text-zinc-900 dark:text-zinc-100 {{ $showEinzel ? 'mt-8' : '' }} mb-3">Staffelmeldungen</h2>

    @php $anyStaffel = false; @endphp
    @foreach($events as $event)
        @php $eventRelays = $relaysByEvent[$event->id] ?? null; @endphp
        @if($eventRelays)
            @php $anyStaffel = true; @endphp
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 overflow-hidden mb-4">
                <div class="px-4 py-2.5 bg-zinc-50 dark:bg-zinc-800/60 border-b border-zinc-200 dark:border-zinc-700
                            flex items-center gap-2">
                    <h3 class="font-semibold text-zinc-800 dark:text-zinc-100">
                        {{ $event->event_number ? 'Nr. ' . $event->event_number . ' – ' : '' }}{{ $event->display_name }}
                    </h3>
                    <span class="ml-auto text-xs text-zinc-400">{{ $eventRelays->count() }} Staffeln</span>
                </div>
                <div class="p-4 space-y-3">
                    @foreach($eventRelays as $relay)
                        @php
                            $required = $relay->swimEvent->relay_count ?? 4;
                            $memberCount = $relay->members->count();
                        @endphp
                        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4">
                            <div class="flex items-start justify-between gap-4 mb-3">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="font-medium text-zinc-900 dark:text-zinc-100">
                                        {{ $relayNames[$relay->id] }}
                                    </span>
                                    @if($relay->name)
                                        {{-- Eigener Name: Verein zusätzlich nennen, sonst ist er nicht erkennbar. --}}
                                        <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ $relay->club?->display_name }}</span>
                                    @endif
                                    @if($relay->relay_class)
                                        <flux:badge color="blue" size="sm"
                                                    class="font-mono">{{ $relay->relay_class }}</flux:badge>
                                    @endif
                                    <x-gender-icon :gender="$relay->teamGender() ?? $relay->swimEvent->gender"/>
                                    @if($memberCount === $required)
                                        <flux:badge color="green" size="sm">Vollständig</flux:badge>
                                    @else
                                        <flux:badge color="zinc" size="sm">{{ $memberCount }}/{{ $required }} Athleten</flux:badge>
                                    @endif
                                    @if($relay->is_exhibition)
                                        <flux:badge color="violet" size="sm" title="Außer Konkurrenz">AK</flux:badge>
                                    @endif
                                    @if($relay->is_late_entry)
                                        <flux:badge color="orange" size="sm">Nachmeldung</flux:badge>
                                    @endif
                                    @if($relay->entry_time || $relay->entry_time_code)
                                        <span class="font-mono text-sm text-zinc-700 dark:text-zinc-200">
                                            {{ $relay->entry_time ? TimeParser::display($relay->entry_time) : $relay->entry_time_code }}
                                        </span>
                                    @endif
                                </div>
                                <div class="flex items-center gap-1 shrink-0">
                                    <flux:button
                                        href="{{ route('club-entries.relay.edit', ['meet' => $meet, 'relayEntry' => $relay, 'club_id' => $relay->club_id, 'return_to' => url()->full()]) }}"
                                        size="xs" variant="ghost" icon="pencil" class="text-amber-500!"/>
                                    <form method="POST"
                                          action="{{ route('club-entries.relay.destroy', ['meet' => $meet, 'relayEntry' => $relay, 'club_id' => $relay->club_id]) }}"
                                          class="inline">
                                        @csrf @method('DELETE')
                                        <flux:button type="submit" size="xs" variant="ghost" icon="trash"
                                                     class="text-red-500!"
                                                     onclick="return confirm('Staffelmeldung löschen?')"/>
                                    </form>
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-zinc-600 dark:text-zinc-300">
                                @forelse($relay->members as $member)
                                    <span class="inline-flex items-center gap-1">
                                        <span class="text-zinc-400 font-mono text-xs">{{ $member->position }}.</span>
                                        {{ $member->athlete?->last_name }}, {{ $member->athlete?->first_name }}
                                        <x-gender-icon :gender="$member->athlete?->gender" class="text-xs"/>
                                    </span>
                                @empty
                                    <span class="italic text-zinc-400">Noch keine Athleten eingetragen.</span>
                                @endforelse
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @endforeach

    @unless($anyStaffel)
        <p class="text-sm text-zinc-400 dark:text-zinc-500 py-4">
            Keine Staffelmeldungen{{ $eventFilter !== null ? ' in dieser Disziplin' : '' }}.
        </p>
    @endunless
    @endif {{-- showStaffel --}}

@endsection
