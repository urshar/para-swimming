@extends('layouts.app')

@section('title', "Qualifikation $list->year")

@section('content')
    @php
        // Einzelne, bereits fertige Werte in einem Array statt komplexer Ausdrücke direkt im
        // @js()-Ausdruck (siehe CLAUDE.md: @json()/@js()-Komma-Fallstrick). Die eigentliche
        // Alpine-Logik (inkl. $refs) sitzt in resources/js/qualification-filters.js — als reines
        // .js löst das auch die PhpStorm-Fehlmeldungen ("Unresolved variable $refs"), die ein
        // inline x-data-String mit eingebetteten @js()-Direktiven auslöste.
        $filterConfig = [
            'strokeDistance' => request('stroke_type_id') !== null && request('stroke_type_id') !== ''
                ? request('stroke_type_id').'|'.request('distance')
                : '',
            'gender' => (string) request('gender', ''),
            'sportClass' => (string) request('sport_class', ''),
            'club' => (string) request('club_id', ''),
            'search' => (string) request('search', ''),
        ];
    @endphp
    {{-- Kein max-w-Wrapper — wie records/index.blade.php: volle verfügbare Breite, damit die
         Filterleiste (Bewerb/Geschlecht/Sportklasse/Verein/Suche) genug Platz zum Lesen hat. --}}
    <div x-data="{
             openOnly(id) {
                 document.querySelectorAll('[data-flux-accordion-item]').forEach(el => { el.value = el.id === id })
             },
             openAll() {
                 document.querySelectorAll('[data-flux-accordion-item]').forEach(el => { el.value = true })
             },
         }">
        <div class="mb-6">
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Qualifikation {{ $list->year }}</h1>
                {{-- Eindeutige Athleten zählen, nicht Qualifikations-Zeilen — ein Athlet kann in mehreren
                     Bewerben qualifiziert sein, $qualifications->count() zählte bisher jede Zeile einzeln
                     und übertrieb die Schwimmer-Anzahl entsprechend. --}}
                <flux:badge color="emerald">{{ $qualifications->pluck('athlete_id')->unique()->count() }} Schwimmer</flux:badge>
            </div>

            <div class="flex items-center flex-wrap gap-2 mt-4">
                <flux:button href="{{ route('qualifying-time-lists.show', $list) }}" variant="filled"
                             icon="arrow-left" size="sm">
                    Zurück
                </flux:button>

                @unless($qualifications->isEmpty())
                    <flux:dropdown>
                        <flux:button variant="filled" size="sm" icon:trailing="chevron-down" class="text-blue-500!">
                            Inhaltsverzeichnis
                        </flux:button>
                        <flux:menu>
                            @foreach($sections as $section)
                                @php $groupId = 'group-'.($section['group']?->id ?? 'sonstige'); @endphp
                                <flux:menu.item href="#{{ $groupId }}" @click="openOnly('{{ $groupId }}')">
                                    {{ $section['group']?->name_de ?? 'Sonstige Sportklassen' }}
                                </flux:menu.item>
                            @endforeach
                            <flux:menu.separator/>
                            <flux:menu.item icon="squares-2x2" @click="openAll()">
                                Alle aufklappen
                            </flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                @endunless

                <div class="ml-auto flex items-center flex-wrap gap-2">
                    <flux:button
                        href="{{ route('qualifying-time-lists.qualifications.pdf', $list) }}?{{ http_build_query(request()->query()) }}"
                        variant="filled" icon="printer" size="sm" target="_blank" class="text-purple-500!">
                        PDF
                    </flux:button>
                </div>
            </div>
        </div>

        {{--
            Filter — eine Zeile, linksbündig, ohne Card — wie records/index.blade.php. Jedes Feld löst bei
            Änderung sofort eine neue Suche aus (kein Filtern-Button nötig). Die vier Selects laufen über
            x-model + $watch statt onchange="this.form.submit()": das interne "change"-Event von flux:select
            (Custom Element <ui-select>) feuert mit bubbles:false, ein @change direkt draufgesetzt kam im Test
            nicht zuverlässig an. Die Suche ist ein natives <input> (kein Custom Element) und läuft daher
            direkt über x-model.debounce, damit nicht bei jedem Tastendruck sofort abgesendet wird. Die
            Alpine-Logik sitzt in resources/js/qualification-filters.js (Alpine.data), nicht inline.
        --}}
        <form method="GET" action="{{ route('qualifying-time-lists.qualifications', $list) }}"
              class="flex flex-wrap items-center gap-3 mb-6"
              x-data="qualificationFilters(@js($filterConfig))">
            <flux:select variant="listbox" name="stroke_type_id_distance" x-model="strokeDistance"
                placeholder="Alle Bewerbe" clearable class="w-56">
                @foreach($events as $event)
                    <flux:select.option value="{{ $event['stroke_type_id'] }}|{{ $event['distance'] }}">
                        {{ $event['label'] }}
                    </flux:select.option>
                @endforeach
            </flux:select>
            <input type="hidden" name="stroke_type_id" x-ref="strokeTypeIdField" value="{{ request('stroke_type_id') }}"/>
            <input type="hidden" name="distance" x-ref="distanceField" value="{{ request('distance') }}"/>

            <flux:select variant="listbox" name="gender" x-model="genderFilter" placeholder="Alle" clearable
                class="w-28">
                @foreach($genders as $gender)
                    <flux:select.option value="{{ $gender }}">{{ $gender }}</flux:select.option>
                @endforeach
            </flux:select>

            {{-- Alle möglichen Sportklassen (nicht nur die für diese Liste tatsächlich
                 vorkommenden), je Nummer über S/SB/SM zusammengefasst (z.B. "S03,SB03,SM03"),
                 siehe QualifyingTimeListController::buildSportClassOptions(). --}}
            <flux:select variant="listbox" name="sport_class" x-model="sportClassFilter" placeholder="Alle Klassen"
                clearable class="w-56">
                @foreach($sportClassOptions as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select variant="listbox" searchable name="club_id" x-model="clubFilter" placeholder="Alle Vereine"
                clearable class="w-56">
                @foreach($clubs as $club)
                    <flux:select.option value="{{ $club->id }}">{{ $club->display_name ?? $club->name }}</flux:select.option>
                @endforeach
            </flux:select>

            {{-- flux:input setzt intern selbst "w-full" auf demselben Wrapper-Div wie ein von außen
                 übergebenes class="w-56" — beide Utility-Klassen zielen auf dieselbe width-Eigenschaft,
                 "w-full" gewinnt in der Tailwind-Kaskade (auch mit w-56! als Important-Variante, das griff
                 hier live geprüft nicht). Deshalb stattdessen in einen eigenen, schmalen Container wrappen:
                 "w-full" bezieht sich dann auf dessen Breite statt auf die volle Formularzeile. --}}
            <div class="w-56">
                <flux:input name="search" x-model.debounce.500ms="searchFilter" placeholder="Name oder Verein"/>
            </div>

            @if(request()->anyFilled(['stroke_type_id', 'gender', 'sport_class', 'club_id', 'search']))
                <flux:button href="{{ route('qualifying-time-lists.qualifications', $list) }}" variant="filled"
                             icon="x-mark" class="text-red-500!">
                    Filter zurücksetzen
                </flux:button>
            @endif
        </form>

        @if($qualifications->isEmpty())
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-8">
                <p class="text-sm text-zinc-400 text-center">
                    Keine Qualifikationen gefunden — ggf. Filter anpassen oder zuerst unter „Bearbeiten" die
                    Qualifikation berechnen.
                </p>
            </div>
        @else
            @foreach($sections as $section)
                <flux:accordion class="mb-6">
                <flux:accordion.item id="group-{{ $section['group']?->id ?? 'sonstige' }}" class="scroll-mt-4"
                                      expanded transition>
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
