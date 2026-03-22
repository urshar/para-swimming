@extends('layouts.app')

@section('title', isset($meet) ? 'Wettkampf bearbeiten' : 'Neuer Wettkampf')

@section('content')
    <div class="max-w-2xl">

        {{-- Header --}}
        <div class="flex items-center gap-3 mb-6">
            <flux:button href="{{ route('meets.index') }}" variant="ghost" icon="arrow-left" size="sm"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                {{ isset($meet) ? 'Wettkampf bearbeiten' : 'Neuer Wettkampf' }}
            </h1>
        </div>

        <form method="POST" action="{{ isset($meet) ? route('meets.update', $meet) : route('meets.store') }}">
            @csrf
            @if(isset($meet))
                @method('PUT')
            @endif

            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-5">

                <flux:field>
                    <flux:label>Name *</flux:label>
                    <flux:input name="name" value="{{ old('name', $meet->name ?? '') }}" required/>
                    <flux:error name="name"/>
                </flux:field>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Stadt *</flux:label>
                        <flux:input name="city" value="{{ old('city', $meet->city ?? '') }}" required/>
                        <flux:error name="city"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Nation *</flux:label>
                        <flux:select name="nation_id" required>
                            <option value="">Bitte wählen…</option>
                            @foreach($nations as $nation)
                                <option value="{{ $nation->id }}"
                                    @selected(old('nation_id', $meet->nation_id ?? '') == $nation->id)>
                                    {{ $nation->code }} – {{ $nation->name_de }}
                                </option>
                            @endforeach
                        </flux:select>
                        <flux:error name="nation_id"/>
                    </flux:field>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Startdatum *</flux:label>
                        <flux:input name="start_date" type="date"
                                    value="{{ old('start_date', isset($meet) ? $meet->start_date->format('Y-m-d') : '') }}"
                                    required/>
                        <flux:error name="start_date"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Enddatum</flux:label>
                        <flux:input name="end_date" type="date"
                                    value="{{ old('end_date', isset($meet) && $meet->end_date ? $meet->end_date->format('Y-m-d') : '') }}"/>
                        <flux:error name="end_date"/>
                    </flux:field>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Bahnlänge *</flux:label>
                        <flux:select name="course" required>
                            @foreach(['LCM' => 'LCM (50m)', 'SCM' => 'SCM (25m)', 'SCY' => 'SCY (Yards)', 'OPEN' => 'Freiwasser'] as $val => $label)
                                <option value="{{ $val }}" @selected(old('course', $meet->course ?? 'LCM') === $val)>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </flux:select>
                        <flux:error name="course"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Zeitnahme</flux:label>
                        <flux:select name="timing">
                            <option value="">Nicht angegeben</option>
                            @foreach(['AUTOMATIC' => 'Automatisch', 'SEMIAUTOMATIC' => 'Halbautomatisch', 'MANUAL3' => 'Manuell 3', 'MANUAL2' => 'Manuell 2', 'MANUAL1' => 'Manuell 1'] as $val => $label)
                                <option value="{{ $val }}" @selected(old('timing', $meet->timing ?? '') === $val)>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </flux:select>
                        <flux:error name="timing"/>
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>Veranstalter</flux:label>
                    <flux:input name="organizer" value="{{ old('organizer', $meet->organizer ?? '') }}"/>
                    <flux:error name="organizer"/>
                </flux:field>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Meldetyp</flux:label>
                        <flux:select name="entry_type">
                            <option value="">Nicht angegeben</option>
                            <option value="OPEN" @selected(old('entry_type', $meet->entry_type ?? '') === 'OPEN')>
                                Offen
                            </option>
                            <option
                                value="INVITATION" @selected(old('entry_type', $meet->entry_type ?? '') === 'INVITATION')>
                                Nur Eingeladene
                            </option>
                        </flux:select>
                        <flux:error name="entry_type"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Höhe über Meeresspiegel</flux:label>
                        <flux:input name="altitude" type="number" min="0" max="9000"
                                    value="{{ old('altitude', $meet->altitude ?? 0) }}"/>
                        <flux:error name="altitude"/>
                    </flux:field>
                </div>

                <flux:field>
                    <flux:checkbox name="is_open" value="1" :checked="old('is_open', $meet->is_open ?? false)">
                        Offen für Club-Meldungen
                    </flux:checkbox>
                </flux:field>

            </div>

            <div class="flex gap-3 mt-6">
                <flux:button type="submit" variant="primary">
                    {{ isset($meet) ? 'Speichern' : 'Wettkampf anlegen' }}
                </flux:button>
                <flux:button href="{{ isset($meet) ? route('meets.show', $meet) : route('meets.index') }}"
                             variant="ghost">
                    Abbrechen
                </flux:button>
            </div>
        </form>

    </div>
@endsection
