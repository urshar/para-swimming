@php use App\Support\SportClassSorter; @endphp
@extends('layouts.app')

@section('title', $list ? "Richtzeiten $list->year bearbeiten" : 'Neue Richtzeitenliste')

@section('content')
    <div class="max-w-4xl">
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                {{ $list ? "Richtzeiten $list->year bearbeiten" : 'Neue Richtzeitenliste' }}
            </h1>
            <div class="mt-4">
                <flux:button href="{{ route('qualifying-time-lists.index') }}" variant="filled" icon="arrow-left" size="sm">
                    Zurück
                </flux:button>
            </div>
        </div>

        @if($errors->any())
            <div
                class="mb-4 p-4 bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-800 rounded-xl text-sm text-red-700 dark:text-red-400">
                @foreach($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        @if(session('success'))
            <div
                class="mb-4 p-4 bg-green-50 dark:bg-green-950/20 border border-green-200 dark:border-green-800 rounded-xl text-sm text-green-700 dark:text-green-400">
                {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div
                class="mb-4 p-4 bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-800 rounded-xl text-sm text-red-700 dark:text-red-400">
                <p>{{ session('error') }}</p>
                @if(str_contains(session('error'), 'kein Meet zugeordnet'))
                    <flux:button href="{{ route('meets.index') }}" variant="filled" size="sm" icon="arrow-right"
                                 class="mt-3">
                        Zu den Wettkämpfen
                    </flux:button>
                @endif
            </div>
        @endif

        @unless($list)
            <form method="POST" action="{{ route('qualifying-time-lists.store') }}">
                @csrf

                <div
                    class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4 mb-4">
                    @include('qualifying-time-lists._general-fields')
                </div>

                <div class="flex gap-3">
                    <flux:button type="submit" variant="primary">Anlegen</flux:button>
                    <flux:button href="{{ route('qualifying-time-lists.index') }}" variant="ghost">
                        Abbrechen
                    </flux:button>
                </div>
            </form>
        @endif

        @if($list)
            @php
                // Filteroptionen für die Richtzeiten-Tabelle: nur tatsächlich vorkommende
                // Werte, aus der bereits geladenen times-Relation abgeleitet (kein
                // zusätzlicher Request nötig — die Filterung selbst läuft rein clientseitig
                // im DOM, siehe resources/js/qualifying-times-filter.js).
                $usedGenders = $list->times->pluck('gender')->unique()->sort()->values();
                $usedSportClasses = $list->times->pluck('sport_class')->unique()
                    ->sortBy(fn ($sc) => SportClassSorter::key($sc))->values();
                $usedStrokeTypes = $list->times->pluck('strokeType')->filter()->unique('id')
                    ->sortBy('name_de')->values();
                $usedDistances = $list->times->pluck('distance')->unique()->sort()->values();
            @endphp

            <flux:tab.group class="mt-6">
                <flux:tabs>
                    <flux:tab name="allgemein">Allgemeine Daten</flux:tab>
                    <flux:tab name="richtzeiten">Richtzeiten</flux:tab>
                    <flux:tab name="verwaltung">Zielpunkte &amp; Qualifikation</flux:tab>
                </flux:tabs>

                {{-- ── Allgemeine Daten ──────────────────────────────────────── --}}
                <flux:tab.panel name="allgemein">
                    <form method="POST" action="{{ route('qualifying-time-lists.update', $list) }}">
                        @csrf
                        @method('PUT')

                        <div
                            class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4 mb-4">
                            @include('qualifying-time-lists._general-fields')
                        </div>

                        <div class="flex gap-3">
                            <flux:button type="submit" variant="primary">Speichern</flux:button>
                            <flux:button href="{{ route('qualifying-time-lists.index') }}" variant="ghost">
                                Abbrechen
                            </flux:button>
                        </div>
                    </form>
                </flux:tab.panel>

                {{-- ── Richtzeiten-Zeilen ─────────────────────────────────────── --}}
                <flux:tab.panel name="richtzeiten">
                    {{-- Manuelles Einfügen — eigene Card oberhalb der Liste, statt im selben
                         Card-Rahmen wie die Liste/der Filter darunter. --}}
                    <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 mb-6">
                        <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-1">Richtzeit manuell hinzufügen</h2>
                        <p class="text-xs text-zinc-400 mb-4">
                            Eine manuelle Eingabe überschreibt eine automatisch berechnete Zeile für denselben Bewerb
                            dauerhaft (bis sie hier wieder gelöscht wird).
                        </p>

                        <form method="POST" action="{{ route('qualifying-time-lists.times.store', $list) }}"
                              class="grid grid-cols-5 gap-3">
                            @csrf
                            <flux:select variant="listbox" name="stroke_type_id" placeholder="Stroke">
                                @foreach($strokeTypes as $stroke)
                                    <flux:select.option value="{{ $stroke->id }}">{{ $stroke->name_de }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:input name="distance" type="number" min="1" placeholder="Distanz (m)"/>
                            <flux:select variant="listbox" name="gender" placeholder="Geschlecht">
                                <flux:select.option value="M">M</flux:select.option>
                                <flux:select.option value="F">F</flux:select.option>
                            </flux:select>
                            <flux:input name="sport_class" placeholder="z.B. S9"/>
                            <div class="flex gap-2">
                                <flux:input name="value" placeholder="01:23.45"/>
                                <flux:button type="submit" variant="primary">OK</flux:button>
                            </div>
                        </form>
                    </div>

                    <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6"
                         x-data="qualifyingTimesFilter()">
                        <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-1">Richtzeiten</h2>
                        <p class="text-xs text-zinc-400 mb-4">
                            Automatisch berechnete Zeilen sind blau markiert, manuell gesetzte/überschriebene amber.
                        </p>

                        @if($list->times->isNotEmpty())
                            {{-- Filter — ohne Filtern-Button, filtert live im DOM (data-rzt-*-Attribute
                                 an den Zeilen/Gruppen/Sektionen unten, siehe qualifying-times-filter.js). --}}
                            <div class="flex flex-wrap items-center gap-3 mb-4 pb-4 border-b border-zinc-100 dark:border-zinc-700">
                                <flux:select variant="listbox" x-model="strokeTypeId" placeholder="Alle Bewerbe"
                                             clearable class="w-48">
                                    @foreach($usedStrokeTypes as $stroke)
                                        <flux:select.option value="{{ $stroke->id }}">{{ $stroke->name_de }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:select variant="listbox" x-model="distance" placeholder="Alle Distanzen"
                                             clearable class="w-36">
                                    @foreach($usedDistances as $distance)
                                        <flux:select.option value="{{ $distance }}">{{ $distance }} m</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:select variant="listbox" x-model="gender" placeholder="Alle" clearable
                                             class="w-28">
                                    @foreach($usedGenders as $gender)
                                        <flux:select.option value="{{ $gender }}">{{ $gender }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:select variant="listbox" x-model="sportClass" placeholder="Alle Klassen"
                                             clearable class="w-40">
                                    @foreach($usedSportClasses as $sportClass)
                                        <flux:select.option value="{{ $sportClass }}">{{ $sportClass }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>
                        @endif

                        @if($list->times->isEmpty())
                            <p class="text-sm text-zinc-400 py-4 text-center">Noch keine Richtzeiten hinterlegt.</p>
                        @else
                            @foreach($sections as $section)
                                <div class="mb-6" data-rzt-section>
                                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 mb-3">
                                        {{ $section['group']?->name_de ?? 'Sonstige Sportklassen' }}
                                    </h3>

                                    @foreach($section['strokes'] as $strokeGroup)
                                        <div class="mb-3" data-rzt-group>
                                            <h4 class="text-xs font-semibold text-zinc-400 dark:text-zinc-500 uppercase tracking-wider mb-1 px-1">
                                                {{ $strokeGroup['distance'].'m '.($strokeGroup['stroke']?->name_de ?? 'Unbekannte Lage') }}
                                            </h4>
                                            <flux:table
                                                class="[&_td:first-child]:ps-0 [&_th:first-child]:ps-0">
                                                <flux:table.columns>
                                                    <flux:table.column>Geschlecht</flux:table.column>
                                                    <flux:table.column>Sportklasse</flux:table.column>
                                                    <flux:table.column>Richtzeit</flux:table.column>
                                                    <flux:table.column>Quelle</flux:table.column>
                                                    <flux:table.column></flux:table.column>
                                                </flux:table.columns>
                                                <flux:table.rows>
                                                    @foreach($strokeGroup['items'] as $time)
                                                        <flux:table.row data-rzt-row
                                                            data-rzt-gender="{{ $time->gender }}"
                                                            data-rzt-sport-class="{{ $time->sport_class }}"
                                                            data-rzt-stroke-id="{{ $time->stroke_type_id }}"
                                                            data-rzt-distance="{{ $time->distance }}">
                                                            <flux:table.cell>{{ $time->gender }}</flux:table.cell>
                                                            <flux:table.cell class="font-mono">{{ $time->sport_class }}</flux:table.cell>
                                                            <flux:table.cell class="font-mono">{{ $time->formatted_value ?? '–' }}</flux:table.cell>
                                                            <flux:table.cell>
                                                                @if($time->isManual())
                                                                    <flux:badge color="amber">Manuell</flux:badge>
                                                                @else
                                                                    <flux:badge color="blue">Berechnet</flux:badge>
                                                                @endif
                                                            </flux:table.cell>
                                                            <flux:table.cell>
                                                                <form method="POST"
                                                                      action="{{ route('qualifying-time-lists.times.destroy', [$list, $time]) }}"
                                                                      onsubmit="return confirm('Richtzeit wirklich löschen?');">
                                                                    @csrf
                                                                    @method('DELETE')
                                                                    <flux:button type="submit" variant="ghost" size="sm"
                                                                                 icon="trash" class="text-red-500!"/>
                                                                </form>
                                                            </flux:table.cell>
                                                        </flux:table.row>
                                                    @endforeach
                                                </flux:table.rows>
                                            </flux:table>
                                        </div>
                                    @endforeach
                                </div>
                            @endforeach
                        @endif
                    </div>
                </flux:tab.panel>

                {{-- ── Zielpunkte + Automatische Berechnung + Qualifikation ──────── --}}
                <flux:tab.panel name="verwaltung">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                            <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-1">Zielpunkte je Sportklasse</h2>
                            <p class="text-xs text-zinc-400 mb-4">
                                Standard: 100 Punkte. Nur abweichende Sportklassen hier eintragen (z.B. S2, SB2, SM2).
                            </p>

                            <form method="POST" action="{{ route('qualifying-time-lists.target-points.store', $list) }}"
                                  class="flex gap-3 mb-4">
                                @csrf
                                <flux:input name="sport_class" placeholder="z.B. S2" class="w-24"/>
                                <flux:input name="points" type="number" min="0" max="2000" placeholder="Punkte" class="w-24"/>
                                <flux:button type="submit" variant="primary">Speichern</flux:button>
                            </form>

                            @if($list->targetPoints->isNotEmpty())
                                <div class="flex flex-wrap gap-2">
                                    @foreach($list->targetPoints->sortBy('sort_key') as $tp)
                                        <span
                                            class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-zinc-100 dark:bg-zinc-700 text-sm text-zinc-800 dark:text-zinc-200">
                                            {{ $tp->sport_class }}: {{ $tp->points }} Pkt.
                                            <form method="POST"
                                                  action="{{ route('qualifying-time-lists.target-points.destroy', [$list, $tp]) }}"
                                                  onsubmit="return confirm('Override für „{{ $tp->sport_class }}“ entfernen?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="text-zinc-400 hover:text-red-500">&times;</button>
                                            </form>
                                        </span>
                                    @endforeach
                                </div>
                            @else
                                <p class="text-sm text-zinc-400">Keine Overrides — für alle Sportklassen gelten 100 Punkte.</p>
                            @endif
                        </div>

                        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                            <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-1">Automatische Berechnung</h2>
                            <p class="text-xs text-zinc-400 mb-4">
                                Berechnet alle Richtzeiten aus den bestehenden Basiswerten und den oben hinterlegten
                                Zielpunkten, für den Kurs und das Datum des dieser Liste zugeordneten ÖSTM & ÖM-Meets.
                                Bewerbe/Sportklassen ohne passenden Basiswert-Eintrag werden übersprungen.
                            </p>
                            <form method="POST" action="{{ route('qualifying-time-lists.calculate', $list) }}"
                                  class="flex flex-col items-start gap-3">
                                @csrf
                                <flux:switch name="overwrite_manual" value="1"
                                             label="Auch manuell gesetzte Zeiten überschreiben"/>
                                <flux:button type="submit" variant="primary" icon="calculator">
                                    Richtzeiten berechnen
                                </flux:button>
                            </form>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 mt-6">
                        <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-1">Qualifikation ermitteln</h2>
                        <p class="text-xs text-zinc-400 mb-4">
                            Ermittelt alle Schwimmer, die im oben eingetragenen Qualifikationszeitraum eine Richtzeit
                            erreicht haben. Eine erneute Berechnung ersetzt die bestehende Liste vollständig.
                        </p>

                        @if(! $list->qualification_period_start)
                            <p class="text-sm text-amber-600 dark:text-amber-400 mb-4">
                                Zeitraum-Beginn ist oben noch nicht gesetzt — bitte zuerst eintragen und speichern.
                            </p>
                        @elseif(! $list->qualification_period_end)
                            <p class="text-sm text-amber-600 dark:text-amber-400 mb-4">
                                Zeitraum: ab {{ $list->qualification_period_start->format('d.m.Y') }} — Ende noch nicht
                                gesetzt. Es wird vorläufig bis heute gerechnet; sobald der ÖSTM & ÖM-Termin feststeht, Ende
                                eintragen und neu berechnen.
                            </p>
                        @else
                            <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-4">
                                Zeitraum: {{ $list->qualification_period_start->format('d.m.Y') }}
                                – {{ $list->qualification_period_end->format('d.m.Y') }}
                            </p>
                        @endif

                        <form method="POST" action="{{ route('qualifying-time-lists.qualifications.calculate', $list) }}"
                              x-data="{ submit() { if (confirm('Qualifikation neu ermitteln? Eine bestehende Liste wird dabei vollständig ersetzt.')) this.$el.submit() } }"
                              @submit.prevent="submit()" class="inline">
                            @csrf
                            <flux:button type="submit" variant="primary" icon="check-badge">
                                Qualifikation ermitteln
                            </flux:button>
                        </form>
                        <flux:button href="{{ route('qualifying-time-lists.qualifications', $list) }}" variant="ghost"
                                     icon="eye">
                            Ergebnisliste anzeigen
                        </flux:button>
                    </div>
                </flux:tab.panel>
            </flux:tab.group>
        @endif
    </div>
@endsection
