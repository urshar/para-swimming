@extends('layouts.app')

@section('title', isset($result) ? 'Ergebnis bearbeiten' : 'Ergebnis anlegen – ' . $meet->name)

@section('content')
    <div class="max-w-2xl">
        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 p-6">
            <form
                method="POST"
                action="{{ isset($result) ? route('results.update', $result) : route('meets.results.store', $meet) }}"
                class="space-y-4"
            >
                @csrf
                @if(isset($result))
                    @method('PUT')
                @endif

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Disziplin</flux:label>
                        <flux:select name="swim_event_id" required>
                            <option value="">Disziplin wählen…</option>
                            @foreach($swimEvents as $event)
                                <option
                                    value="{{ $event->id }}" @selected(old('swim_event_id', $result->swim_event_id ?? '') == $event->id)>
                                    {{ $event->display_name }}
                                </option>
                            @endforeach
                        </flux:select>
                        <flux:error name="swim_event_id"/>
                    </flux:field>

                    <flux:field>
                        <flux:label>Athlet</flux:label>
                        @if(isset($result))
                            <flux:input value="{{ $result->athlete?->display_name }}" disabled/>
                            <input type="hidden" name="athlete_id" value="{{ $result->athlete_id }}">
                        @else
                            <flux:select name="athlete_id" required>
                                <option value="">Athlet wählen…</option>
                                @foreach($entries->pluck('athlete')->unique('id') as $athlete)
                                    @if($athlete)
                                        <option value="{{ $athlete->id }}" @selected(old('athlete_id') == $athlete->id)>
                                            {{ $athlete->display_name }}
                                        </option>
                                    @endif
                                @endforeach
                            </flux:select>
                            <flux:error name="athlete_id"/>
                        @endif
                    </flux:field>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Club</flux:label>
                        @if(isset($result))
                            <flux:input value="{{ $result->club?->display_name }}" disabled/>
                            <input type="hidden" name="club_id" value="{{ $result->club_id }}">
                        @else
                            <flux:select name="club_id" required>
                                <option value="">Club wählen…</option>
                                @foreach($entries->pluck('club')->unique('id') as $club)
                                    @if($club)
                                        <option
                                            value="{{ $club->id }}" @selected(old('club_id') == $club->id)>{{ $club->name }}</option>
                                    @endif
                                @endforeach
                            </flux:select>
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
                        <flux:label>Schwimmzeit (Hundertstelsekunden)</flux:label>
                        <flux:input name="swim_time" type="number" min="0"
                                    value="{{ old('swim_time', $result->swim_time ?? '') }}"
                                    placeholder="z.B. 6245 für 1:02.45"/>
                        <flux:error name="swim_time"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Status</flux:label>
                        <flux:select name="status">
                            <option value="" @selected(!old('status', $result->status ?? ''))>Gültig</option>
                            <option value="DSQ" @selected(old('status', $result->status ?? '') === 'DSQ')>DSQ –
                                Disqualifiziert
                            </option>
                            <option value="DNS" @selected(old('status', $result->status ?? '') === 'DNS')>DNS – Nicht
                                angetreten
                            </option>
                            <option value="DNF" @selected(old('status', $result->status ?? '') === 'DNF')>DNF – Nicht
                                beendet
                            </option>
                            <option value="EXH" @selected(old('status', $result->status ?? '') === 'EXH')>EXH –
                                Ausstellungsstart
                            </option>
                            <option value="SICK" @selected(old('status', $result->status ?? '') === 'SICK')>SICK –
                                Krank
                            </option>
                            <option value="WDR" @selected(old('status', $result->status ?? '') === 'WDR')>WDR –
                                Zurückgezogen
                            </option>
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
                        <flux:checkbox name="is_world_record" value="1"
                                       :checked="old('is_world_record',    $result->is_world_record    ?? false)"
                                       label="Weltrekord (WR)"/>
                        <flux:checkbox name="is_european_record" value="1"
                                       :checked="old('is_european_record', $result->is_european_record ?? false)"
                                       label="Europarekord (ER)"/>
                        <flux:checkbox name="is_national_record" value="1"
                                       :checked="old('is_national_record', $result->is_national_record ?? false)"
                                       label="Nationalrekord (NR)"/>
                    </div>
                </div>

                {{--
                    Splitzeiten — bis zu 10 fixe Zeilen.
                    Leere Zeilen werden im Controller ignoriert.
                    Kein Alpine.js nötig → keine PhpStorm Warnungen.
                --}}
                <div>
                    <div class="text-xs font-semibold text-zinc-500 uppercase tracking-wide mb-3">Splitzeiten</div>
                    <p class="text-xs text-zinc-400 mb-3">Leere Zeilen werden ignoriert. Kumulierte Zeit ab Start in
                        Hundertstelsekunden.</p>
                    <div class="grid grid-cols-2 gap-2 text-xs font-medium text-zinc-500 dark:text-zinc-400 px-1 mb-1">
                        <span>Distanz (m)</span>
                        <span>Zeit (Hundertstel)</span>
                    </div>
                    @php
                        $existingSplits = isset($result)
                            ? $result->splits->map(fn($s) => ['distance' => $s->distance, 'split_time' => $s->split_time])->values()->toArray()
                            : [];
                    @endphp
                    @for($i = 0; $i < 10; $i++)
                        <div class="grid grid-cols-2 gap-2 mb-2">
                            <flux:input
                                name="splits[{{ $i }}][distance]"
                                type="number"
                                min="1"
                                value="{{ old('splits.' . $i . '.distance', $existingSplits[$i]['distance'] ?? '') }}"
                                placeholder="{{ ($i + 1) * 50 }}"
                            />
                            <flux:input
                                name="splits[{{ $i }}][split_time]"
                                type="number"
                                min="0"
                                value="{{ old('splits.' . $i . '.split_time', $existingSplits[$i]['split_time'] ?? '') }}"
                                placeholder="z.B. 2832"
                            />
                        </div>
                    @endfor
                </div>

                <div class="flex gap-3 pt-2">
                    <flux:button type="submit" variant="primary">
                        {{ isset($result) ? 'Speichern' : 'Ergebnis anlegen' }}
                    </flux:button>
                    <flux:button href="{{ route('meets.show', $meet) }}" variant="ghost">Abbrechen</flux:button>
                </div>
            </form>
        </div>
    </div>
@endsection
