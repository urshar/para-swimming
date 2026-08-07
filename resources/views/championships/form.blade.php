@php use App\Models\Championship; @endphp
@extends('layouts.app')

@section('title', $championship ? 'Meisterschaft bearbeiten' : 'Neue Meisterschaft')

@section('content')
    @php
        $typLabels = [
            Championship::TYPE_EC => 'Europameisterschaft',
            Championship::TYPE_WC => 'Weltmeisterschaft',
            Championship::TYPE_PARALYMPICS => 'Paralympics',
            Championship::TYPE_OTHER => 'Sonstige',
        ];
    @endphp

    <div class="max-w-2xl">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100 mb-6">
            {{ $championship ? 'Meisterschaft bearbeiten' : 'Neue Meisterschaft' }}
        </h1>

        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
            <form method="POST"
                  action="{{ $championship ? route('championships.update', $championship) : route('championships.store') }}"
                  class="space-y-4">
                @csrf
                @if($championship)
                    @method('PUT')
                @endif

                <flux:field>
                    <flux:label>Name</flux:label>
                    <flux:input name="name" value="{{ old('name', $championship->name ?? '') }}"
                                placeholder="World Para Swimming European Championships 2026"/>
                    @error('name')
                    <flux:error>{{ $message }}</flux:error>
                    @enderror
                </flux:field>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Kurzname</flux:label>
                        <flux:input name="short_name"
                                    value="{{ old('short_name', $championship->short_name ?? '') }}"
                                    placeholder="EM 2026"/>
                        @error('short_name')
                        <flux:error>{{ $message }}</flux:error>
                        @enderror
                    </flux:field>

                    <flux:field>
                        <flux:label>Jahr</flux:label>
                        <flux:input name="year" type="number"
                                    value="{{ old('year', $championship->year ?? now()->year) }}"/>
                        @error('year')
                        <flux:error>{{ $message }}</flux:error>
                        @enderror
                    </flux:field>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Art</flux:label>
                        <flux:select name="type">
                            @foreach($typLabels as $wert => $label)
                                <option value="{{ $wert }}"
                                        @if(old('type', $championship->type ?? '') === $wert) selected @endif>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </flux:select>
                        @error('type')
                        <flux:error>{{ $message }}</flux:error>
                        @enderror
                    </flux:field>

                    <flux:field>
                        <flux:label>Bahnlänge der Normen</flux:label>
                        <flux:select name="course">
                            @foreach(Championship::COURSES as $wert)
                                <option value="{{ $wert }}"
                                        @if(old('course', $championship->course ?? Championship::COURSE_LCM) === $wert) selected @endif>
                                    {{ $wert }}
                                </option>
                            @endforeach
                        </flux:select>
                        @error('course')
                        <flux:error>{{ $message }}</flux:error>
                        @enderror
                    </flux:field>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Qualifikationszeitraum ab</flux:label>
                        <flux:input name="qualification_start" type="date"
                                    value="{{ old('qualification_start', $championship?->qualification_start?->format('Y-m-d') ?? '') }}"/>
                        @error('qualification_start')
                        <flux:error>{{ $message }}</flux:error>
                        @enderror
                    </flux:field>

                    <flux:field>
                        <flux:label>bis</flux:label>
                        <flux:input name="qualification_end" type="date"
                                    value="{{ old('qualification_end', $championship?->qualification_end?->format('Y-m-d') ?? '') }}"/>
                        @error('qualification_end')
                        <flux:error>{{ $message }}</flux:error>
                        @enderror
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>Herkunft der Normdatei</flux:label>
                    <flux:input name="source" value="{{ old('source', $championship->source ?? '') }}"
                                placeholder="WPS Qualification Standards, Fassung vom …"/>
                    @error('source')
                    <flux:error>{{ $message }}</flux:error>
                    @enderror
                </flux:field>

                <flux:field>
                    <flux:label>Notizen</flux:label>
                    <flux:textarea name="notes" rows="3">{{ old('notes', $championship->notes ?? '') }}</flux:textarea>
                    @error('notes')
                    <flux:error>{{ $message }}</flux:error>
                    @enderror
                </flux:field>

                <flux:checkbox name="is_active" value="1" label="Aktiv"
                               :checked="old('is_active', $championship->is_active ?? true)"/>

                <div class="flex gap-3 pt-2">
                    <flux:button type="submit" variant="primary">
                        {{ $championship ? 'Speichern' : 'Anlegen' }}
                    </flux:button>
                    <flux:button
                        href="{{ $championship ? route('championships.show', $championship) : route('championships.index') }}"
                        variant="ghost">Abbrechen
                    </flux:button>
                </div>
            </form>
        </div>
    </div>
@endsection
