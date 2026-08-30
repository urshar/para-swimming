@extends('layouts.app')

@section('title', "Qualifikation $list->year")

@section('content')
    @php
        // Einzelne, bereits fertige Werte statt komplexer Ausdrücke direkt im
        // x-data-Objektliteral (siehe CLAUDE.md: @json()/@js()-Komma-Fallstrick).
        $strokeDistanceFilter = request('stroke_type_id') !== null && request('stroke_type_id') !== ''
            ? request('stroke_type_id').'|'.request('distance')
            : '';
        $genderFilter = (string) request('gender', '');
        $sportClassFilter = (string) request('sport_class', '');
        $clubFilter = (string) request('club_id', '');
    @endphp
    <div class="max-w-5xl">
        <div class="flex items-center gap-3 mb-6">
            <flux:button href="{{ route('qualifying-time-lists.show', $list) }}" variant="ghost" icon="arrow-left"
                         size="sm"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Qualifikation {{ $list->year }}</h1>
            <flux:badge color="zinc">{{ $qualifications->count() }} Schwimmer</flux:badge>
            <flux:button href="{{ route('qualifying-time-lists.qualifications.pdf', $list) }}?{{ http_build_query(request()->query()) }}"
                         variant="ghost" icon="printer" size="sm" target="_blank" class="ms-auto">
                PDF
            </flux:button>
        </div>

        {{--
            Filter — vier Selects lösen bei Änderung automatisch die Suche aus. Die früheren nativen
            <select onchange="this.form.submit()"> funktionieren mit flux:select nicht mehr: das ist ein
            Custom Element (<ui-select>), dessen internes "change"-Event mit {bubbles:false} feuert — ein
            @change auf dem <ui-select> selbst kam beim Test nicht an. x-model (der ohnehin für
            Flux-Komponenten vorgeschriebene Weg, siehe CLAUDE.md) + $watch funktioniert zuverlässig.
        --}}
        <form method="GET" action="{{ route('qualifying-time-lists.qualifications', $list) }}"
              class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 mb-6 grid grid-cols-2 md:grid-cols-5 gap-3 items-end"
              x-data="{
                  strokeDistance: @js($strokeDistanceFilter),
                  genderFilter: @js($genderFilter),
                  sportClassFilter: @js($sportClassFilter),
                  clubFilter: @js($clubFilter),
                  submitFilter() {
                      const [s, d] = (this.strokeDistance || '').split('|');
                      this.$refs.strokeTypeIdField.value = s ?? '';
                      this.$refs.distanceField.value = d ?? '';
                      this.$el.submit();
                  },
              }"
              x-init="
                  $watch('strokeDistance', () => submitFilter());
                  $watch('genderFilter', () => submitFilter());
                  $watch('sportClassFilter', () => submitFilter());
                  $watch('clubFilter', () => submitFilter());
              ">
            <flux:field>
                <flux:label>Bewerb</flux:label>
                <flux:select variant="listbox" name="stroke_type_id_distance" x-model="strokeDistance"
                    placeholder="Alle" clearable>
                    @foreach($events as $event)
                        <flux:select.option value="{{ $event['stroke_type_id'] }}|{{ $event['distance'] }}">
                            {{ $event['label'] }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
                <input type="hidden" name="stroke_type_id" x-ref="strokeTypeIdField" value="{{ request('stroke_type_id') }}"/>
                <input type="hidden" name="distance" x-ref="distanceField" value="{{ request('distance') }}"/>
            </flux:field>

            <flux:field>
                <flux:label>Geschlecht</flux:label>
                <flux:select variant="listbox" name="gender" x-model="genderFilter" placeholder="Alle" clearable>
                    @foreach($genders as $gender)
                        <flux:select.option value="{{ $gender }}">{{ $gender }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field>
                <flux:label>Sportklasse</flux:label>
                <flux:select variant="listbox" name="sport_class" x-model="sportClassFilter" placeholder="Alle" clearable>
                    @foreach($sportClasses as $sportClass)
                        <flux:select.option value="{{ $sportClass }}">{{ $sportClass }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field>
                <flux:label>Verein</flux:label>
                <flux:select variant="listbox" searchable name="club_id" x-model="clubFilter" placeholder="Alle" clearable>
                    @foreach($clubs as $club)
                        <flux:select.option value="{{ $club->id }}">{{ $club->display_name ?? $club->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field>
                <flux:label>Suche (Name/Verein)</flux:label>
                <div class="flex gap-2">
                    <flux:input name="search" value="{{ request('search') }}" placeholder="Name oder Verein"/>
                    <flux:button type="submit" variant="primary" size="sm" icon="magnifying-glass"/>
                </div>
            </flux:field>
        </form>

        @if(request()->anyFilled(['stroke_type_id', 'gender', 'sport_class', 'club_id', 'search']))
            <div class="mb-4">
                <flux:button href="{{ route('qualifying-time-lists.qualifications', $list) }}" variant="ghost"
                             size="sm" icon="x-mark">
                    Filter zurücksetzen
                </flux:button>
            </div>
        @endif

        @if($qualifications->isEmpty())
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-8">
                <p class="text-sm text-zinc-400 text-center">
                    Keine Qualifikationen gefunden — ggf. Filter anpassen oder zuerst unter „Bearbeiten" die
                    Qualifikation berechnen.
                </p>
            </div>
        @else
            {{-- ── Inhaltsverzeichnis ─────────────────────────────────────── --}}
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 mb-6">
                <p class="text-xs font-semibold text-zinc-400 dark:text-zinc-500 uppercase tracking-wider mb-2">
                    Inhaltsverzeichnis
                </p>
                <div class="flex flex-wrap gap-2">
                    @foreach($sections as $section)
                        <a href="#group-{{ $section['group']?->id ?? 'sonstige' }}"
                           class="px-2.5 py-1 rounded-lg bg-zinc-100 dark:bg-zinc-700 text-sm text-zinc-700 dark:text-zinc-200 hover:bg-zinc-200 dark:hover:bg-zinc-600">
                            {{ $section['group']?->name_de ?? 'Sonstige Sportklassen' }}
                        </a>
                    @endforeach
                </div>
            </div>

            @foreach($sections as $section)
                <flux:accordion id="group-{{ $section['group']?->id ?? 'sonstige' }}" class="mb-6 scroll-mt-4">
                <flux:accordion.item expanded transition>
                    <flux:accordion.heading class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">
                        {{ $section['group']?->name_de ?? 'Sonstige Sportklassen' }}
                    </flux:accordion.heading>

                    <flux:accordion.content>
                        @foreach($section['strokes'] as $strokeGroup)
                            <div class="mb-4">
                                <h3 class="text-xs font-semibold text-zinc-400 dark:text-zinc-500 uppercase tracking-wider mb-2 px-1">
                                    {{ $strokeGroup['distance'].'m '.($strokeGroup['stroke']?->name_de ?? 'Unbekannte Lage') }}
                                </h3>
                                <div
                                    class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                                    <flux:table
                                        class="[&_td:first-child]:ps-4 [&_th:first-child]:ps-4 [&_td:last-child]:pe-4 [&_th:last-child]:pe-4">
                                        <flux:table.columns>
                                            <flux:table.column>Name</flux:table.column>
                                            <flux:table.column>Verein</flux:table.column>
                                            <flux:table.column>Geschlecht</flux:table.column>
                                            <flux:table.column>Sportklasse</flux:table.column>
                                            <flux:table.column>Zeit</flux:table.column>
                                            <flux:table.column>Richtzeit</flux:table.column>
                                            <flux:table.column>Punkte</flux:table.column>
                                            <flux:table.column>Datum</flux:table.column>
                                        </flux:table.columns>
                                        <flux:table.rows>
                                            @foreach($strokeGroup['items'] as $q)
                                                <flux:table.row>
                                                    <flux:table.cell class="font-medium">
                                                        {{ $q->athlete?->last_name }}, {{ $q->athlete?->first_name }}
                                                    </flux:table.cell>
                                                    <flux:table.cell>
                                                        {{ $q->club?->display_name ?? $q->club?->name ?? '–' }}
                                                    </flux:table.cell>
                                                    <flux:table.cell>{{ $q->qualifyingTime->gender }}</flux:table.cell>
                                                    <flux:table.cell class="font-mono">{{ $q->sport_class }}</flux:table.cell>
                                                    <flux:table.cell class="font-mono">{{ $q->formatted_swim_time }}</flux:table.cell>
                                                    <flux:table.cell class="font-mono text-zinc-400">
                                                        {{ $q->qualifyingTime->formatted_value }}
                                                    </flux:table.cell>
                                                    <flux:table.cell>{{ $q->points ?? '–' }}</flux:table.cell>
                                                    <flux:table.cell>{{ $q->qualified_at->format('d.m.Y') }}</flux:table.cell>
                                                </flux:table.row>
                                            @endforeach
                                        </flux:table.rows>
                                    </flux:table>
                                </div>
                            </div>
                        @endforeach
                    </flux:accordion.content>
                </flux:accordion.item>
                </flux:accordion>
            @endforeach
        @endif
    </div>
@endsection
