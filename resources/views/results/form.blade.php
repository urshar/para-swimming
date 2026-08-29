@extends('layouts.app')

@section('title', isset($result) ? 'Ergebnis bearbeiten' : 'Ergebnis anlegen – ' . $meet->name)

@section('content')
    @php
        use App\Support\TimeParser;

        // Für die automatische Club-Vorbelegung beim Athleten-Wechsel (bleibt änderbar) —
        // nur im Anlegen-Formular relevant, dort ist der Athlet frei wählbar.
        $athleteClubMap = isset($result) ? collect() : $athletes->pluck('club.id', 'id');

        // Einzelne Variablen statt old(...) direkt in @json(): @json() teilt sein Argument
        // naiv an jedem Komma auf (Blade\Compilers\Concerns\CompilesJson::compileJson()) —
        // old("key", "default") hat selbst ein Komma und würde mitten im Ausdruck zerschnitten.
        $oldAthleteId = old('athlete_id', '');
        $oldClubId = old('club_id', '');

        $swimTimeValue = old('swim_time', isset($result) && $result->swim_time ? TimeParser::display($result->swim_time) : '');

        $existingSplits = isset($result)
            ? $result->splits->map(fn ($s) => ['distance' => $s->distance, 'split_time' => $s->split_time])->values()->toArray()
            : [];
    @endphp
    <div class="max-w-4xl">

        {{-- Header --}}
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                {{ isset($result) ? 'Ergebnis bearbeiten' : 'Ergebnis anlegen' }}
            </h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">{{ $meet->name }}</p>
            <div class="mt-4">
                <flux:button href="{{ url()->previous() }}" variant="filled" icon="arrow-left" size="sm">
                    Zurück
                </flux:button>
            </div>
        </div>

        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 p-6">
            <form
                method="POST"
                action="{{ isset($result) ? route('results.update', $result) : route('meets.results.store', $meet) }}"
                x-data='{
                    athleteId: @json($oldAthleteId),
                    clubId: @json($oldClubId),
                    athleteClubMap: @json($athleteClubMap),
                }'
                @if(! isset($result))
                    x-init="$watch('athleteId', id => { if (athleteClubMap[id]) { clubId = String(athleteClubMap[id]); } })"
                @endif
            >
                @csrf
                @if(isset($result))
                    @method('PUT')
                @endif

                <flux:tab.group>
                    <flux:tabs class="mb-4">
                        <flux:tab name="grunddaten">Grunddaten</flux:tab>
                        <flux:tab name="splitzeiten">Splitzeiten</flux:tab>
                    </flux:tabs>

                    <flux:tab.panel name="grunddaten" class="space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <flux:field>
                                <flux:label>Disziplin <span class="text-red-500 dark:text-red-400">*</span></flux:label>
                                <flux:select variant="listbox" name="swim_event_id" required>
                                    @foreach($swimEvents as $event)
                                        <flux:select.option
                                            value="{{ $event->id }}" :selected="old('swim_event_id', $result->swim_event_id ?? '') == $event->id">
                                            {{ $event->display_name }}
                                        </flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:error name="swim_event_id"/>
                            </flux:field>

                            <flux:field>
                                @if(isset($result))
                                    <flux:label>Athlet</flux:label>
                                    <flux:input value="{{ $result->athlete?->display_name }}" disabled/>
                                    <input type="hidden" name="athlete_id" value="{{ $result->athlete_id }}">
                                @else
                                    <flux:label>Athlet <span class="text-red-500 dark:text-red-400">*</span></flux:label>
                                    <flux:select variant="listbox" searchable name="athlete_id" x-model="athleteId" required>
                                        @foreach($athletes as $athlete)
                                            <flux:select.option value="{{ $athlete->id }}" :selected="old('athlete_id') == $athlete->id">
                                                {{ $athlete->display_name }}
                                                {{ $athlete->sport_classes_display ? '– ' . $athlete->sport_classes_display : '' }}
                                                ({{ $athlete->nation?->code }})
                                            </flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    <flux:description class="mt-1">Auch ohne Meldung zu diesem Wettkampf wählbar.</flux:description>
                                    <flux:error name="athlete_id"/>
                                @endif
                            </flux:field>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <flux:field>
                                @if(isset($result))
                                    <flux:label>Club</flux:label>
                                    <flux:input value="{{ $result->club?->display_name }}" disabled/>
                                    <input type="hidden" name="club_id" value="{{ $result->club_id }}">
                                @else
                                    <flux:label>Club <span class="text-red-500 dark:text-red-400">*</span></flux:label>
                                    <flux:select variant="listbox" searchable name="club_id" x-model="clubId" required>
                                        @foreach($clubs as $club)
                                            <flux:select.option
                                                value="{{ $club->id }}" :selected="old('club_id') == $club->id">{{ $club->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    <flux:description class="mt-1">Wird beim Athleten-Wechsel automatisch vorbelegt — bleibt änderbar.</flux:description>
                                    <flux:error name="club_id"/>
                                @endif
                            </flux:field>

                            <flux:field>
                                <flux:label>Sport-Klasse</flux:label>
                                <flux:input name="sport_class" value="{{ old('sport_class', $result->sport_class ?? '') }}"
                                            maxlength="15" placeholder="z.B. S4"/>
                                <flux:error name="sport_class"/>
                            </flux:field>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <flux:field>
                                <flux:label>Schwimmzeit</flux:label>
                                <div x-data='maskedTimeField(@json($swimTimeValue))'>
                                    <flux:input name="swim_time" type="text" x-model="value"
                                                placeholder="00:00.00" autocomplete="off"/>
                                </div>
                                <flux:description class="mt-1">MM:SS.hh — leer lassen ohne Zeit (z.B. bei DNS)</flux:description>
                                <flux:error name="swim_time"/>
                            </flux:field>
                            <flux:field>
                                <flux:label>Status</flux:label>
                                <flux:select variant="listbox" name="status">
                                    <flux:select.option value="" :selected="!old('status', $result->status ?? '')">Gültig</flux:select.option>
                                    <flux:select.option value="DSQ" :selected="old('status', $result->status ?? '') === 'DSQ'">DSQ –
                                        Disqualifiziert
                                    </flux:select.option>
                                    <flux:select.option value="DNS" :selected="old('status', $result->status ?? '') === 'DNS'">DNS – Nicht
                                        angetreten
                                    </flux:select.option>
                                    <flux:select.option value="DNF" :selected="old('status', $result->status ?? '') === 'DNF'">DNF – Nicht
                                        beendet
                                    </flux:select.option>
                                    <flux:select.option value="EXH" :selected="old('status', $result->status ?? '') === 'EXH'">EXH –
                                        Außer Konkurrenz
                                    </flux:select.option>
                                    <flux:select.option value="SICK" :selected="old('status', $result->status ?? '') === 'SICK'">SICK –
                                        Krank
                                    </flux:select.option>
                                    <flux:select.option value="WDR" :selected="old('status', $result->status ?? '') === 'WDR'">WDR –
                                        Zurückgezogen
                                    </flux:select.option>
                                </flux:select>
                                <flux:error name="status"/>
                            </flux:field>
                        </div>

                        <div class="grid grid-cols-3 gap-4">
                            <flux:field>
                                <flux:label>Platz</flux:label>
                                <flux:input name="place" type="number" min="1"
                                            value="{{ old('place', $result->place ?? '') }}"/>
                                <flux:error name="place"/>
                            </flux:field>
                            <flux:field>
                                <flux:label>Lauf</flux:label>
                                <flux:input name="heat" type="number" min="1" value="{{ old('heat', $result->heat ?? '') }}"/>
                                <flux:error name="heat"/>
                            </flux:field>
                            <flux:field>
                                <flux:label>Bahn</flux:label>
                                <flux:input name="lane" type="number" min="0" value="{{ old('lane', $result->lane ?? '') }}"/>
                                <flux:error name="lane"/>
                            </flux:field>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <flux:field>
                                <flux:label>Punkte</flux:label>
                                <flux:input name="points" type="number" min="0"
                                            value="{{ old('points', $result->points ?? '') }}"/>
                                <flux:error name="points"/>
                            </flux:field>
                            <flux:field>
                                <flux:label>Reaktionszeit (Hundertstelsekunden)</flux:label>
                                <flux:input name="reaction_time" type="number"
                                            value="{{ old('reaction_time', $result->reaction_time ?? '') }}"
                                            placeholder="z.B. 14 oder -3"/>
                                <flux:error name="reaction_time"/>
                            </flux:field>
                        </div>

                        <flux:field>
                            <flux:label>Kommentar / DSQ-Grund</flux:label>
                            <flux:input name="comment" value="{{ old('comment', $result->comment ?? '') }}" maxlength="255"/>
                            <flux:error name="comment"/>
                        </flux:field>

                        {{-- Rekord-Flags --}}
                        <div class="pt-2 pb-2">
                            <div class="text-xs font-semibold text-zinc-500 uppercase tracking-wide mb-3">Rekord-Flags</div>
                            <div class="flex gap-6">
                                <flux:switch name="is_world_record" value="1"
                                             :checked="old('is_world_record',    $result->is_world_record    ?? false)"
                                             label="Weltrekord (WR)"/>
                                <flux:switch name="is_european_record" value="1"
                                             :checked="old('is_european_record', $result->is_european_record ?? false)"
                                             label="Europarekord (ER)"/>
                                <flux:switch name="is_national_record" value="1"
                                             :checked="old('is_national_record', $result->is_national_record ?? false)"
                                             label="Nationalrekord (NR)"/>
                            </div>
                        </div>
                    </flux:tab.panel>

                    {{--
                        Splitzeiten — bis zu 10 fixe Zeilen, in einem eigenen Tab statt im
                        durchlaufenden Formular (das machte die Grunddaten-Ansicht unnötig lang).
                        Leere Zeilen werden im Controller ignoriert. Jede Zeit-Spalte bekommt ihre
                        eigene maskedTimeField()-Instanz (resources/js/masked-time-field.js, siehe
                        dort) mit isoliertem Alpine-Scope — kein gemeinsamer Zustand nötig, jede
                        Zeile ist unabhängig.
                    --}}
                    <flux:tab.panel name="splitzeiten">
                        <p class="text-xs text-zinc-400 mb-3">Leere Zeilen werden ignoriert. Kumulierte Zeit ab Start.</p>
                        <div class="grid grid-cols-2 gap-2 text-xs font-medium text-zinc-500 dark:text-zinc-400 px-1 mb-1">
                            <span>Distanz (m)</span>
                            <span>Zeit (MM:SS.hh)</span>
                        </div>
                        @for($i = 0; $i < 10; $i++)
                            @php
                                $splitTimeValue = old(
                                    'splits.'.$i.'.split_time',
                                    isset($existingSplits[$i]) ? TimeParser::display($existingSplits[$i]['split_time']) : ''
                                );
                            @endphp
                            <div class="grid grid-cols-2 gap-2 mb-2">
                                <flux:input
                                    name="splits[{{ $i }}][distance]"
                                    type="number"
                                    min="1"
                                    value="{{ old('splits.'.$i.'.distance', $existingSplits[$i]['distance'] ?? '') }}"
                                    placeholder="{{ ($i + 1) * 50 }}"
                                />
                                <div x-data='maskedTimeField(@json($splitTimeValue))'>
                                    <flux:input name="splits[{{ $i }}][split_time]" type="text" x-model="value"
                                                placeholder="00:00.00" autocomplete="off"/>
                                </div>
                            </div>
                        @endfor
                    </flux:tab.panel>
                </flux:tab.group>

                <div class="flex gap-3 pt-6">
                    <flux:button type="submit" variant="primary">
                        {{ isset($result) ? 'Speichern' : 'Ergebnis anlegen' }}
                    </flux:button>
                    <flux:button href="{{ url()->previous() }}" variant="ghost">Abbrechen</flux:button>
                </div>
            </form>
        </div>
    </div>
@endsection
