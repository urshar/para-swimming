@extends('layouts.app')

@section('title', 'Rekord eintragen')

@section('content')
    <div class="max-w-2xl">
        <div class="flex items-center gap-3 mb-6">
            <flux:button href="{{ route('records.index') }}" variant="ghost" icon="arrow-left" size="sm"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Rekord manuell eintragen</h1>
        </div>

        <form method="POST" action="{{ route('records.store') }}">
            @csrf

            {{-- Rekord-Klassifizierung --}}
            <div
                class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4 mb-4">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">Rekord-Klassifizierung</h2>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Rekord-Typ *</flux:label>
                        <flux:input name="record_type" value="{{ old('record_type', 'WR') }}"
                                    placeholder="WR, ER, AUT, GER …" required/>
                        <flux:error name="record_type"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Status *</flux:label>
                        <flux:select name="record_status" required>
                            <option value="APPROVED" @selected(old('record_status') === 'APPROVED')>Bestätigt</option>
                            <option value="PENDING" @selected(old('record_status') === 'PENDING')>Ausstehend</option>
                            <option value="TARGETTIME" @selected(old('record_status') === 'TARGETTIME')>Zielzeit
                            </option>
                        </flux:select>
                    </flux:field>
                </div>

                <div class="grid grid-cols-3 gap-4">
                    <flux:field>
                        <flux:label>Sport-Klasse *</flux:label>
                        <flux:input name="sport_class" value="{{ old('sport_class') }}" placeholder="S4, SB3, SM14 …"
                                    required/>
                        <flux:error name="sport_class"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Geschlecht *</flux:label>
                        <flux:select name="gender" required>
                            <option value="M" @selected(old('gender') === 'M')>Herren</option>
                            <option value="F" @selected(old('gender') === 'F')>Damen</option>
                            <option value="X" @selected(old('gender') === 'X')>Mixed</option>
                        </flux:select>
                    </flux:field>
                    <flux:field>
                        <flux:label>Bahn *</flux:label>
                        <flux:select name="course" required>
                            <option value="LCM" @selected(old('course', 'LCM') === 'LCM')>LCM (50m)</option>
                            <option value="SCM" @selected(old('course') === 'SCM')>SCM (25m)</option>
                            <option value="SCY" @selected(old('course') === 'SCY')>SCY (Yards)</option>
                        </flux:select>
                    </flux:field>
                </div>

                <div class="grid grid-cols-3 gap-4">
                    <flux:field>
                        <flux:label>Disziplin *</flux:label>
                        <flux:select name="stroke_type_id" required>
                            <option value="">Wählen…</option>
                            @foreach($strokeTypes as $stroke)
                                <option value="{{ $stroke->id }}" @selected(old('stroke_type_id') == $stroke->id)>
                                    {{ $stroke->name_de }}
                                </option>
                            @endforeach
                        </flux:select>
                        <flux:error name="stroke_type_id"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Distanz (m) *</flux:label>
                        <flux:input name="distance" type="number" min="1" value="{{ old('distance') }}" required/>
                        <flux:error name="distance"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Staffel (Schwimmer)</flux:label>
                        <flux:input name="relay_count" type="number" min="1" value="{{ old('relay_count', 1) }}"/>
                    </flux:field>
                </div>
            </div>

            {{-- Leistung --}}
            <div
                class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4 mb-4">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">Leistung</h2>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Schwimmzeit (Hundertstel) *</flux:label>
                        <flux:input name="swim_time" type="number" min="0" value="{{ old('swim_time') }}"
                                    placeholder="z.B. 6532 = 1:05.32" required/>
                        <flux:description>Hundertstelsekunden: 1:05.32 = 6532</flux:description>
                        <flux:error name="swim_time"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Athlet</flux:label>
                        <flux:select name="athlete_id">
                            <option value="">Kein Athlet</option>
                        </flux:select>
                    </flux:field>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Nation</flux:label>
                        <flux:select name="nation_id">
                            <option value="">Keine Nation</option>
                            @foreach($nations as $nation)
                                <option value="{{ $nation->id }}" @selected(old('nation_id') == $nation->id)>
                                    {{ $nation->code }} – {{ $nation->name_de }}
                                </option>
                            @endforeach
                        </flux:select>
                    </flux:field>
                    <flux:field>
                        <flux:label>Datum</flux:label>
                        <flux:input name="set_date" type="date" value="{{ old('set_date') }}"/>
                    </flux:field>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Wettkampf</flux:label>
                        <flux:input name="meet_name" value="{{ old('meet_name') }}"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Ort</flux:label>
                        <flux:input name="meet_city" value="{{ old('meet_city') }}"/>
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>Anmerkung</flux:label>
                    <flux:input name="comment" value="{{ old('comment') }}"/>
                </flux:field>
            </div>

            {{--
                Splitzeiten — bis zu 10 fixe Zeilen.
                Leere Felder werden im Controller ignoriert.
                Kein Alpine.js / x-for nötig → keine PhpStorm Warnungen.
            --}}
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 mb-6">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-1">Splitzeiten</h2>
                <p class="text-xs text-zinc-400 mb-4">Leere Zeilen werden ignoriert. Kumulierte Zeit ab Start in
                    Hundertstelsekunden.</p>

                <div class="space-y-2">
                    <div class="grid grid-cols-2 gap-3 text-xs font-medium text-zinc-500 dark:text-zinc-400 px-1 mb-1">
                        <span>Distanz (m)</span>
                        <span>Zeit (Hundertstel)</span>
                    </div>
                    @for($i = 0; $i < 10; $i++)
                        <div class="grid grid-cols-2 gap-3">
                            <flux:input
                                name="splits[{{ $i }}][distance]"
                                type="number"
                                min="1"
                                value="{{ old('splits.' . $i . '.distance') }}"
                                placeholder="{{ ($i + 1) * 50 }}"
                            />
                            <flux:input
                                name="splits[{{ $i }}][split_time]"
                                type="number"
                                min="0"
                                value="{{ old('splits.' . $i . '.split_time') }}"
                                placeholder="z.B. 2832"
                            />
                        </div>
                    @endfor
                </div>
            </div>

            <div class="flex gap-3">
                <flux:button type="submit" variant="primary">Rekord eintragen</flux:button>
                <flux:button href="{{ route('records.index') }}" variant="ghost">Abbrechen</flux:button>
            </div>
        </form>
    </div>
@endsection
