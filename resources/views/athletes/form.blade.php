@extends('layouts.app')

@section('title', isset($athlete) ? $athlete->display_name . ' bearbeiten' : 'Neuer Athlet')

@section('content')
    <div class="max-w-3xl">

        <div class="flex items-center gap-3 mb-6">
            <flux:button href="{{ route('athletes.index') }}" variant="ghost" icon="arrow-left" size="sm"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                {{ isset($athlete) ? 'Athlet bearbeiten' : 'Neuer Athlet' }}
            </h1>
        </div>

        <form method="POST"
              action="{{ isset($athlete) ? route('athletes.update', $athlete) : route('athletes.store') }}">
            @csrf
            @if(isset($athlete))
                @method('PUT')
            @endif

            {{-- Stammdaten --}}
            <div
                class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4 mb-4">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">Stammdaten</h2>

                <div class="grid grid-cols-3 gap-4">
                    <flux:field>
                        <flux:label>Namenspräfix</flux:label>
                        <flux:input name="name_prefix" value="{{ old('name_prefix', $athlete->name_prefix ?? '') }}"
                                    placeholder="van den…"/>
                        <flux:error name="name_prefix"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Vorname *</flux:label>
                        <flux:input name="first_name" value="{{ old('first_name', $athlete->first_name ?? '') }}"
                                    required/>
                        <flux:error name="first_name"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Nachname *</flux:label>
                        <flux:input name="last_name" value="{{ old('last_name', $athlete->last_name ?? '') }}"
                                    required/>
                        <flux:error name="last_name"/>
                    </flux:field>
                </div>

                <div class="grid grid-cols-3 gap-4">
                    <flux:field>
                        <flux:label>Geschlecht *</flux:label>
                        <flux:select name="gender" required>
                            <option value="M" @selected(old('gender', $athlete->gender ?? 'M') === 'M')>Männlich
                            </option>
                            <option value="F" @selected(old('gender', $athlete->gender ?? '') === 'F')>Weiblich</option>
                            <option value="N" @selected(old('gender', $athlete->gender ?? '') === 'N')>Nicht binär
                            </option>
                        </flux:select>
                        <flux:error name="gender"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Geburtsdatum</flux:label>
                        <flux:input name="birth_date" type="date"
                                    value="{{ old('birth_date', isset($athlete) && $athlete->birth_date ? $athlete->birth_date->format('Y-m-d') : '') }}"/>
                        <flux:error name="birth_date"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Nation *</flux:label>
                        <flux:select name="nation_id" required>
                            <option value="">Bitte wählen…</option>
                            @foreach($nations as $nation)
                                <option
                                    value="{{ $nation->id }}" @selected(old('nation_id', $athlete->nation_id ?? '') == $nation->id)>
                                    {{ $nation->code }} – {{ $nation->name_de }}
                                </option>
                            @endforeach
                        </flux:select>
                        <flux:error name="nation_id"/>
                    </flux:field>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Verein</flux:label>
                        <flux:select name="club_id">
                            <option value="">Kein Verein</option>
                            @foreach($clubs as $club)
                                <option
                                    value="{{ $club->id }}" @selected(old('club_id', $athlete->club_id ?? '') == $club->id)>
                                    {{ $club->display_name }} ({{ $club->nation?->code }})
                                </option>
                            @endforeach
                        </flux:select>
                        <flux:error name="club_id"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Status</flux:label>
                        <flux:select name="status">
                            <option value="">Normal</option>
                            <option
                                value="EXHIBITION" @selected(old('status', $athlete->status ?? '') === 'EXHIBITION')>
                                Exhibition
                            </option>
                            <option value="FOREIGNER" @selected(old('status', $athlete->status ?? '') === 'FOREIGNER')>
                                Ausländer
                            </option>
                            <option value="ROOKIE" @selected(old('status', $athlete->status ?? '') === 'ROOKIE')>
                                Rookie
                            </option>
                        </flux:select>
                        <flux:error name="status"/>
                    </flux:field>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Nationale Lizenznummer</flux:label>
                        <flux:input name="license" value="{{ old('license', $athlete->license ?? '') }}"/>
                        <flux:error name="license"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>SDMS ID (IPC/WPS)</flux:label>
                        <flux:input name="license_ipc" value="{{ old('license_ipc', $athlete->license_ipc ?? '') }}"
                                    placeholder="World Para Swimming ID"/>
                        <flux:error name="license_ipc"/>
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>Behinderungsart</flux:label>
                    <flux:select name="disability_type">
                        <option value="">Nicht angegeben</option>
                        <option
                            value="physical" @selected(old('disability_type', $athlete->disability_type ?? '') === 'physical')>
                            Körperlich
                        </option>
                        <option
                            value="visual" @selected(old('disability_type', $athlete->disability_type ?? '') === 'visual')>
                            Sehbehinderung
                        </option>
                        <option
                            value="intellectual" @selected(old('disability_type', $athlete->disability_type ?? '') === 'intellectual')>
                            Intellektuell
                        </option>
                    </flux:select>
                </flux:field>
            </div>

            {{-- Sport-Klassen --}}
            {{--
                Maximal 3 Sport-Klassen: S, SB, SM — eine fixe Zeile pro Kategorie.
                Alpine.js steuert Sichtbarkeit. Keine x-for Loop → keine PhpStorm Warnungen.
            --}}
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 mb-4">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-4">Sport-Klassen</h2>

                @foreach([['S', 'S (Freistil / Rücken / Schmetterling)'], ['SB', 'SB (Brust)'], ['SM', 'SM (Lagen)']] as [$cat, $label])
                    @php
                        $existing = isset($athlete)
                            ? $athlete->sportClasses->firstWhere('category', $cat)
                            : null;
                        $loop_index = $loop->index;
                    @endphp
                    <div class="flex items-end gap-3 mb-3">
                        <div class="w-48">
                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                                {{ $label }}
                            </label>
                            <input type="hidden"
                                   name="sport_classes[{{ $loop_index }}][category]"
                                   value="{{ $cat }}">
                        </div>
                        <flux:field class="w-28">
                            <flux:label>Klassen-Nr.</flux:label>
                            <flux:input
                                name="sport_classes[{{ $loop_index }}][class_number]"
                                value="{{ old('sport_classes.' . $loop_index . '.class_number', $existing?->class_number ?? '') }}"
                                placeholder="z.B. 4"
                            />
                        </flux:field>
                        <flux:field class="flex-1">
                            <flux:label>Status</flux:label>
                            <flux:select name="sport_classes[{{ $loop_index }}][status]">
                                <option value="">Nicht angegeben</option>
                                @foreach(['CONFIRMED' => 'Bestätigt', 'NATIONAL' => 'Nur national', 'NEW' => 'Neu', 'REVIEW' => 'Überprüfung', 'OBSERVATION' => 'Beobachtung'] as $val => $statusLabel)
                                    <option value="{{ $val }}"
                                        @selected(old('sport_classes.' . $loop_index . '.status', $existing?->status ?? '') === $val)>
                                        {{ $statusLabel }}
                                    </option>
                                @endforeach
                            </flux:select>
                        </flux:field>
                    </div>
                @endforeach
                <flux:description>Klassen-Nr. leer lassen wenn nicht zutreffend.</flux:description>
            </div>

            {{-- Exceptions --}}
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 mb-6">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-4">WPS Exceptions</h2>
                <div class="space-y-2">
                    @foreach($exceptionCodes as $code)
                        @php
                            $existing = isset($athlete) ? $athlete->exceptions->firstWhere('id', $code->id) : null;
                            $checked  = $existing !== null;
                        @endphp
                        <div
                            class="flex items-start gap-3 py-2 border-b border-zinc-100 dark:border-zinc-700 last:border-0">
                            <input type="checkbox"
                                   name="exceptions[{{ $loop->index }}][code_id]"
                                   value="{{ $code->id }}"
                                   id="exc_{{ $code->id }}"
                                   @checked($checked)
                                   class="mt-1 rounded border-zinc-300 dark:border-zinc-600 text-blue-600">
                            <div class="flex-1">
                                <label for="exc_{{ $code->id }}"
                                       class="font-mono font-bold text-sm text-zinc-900 dark:text-zinc-100 cursor-pointer">
                                    {{ $code->code }}
                                </label>
                                <span class="text-sm text-zinc-600 dark:text-zinc-400 ml-2">{{ $code->name_de }}</span>
                                @if($code->applies_to)
                                    <flux:badge size="sm" color="zinc" class="ml-2">{{ $code->applies_to }}</flux:badge>
                                @endif
                            </div>
                            <flux:select name="exceptions[{{ $loop->index }}][category]" class="w-28">
                                <option value="">Allgemein</option>
                                <option value="S" @selected($existing?->pivot?->category === 'S')>S</option>
                                <option value="SB" @selected($existing?->pivot?->category === 'SB')>SB</option>
                                <option value="SM" @selected($existing?->pivot?->category === 'SM')>SM</option>
                            </flux:select>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="flex gap-3">
                <flux:button type="submit" variant="primary">
                    {{ isset($athlete) ? 'Speichern' : 'Athlet anlegen' }}
                </flux:button>
                <flux:button href="{{ isset($athlete) ? route('athletes.show', $athlete) : route('athletes.index') }}"
                             variant="ghost">
                    Abbrechen
                </flux:button>
            </div>

        </form>
    </div>
@endsection
