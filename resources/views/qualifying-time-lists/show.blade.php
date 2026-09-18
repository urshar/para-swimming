@extends('layouts.app')

@section('title', "Richtzeiten $list->year")

@section('content')
    @php
        // Für die Geschlecht-Filterbuttons: nur tatsächlich vorkommende Werte. Die
        // Sportklassen-Auswahl (Dropdown) kommt direkt aus $sections — die sind bereits nach
        // Nummer gruppiert und sortiert (S/SB/SM zusammengefasst, siehe
        // DisabilityGroupGrouper::byNumberThenStroke()), kein eigenes usedNumbers nötig.
        $usedGenders = $list->times->pluck('gender')->unique()->sort()->values();
    @endphp
    <div class="max-w-4xl" x-data="qualifyingTimesShowFilter()">
        <div class="mb-6">
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Richtzeiten {{ $list->year }}</h1>
                @if($list->is_active)
                    <flux:badge color="emerald">Aktiv</flux:badge>
                @else
                    <flux:badge color="zinc">Inaktiv</flux:badge>
                @endif
                @if($list->isLatest())
                    <flux:badge color="blue">Aktuell</flux:badge>
                @else
                    <flux:badge color="zinc">Historisiert — schreibgeschützt</flux:badge>
                @endif
            </div>

            <div class="flex items-center flex-wrap gap-2 mt-4">
                <flux:button href="{{ route('qualifying-time-lists.index') }}" variant="filled" icon="arrow-left"
                             size="sm">
                    Zurück
                </flux:button>

                @unless($list->times->isEmpty())
                    <flux:dropdown>
                        <flux:button variant="filled" size="sm" icon:trailing="chevron-down" class="text-blue-500!">
                            Inhaltsverzeichnis
                        </flux:button>
                        <flux:menu>
                            @foreach($sections as $section)
                                @php $numberKey = $section['number'] ?? 'sonstige'; @endphp
                                <flux:menu.item href="#number-{{ $numberKey }}"
                                                @click="selectedNumber = '{{ $numberKey }}'">
                                    {{ $section['number'] !== null ? 'S'.$section['number'] : 'Sonstige Sportklassen' }}
                                </flux:menu.item>
                            @endforeach
                            <flux:menu.separator/>
                            <flux:menu.item icon="squares-2x2" @click="selectedNumber = 'ALL'">
                                Alle anzeigen
                            </flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                @endunless

                <div class="ml-auto flex items-center flex-wrap gap-2">
                    <flux:button href="{{ route('qualifying-time-lists.qualifications', $list) }}" variant="filled"
                                 icon="check-badge" size="sm">
                        Qualifizierte Schwimmer anzeigen
                    </flux:button>
                    <flux:button href="{{ route('qualifying-time-lists.pdf', $list) }}" variant="filled"
                                 icon="printer" size="sm" target="_blank" class="text-purple-500!">
                        PDF
                    </flux:button>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 mb-6">
            <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-4">Zielpunkte je Sportklasse</h2>
            <p class="text-xs text-zinc-400 mb-4">Standard: 100 Punkte. Nur abweichende Sportklassen sind hier gelistet.</p>

            @if($list->targetPoints->isNotEmpty())
                <div class="flex flex-wrap gap-2">
                    @foreach($list->targetPoints->sortBy('sort_key') as $tp)
                        <span
                            class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-zinc-100 dark:bg-zinc-700 text-sm text-zinc-800 dark:text-zinc-200">
                            {{ $tp->sport_class }}: {{ $tp->points }} Pkt.
                        </span>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-zinc-400">Keine Overrides — für alle Sportklassen gelten 100 Punkte.</p>
            @endif
        </div>

        @if($list->times->isEmpty())
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-8">
                <p class="text-sm text-zinc-400 text-center">Noch keine Richtzeiten hinterlegt.</p>
            </div>
        @else
            {{-- Geschlecht filtert Zeilen, Sportklasse zeigt gezielt nur den einen passenden
                 Abschnitt (und blendet die dann überflüssige Sportklasse-Spalte aus) — beides rein
                 deklarativ per x-show, siehe qualifying-times-show-filter.js. Erik, 17.09.2026:
                 einzelne Sportklasse zu finden dauerte über alle Abschnitte hinweg zu lange; ein
                 Akkordeon lieferte dabei keinen Mehrwert mehr, da ohnehin jeder Abschnitt bereits
                 offen war — ein Dropdown statt der zuvor umbrechenden Button-Reihe (bis zu S21+)
                 ersetzt es. --}}
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 mb-6">
                <div class="flex flex-wrap items-center gap-6">
                    <div class="flex flex-wrap items-center gap-1">
                        <span class="text-xs font-semibold text-zinc-400 dark:text-zinc-500 uppercase tracking-wider mr-2">
                            Geschlecht
                        </span>
                        <flux:button size="sm" variant="ghost" x-on:click="gender = ''"
                                     x-bind:class="gender === '' ? 'bg-zinc-800/5! hover:bg-zinc-800/10! dark:bg-white/10! dark:hover:bg-white/20!' : ''">
                            Alle
                        </flux:button>
                        @foreach($usedGenders as $genderOption)
                            <flux:button size="sm" variant="ghost" x-on:click="gender = '{{ $genderOption }}'"
                                         x-bind:class="gender === '{{ $genderOption }}' ? 'bg-zinc-800/5! hover:bg-zinc-800/10! dark:bg-white/10! dark:hover:bg-white/20!' : ''">
                                {{ $genderOption }}
                            </flux:button>
                        @endforeach
                    </div>

                    <div class="flex items-center gap-2">
                        <span class="text-xs font-semibold text-zinc-400 dark:text-zinc-500 uppercase tracking-wider">
                            Sportklasse
                        </span>
                        <flux:select variant="listbox" x-model="selectedNumber" size="sm" class="w-40">
                            <flux:select.option value="ALL">Alle</flux:select.option>
                            @foreach($sections as $section)
                                <flux:select.option value="{{ $section['number'] ?? 'sonstige' }}">
                                    {{ $section['number'] !== null ? 'S'.$section['number'] : 'Sonstige Sportklassen' }}
                                </flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                </div>
            </div>

            @foreach($sections as $section)
                @php $numberKey = $section['number'] ?? 'sonstige'; @endphp
                <div id="number-{{ $numberKey }}" class="scroll-mt-4 mb-6"
                     x-show="selectedNumber === 'ALL' || selectedNumber === '{{ $numberKey }}'">
                    <div
                        class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                        <div class="px-4 py-3 border-b border-zinc-100 dark:border-zinc-700">
                            <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">
                                {{ $section['number'] !== null ? 'S'.$section['number'] : 'Sonstige Sportklassen' }}
                            </h2>
                        </div>
                        <div class="p-4 [--flux-bleed:1rem]">
                            <flux:table bleed>
                                <flux:table.columns>
                                    <flux:table.column>Lage</flux:table.column>
                                    <flux:table.column>Geschlecht</flux:table.column>
                                    <flux:table.column x-show="selectedNumber === 'ALL'">Sportklasse</flux:table.column>
                                    <flux:table.column>Richtzeit</flux:table.column>
                                    <flux:table.column>Quelle</flux:table.column>
                                </flux:table.columns>
                                <flux:table.rows>
                                    @foreach($section['items'] as $time)
                                        <flux:table.row
                                            x-show="gender === '' || gender === '{{ $time->gender }}'">
                                            <flux:table.cell class="text-sm">
                                                {{ $time->distance }}m {{ $time->strokeType?->name_de ?? 'Unbekannte Lage' }}
                                            </flux:table.cell>
                                            <flux:table.cell>{{ $time->gender }}</flux:table.cell>
                                            <flux:table.cell class="font-mono" x-show="selectedNumber === 'ALL'">
                                                {{ $time->sport_class }}
                                            </flux:table.cell>
                                            <flux:table.cell class="font-mono">{{ $time->formatted_value ?? '–' }}</flux:table.cell>
                                            <flux:table.cell>
                                                @if($time->isManual())
                                                    <flux:badge color="amber">Manuell</flux:badge>
                                                @else
                                                    <flux:badge color="blue">Berechnet</flux:badge>
                                                @endif
                                            </flux:table.cell>
                                        </flux:table.row>
                                    @endforeach
                                </flux:table.rows>
                            </flux:table>
                        </div>
                    </div>
                </div>
            @endforeach
        @endif
    </div>
@endsection
