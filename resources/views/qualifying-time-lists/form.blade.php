@extends('layouts.app')

@section('title', $list ? "Richtzeiten $list->year bearbeiten" : 'Neue Richtzeitenliste')

@section('content')
    <div class="max-w-3xl">
        <div class="flex items-center gap-3 mb-6">
            <flux:button href="{{ route('qualifying-time-lists.index') }}" variant="ghost" icon="arrow-left" size="sm"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                {{ $list ? "Richtzeiten $list->year bearbeiten" : 'Neue Richtzeitenliste' }}
            </h1>
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
                {{ session('error') }}
            </div>
        @endif

        <form method="POST"
              action="{{ $list ? route('qualifying-time-lists.update', $list) : route('qualifying-time-lists.store') }}">
            @csrf
            @if($list)
                @method('PUT')
            @endif

            <div
                class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4 mb-4">
                <flux:field>
                    <flux:label>Wettkampfjahr *</flux:label>
                    <flux:input name="year" type="number" min="2000" max="2100"
                                value="{{ old('year', $list?->year) }}" required/>
                    <flux:error name="year"/>
                </flux:field>

                <flux:field>
                    <flux:checkbox name="is_active" value="1"
                                   :checked="old('is_active', $list?->is_active ?? true)"
                                   label="Aktiv"/>
                </flux:field>

                <div class="grid grid-cols-2 gap-4 pt-2 border-t border-zinc-100 dark:border-zinc-700">
                    <flux:field>
                        <flux:label>Qualifikationszeitraum — Beginn</flux:label>
                        <flux:input name="qualification_period_start" type="date"
                                    value="{{ old('qualification_period_start', $list?->qualification_period_start?->toDateString()) }}"/>
                        <flux:description>Erster Wettkampftag der vorherigen ÖSTM & ÖM.</flux:description>
                        <flux:error name="qualification_period_start"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Qualifikationszeitraum — Ende</flux:label>
                        <flux:input name="qualification_period_end" type="date"
                                    value="{{ old('qualification_period_end', $list?->qualification_period_end?->toDateString()) }}"/>
                        <flux:description>14 Tage vor dem ersten Wettkampftag dieser ÖSTM & ÖM — kann später
                            nachgetragen/geändert werden, sobald der Termin feststeht.
                        </flux:description>
                        <flux:error name="qualification_period_end"/>
                    </flux:field>
                </div>
            </div>

            <div class="flex gap-3">
                <flux:button type="submit" variant="primary">
                    {{ $list ? 'Speichern' : 'Anlegen' }}
                </flux:button>
                <flux:button href="{{ route('qualifying-time-lists.index') }}" variant="ghost">
                    Abbrechen
                </flux:button>
            </div>
        </form>

        @if($list)
            {{-- ── Zielpunkte ─────────────────────────────────────────────────── --}}
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 mt-6">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-1">Zielpunkte je Sportklasse</h2>
                <p class="text-xs text-zinc-400 mb-4">
                    Standard: 100 Punkte. Nur abweichende Sportklassen hier eintragen (z.B. S2, SB2, SM2).
                </p>

                <form method="POST" action="{{ route('qualifying-time-lists.target-points.store', $list) }}"
                      class="flex gap-3 mb-4">
                    @csrf
                    <flux:input name="sport_class" placeholder="z.B. S2" class="w-32"/>
                    <flux:input name="points" type="number" min="0" max="2000" placeholder="Punkte" class="w-32"/>
                    <flux:button type="submit" variant="primary" size="sm">Speichern</flux:button>
                </form>

                @if($list->targetPoints->isNotEmpty())
                    <div class="flex flex-wrap gap-2">
                        @foreach($list->targetPoints->sortBy('sport_class') as $tp)
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

            {{-- ── Automatische Berechnung ───────────────────────────────────── --}}
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 mt-6">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-1">Automatische Berechnung</h2>
                <p class="text-xs text-zinc-400 mb-4">
                    Berechnet alle Richtzeiten aus den bestehenden Basiswerten und den oben hinterlegten
                    Zielpunkten, für den Kurs und das Datum des dieser Liste zugeordneten ÖSTM & ÖM-Meets.
                    Bewerbe/Sportklassen ohne passenden Basiswert-Eintrag werden übersprungen.
                </p>
                <form method="POST" action="{{ route('qualifying-time-lists.calculate', $list) }}"
                      class="flex items-center gap-4">
                    @csrf
                    <flux:checkbox name="overwrite_manual" value="1"
                                   label="Auch manuell gesetzte Zeiten überschreiben"/>
                    <flux:button type="submit" variant="primary" icon="calculator">
                        Richtzeiten berechnen
                    </flux:button>
                </form>
            </div>

            {{-- ── Richtzeiten-Zeilen ─────────────────────────────────────────── --}}
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 mt-6">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-1">Richtzeiten</h2>
                <p class="text-xs text-zinc-400 mb-4">
                    Automatisch berechnete Zeilen sind blau markiert, manuell gesetzte/überschriebene amber.
                    Eine manuelle Eingabe hier überschreibt eine automatisch berechnete Zeile dauerhaft.
                </p>

                <form method="POST" action="{{ route('qualifying-time-lists.times.store', $list) }}"
                      class="grid grid-cols-5 gap-3 mb-4">
                    @csrf
                    <flux:select name="stroke_type_id" placeholder="Stroke">
                        @foreach($strokeTypes as $stroke)
                            <flux:select.option value="{{ $stroke->id }}">{{ $stroke->name_de }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input name="distance" type="number" min="1" placeholder="Distanz (m)"/>
                    <flux:select name="gender" placeholder="Geschlecht">
                        <flux:select.option value="M">M</flux:select.option>
                        <flux:select.option value="F">F</flux:select.option>
                    </flux:select>
                    <flux:input name="sport_class" placeholder="z.B. S9"/>
                    <div class="flex gap-2">
                        <flux:input name="value" placeholder="01:23.45"/>
                        <flux:button type="submit" variant="primary" size="sm">OK</flux:button>
                    </div>
                </form>

                <flux:table
                    class="[&_td:first-child]:ps-0 [&_th:first-child]:ps-0">
                    <flux:table.columns>
                        <flux:table.column>Bewerb</flux:table.column>
                        <flux:table.column>Geschlecht</flux:table.column>
                        <flux:table.column>Sportklasse</flux:table.column>
                        <flux:table.column>Richtzeit</flux:table.column>
                        <flux:table.column>Quelle</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse($list->times->sortBy(['distance', 'gender', 'sport_class']) as $time)
                            <flux:table.row>
                                <flux:table.cell>{{ $time->distance }}m {{ $time->strokeType?->name_de }}</flux:table.cell>
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
                                        <flux:button type="submit" variant="ghost" size="sm" icon="trash"/>
                                    </form>
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="6">
                                    <p class="text-sm text-zinc-400 py-4 text-center">Noch keine Richtzeiten hinterlegt.</p>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>

            {{-- ── Qualifikationsermittlung ──────────────────────────────────── --}}
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
                      @submit.prevent="submit()">
                    @csrf
                    <flux:button type="submit" variant="primary" icon="check-badge">
                        Qualifikation ermitteln
                    </flux:button>
                </form>
            </div>
        @endif
    </div>
@endsection
