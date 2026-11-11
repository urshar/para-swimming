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
        <div class="flex items-center gap-2 mb-6">
            <flux:button href="{{ $championship ? route('championships.show', $championship) : route('championships.index') }}"
                         variant="primary" icon="arrow-left" size="sm" title="Zurück" aria-label="Zurück"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                {{ $championship ? 'Meisterschaft bearbeiten' : 'Neue Meisterschaft' }}
            </h1>
        </div>

        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
            <form method="POST"
                  action="{{ $championship ? route('championships.update', $championship) : route('championships.store') }}"
                  class="space-y-4">
                @csrf
                @if($championship)
                    @method('PUT')
                @endif

                <flux:field>
                    <flux:label>Name <span class="text-red-500 dark:text-red-400">*</span></flux:label>
                    <flux:input name="name" value="{{ old('name', $championship->name ?? '') }}" required
                                placeholder="World Para Swimming European Championships 2026"/>
                    <flux:error name="name"/>
                </flux:field>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Kurzname</flux:label>
                        <flux:input name="short_name"
                                    value="{{ old('short_name', $championship->short_name ?? '') }}"
                                    placeholder="EM 2026"/>
                        <flux:error name="short_name"/>
                    </flux:field>

                    <flux:field>
                        <flux:label>Jahr <span class="text-red-500 dark:text-red-400">*</span></flux:label>
                        <flux:input name="year" type="text" inputmode="numeric" maxlength="4" required
                                    value="{{ old('year', $championship->year ?? now()->year) }}"/>
                        <flux:error name="year"/>
                    </flux:field>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Art <span class="text-red-500 dark:text-red-400">*</span></flux:label>
                        <flux:select variant="listbox" name="type" placeholder="Wählen…" required>
                            @foreach($typLabels as $wert => $label)
                                <flux:select.option value="{{ $wert }}" :selected="old('type', $championship->type ?? '') === $wert">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="type"/>
                    </flux:field>

                    <flux:field>
                        <flux:label>Bahnlänge der Normen <span class="text-red-500 dark:text-red-400">*</span></flux:label>
                        <flux:select variant="listbox" name="course" required>
                            @foreach(Championship::COURSES as $wert)
                                <flux:select.option value="{{ $wert }}" :selected="old('course', $championship->course ?? Championship::COURSE_LCM) === $wert">{{ $wert }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="course"/>
                    </flux:field>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Qualifikationszeitraum ab <span class="text-red-500 dark:text-red-400">*</span></flux:label>
                        <flux:date-picker type="input" locale="de-AT" selectable-header name="qualification_start"
                                    value="{{ old('qualification_start', $championship?->qualification_start?->format('Y-m-d') ?? '') }}" clearable/>
                        <flux:error name="qualification_start"/>
                    </flux:field>

                    <flux:field>
                        <flux:label>bis <span class="text-red-500 dark:text-red-400">*</span></flux:label>
                        <flux:date-picker type="input" locale="de-AT" selectable-header name="qualification_end"
                                    value="{{ old('qualification_end', $championship?->qualification_end?->format('Y-m-d') ?? '') }}" clearable/>
                        <flux:error name="qualification_end"/>
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>Herkunft der Normdatei</flux:label>
                    <flux:input name="source" value="{{ old('source', $championship->source ?? '') }}"
                                placeholder="WPS Qualification Standards, Fassung vom …"/>
                    <flux:error name="source"/>
                </flux:field>

                <flux:field>
                    <flux:label>Notizen</flux:label>
                    <flux:textarea name="notes" rows="3">{{ old('notes', $championship->notes ?? '') }}</flux:textarea>
                    <flux:error name="notes"/>
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
