@php
    use App\Models\Club;
    use App\Support\TimeParser;
@endphp

@extends('layouts.app')

@section('title', isset($record) ? 'Rekord bearbeiten' : 'Rekord eintragen')

@php
    $rec = $record ?? null;

    // Alle möglichen Rekord-Typen gruppiert
    $recordTypeGroups = [
        'International' => [
            'WR' => 'WR – Weltrekord',
            'ER' => 'ER – Europarekord',
            'OR' => 'OR – Olympischer Rekord',
        ],
        'National (Österreich)' => [
            'AUT'    => 'AUT – Österreich (gesamt)',
            'AUT.JR' => 'AUT.JR – Österreich Jugend',
        ],
        'Regional (Österreich)' => collect(Club::REGIONAL_ASSOCIATIONS)
            ->flatMap(fn($name, $code) => [
                "AUT.$code"    => "AUT.$code – $name",
                "AUT.$code.JR" => "AUT.$code.JR – $name (Jugend)",
            ])->toArray(),
    ];
@endphp

@section('content')
    <div class="max-w-2xl">
        <div class="flex items-center gap-3 mb-6">
            <flux:button href="{{ route('records.index') }}" variant="ghost" icon="arrow-left" size="sm"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                {{ $rec ? 'Rekord bearbeiten' : 'Rekord manuell eintragen' }}
            </h1>
        </div>

        <form method="POST" action="{{ $rec ? route('records.update', $rec) : route('records.store') }}">
            @csrf
            @if($rec)
                @method('PUT')
            @endif

            {{-- Rekord-Klassifizierung --}}
            <div
                class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4 mb-4">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">Rekord-Klassifizierung</h2>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Rekord-Typ *</flux:label>
                        <flux:select name="record_type" required>
                            @foreach($recordTypeGroups as $groupLabel => $types)
                                <optgroup label="{{ $groupLabel }}">
                                    @foreach($types as $val => $label)
                                        <option value="{{ $val }}"
                                            @selected(old('record_type', $rec->record_type ?? 'AUT') === $val)>
                                            {{ $label }}
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </flux:select>
                        <flux:error name="record_type"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Status *</flux:label>
                        <flux:select name="record_status" required>
                            @foreach(['APPROVED' => 'Bestätigt', 'PENDING' => 'Ausstehend', 'TARGETTIME' => 'Zielzeit'] as $val => $label)
                                <option value="{{ $val }}"
                                    @selected(old('record_status', $rec->record_status ?? 'APPROVED') === $val)>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </flux:select>
                    </flux:field>
                </div>

                <div class="grid grid-cols-3 gap-4">
                    <flux:field>
                        <flux:label>Sport-Klasse *</flux:label>
                        <flux:input name="sport_class"
                                    value="{{ old('sport_class', $rec->sport_class ?? '') }}"
                                    placeholder="S4, SB3, SM14 …" required/>
                        <flux:error name="sport_class"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Geschlecht *</flux:label>
                        <flux:select name="gender" required>
                            @foreach(['M' => 'Herren', 'F' => 'Damen', 'X' => 'Mixed'] as $val => $label)
                                <option value="{{ $val }}"
                                    @selected(old('gender', $rec->gender ?? 'M') === $val)>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </flux:select>
                    </flux:field>
                    <flux:field>
                        <flux:label>Bahn *</flux:label>
                        <flux:select name="course" required>
                            @foreach(['LCM' => 'LCM (50m)', 'SCM' => 'SCM (25m)', 'SCY' => 'SCY (Yards)'] as $val => $label)
                                <option value="{{ $val }}"
                                    @selected(old('course', $rec->course ?? 'LCM') === $val)>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </flux:select>
                    </flux:field>
                </div>

                <div class="grid grid-cols-3 gap-4">
                    <flux:field>
                        <flux:label>Disziplin *</flux:label>
                        <flux:select name="stroke_type_id" required>
                            <option value="">Wählen…</option>
                            @foreach($strokeTypes as $stroke)
                                <option value="{{ $stroke->id }}"
                                    @selected(old('stroke_type_id', $rec->stroke_type_id ?? '') == $stroke->id)>
                                    {{ $stroke->name_de }}
                                </option>
                            @endforeach
                        </flux:select>
                        <flux:error name="stroke_type_id"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Distanz (m) *</flux:label>
                        <flux:input name="distance" type="number" min="1"
                                    value="{{ old('distance', $rec->distance ?? '') }}" required/>
                        <flux:error name="distance"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Staffel (Schwimmer)</flux:label>
                        <flux:input name="relay_count" type="number" min="1"
                                    value="{{ old('relay_count', $rec->relay_count ?? 1) }}"/>
                    </flux:field>
                </div>
            </div>

            {{-- Leistung --}}
            <div
                class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4 mb-4">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">Leistung</h2>

                <div class="grid grid-cols-2 gap-4">
                    {{--
                        Zeitmaske via IMask.js
                        Format: MM:SS.cs → z.B. 01:05.32
                        x-init initialisiert IMask sobald das Element im DOM ist.
                        lazy: false → Platzhalter wird immer angezeigt.
                    --}}
                    <flux:field>
                        <flux:label>Schwimmzeit *</flux:label>
                        <flux:input
                            name="swim_time"
                            type="text"
                            value="{{ old('swim_time', $rec ? TimeParser::display($rec->swim_time) : '') }}"
                            placeholder="00:00.00"
                            required
                            x-data
                            x-init="
                                IMask($el, {
                                    mask: '00:00.00',
                                    lazy: false,
                                    placeholderChar: '0'
                                })
                            "
                        />
                        <flux:description>Format: MM:SS.cs — z.B. 01:05.32</flux:description>
                        <flux:error name="swim_time"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Athlet</flux:label>
                        <flux:select name="athlete_id">
                            <option value="">Kein Athlet</option>
                            @foreach($athletes as $athlete)
                                <option value="{{ $athlete->id }}"
                                    @selected(old('athlete_id', $rec->athlete_id ?? '') == $athlete->id)>
                                    {{ $athlete->last_name }} {{ $athlete->first_name }}
                                    @if($athlete->club)
                                        ({{ $athlete->club->short_name ?? $athlete->club->name }})
                                    @endif
                                </option>
                            @endforeach
                        </flux:select>
                    </flux:field>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Nation</flux:label>
                        <flux:select name="nation_id">
                            <option value="">Keine Nation</option>
                            @foreach($nations as $nation)
                                <option value="{{ $nation->id }}"
                                    @selected(old('nation_id', $rec->nation_id ?? '') == $nation->id)>
                                    {{ $nation->code }} – {{ $nation->name_de }}
                                </option>
                            @endforeach
                        </flux:select>
                    </flux:field>
                    <flux:field>
                        <flux:label>Datum</flux:label>
                        <flux:input name="set_date" type="date"
                                    value="{{ old('set_date', $rec?->set_date?->format('Y-m-d') ?? '') }}"/>
                    </flux:field>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Wettkampf</flux:label>
                        <flux:input name="meet_name" value="{{ old('meet_name', $rec->meet_name ?? '') }}"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Ort</flux:label>
                        <flux:input name="meet_city" value="{{ old('meet_city', $rec->meet_city ?? '') }}"/>
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>Anmerkung</flux:label>
                    <flux:input name="comment" value="{{ old('comment', $rec->comment ?? '') }}"/>
                </flux:field>
            </div>

            {{-- Splitzeiten --}}
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 mb-6">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-1">Splitzeiten</h2>
                <p class="text-xs text-zinc-400 mb-4">Leere Zeilen werden ignoriert. Kumulierte Zeit ab Start.</p>

                <div class="space-y-2">
                    <div class="grid grid-cols-2 gap-3 text-xs font-medium text-zinc-500 dark:text-zinc-400 px-1 mb-1">
                        <span>Distanz (m)</span>
                        <span>Zeit (MM:SS.cs)</span>
                    </div>
                    @for($i = 0; $i < 10; $i++)
                        <div class="grid grid-cols-2 gap-3">
                            <flux:input
                                name="splits[{{ $i }}][distance]"
                                type="number"
                                min="1"
                                value="{{ old('splits.' . $i . '.distance', $rec?->splits[$i]->distance ?? '') }}"
                                placeholder="{{ ($i + 1) * 50 }}"
                            />
                            <flux:input
                                name="splits[{{ $i }}][split_time]"
                                type="text"
                                value="{{ old('splits.' . $i . '.split_time', isset($rec->splits[$i]) ? TimeParser::display($rec->splits[$i]->split_time) : '') }}"
                                placeholder="00:00.00"
                                x-data
                                x-init="
                                    IMask($el, {
                                        mask: '00:00.00',
                                        lazy: false,
                                        placeholderChar: '0'
                                    })
                                "
                            />
                        </div>
                    @endfor
                </div>
            </div>

            <div class="flex gap-3">
                <flux:button type="submit" variant="primary">
                    {{ $rec ? 'Änderungen speichern' : 'Rekord eintragen' }}
                </flux:button>
                <flux:button
                    href="{{ $rec ? route('records.show', $rec) : route('records.index') }}"
                    variant="ghost">
                    Abbrechen
                </flux:button>
            </div>
        </form>
    </div>
@endsection
