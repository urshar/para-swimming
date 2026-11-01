@extends('layouts.app')

@section('title', 'Version bearbeiten')

@section('content')
    <div class="max-w-2xl">
        <div class="mb-6">
            <div class="flex items-center gap-2">
                <flux:button href="{{ route('wps.versions.show', $version) }}" variant="primary" icon="arrow-left"
                             size="sm" title="Zurück" aria-label="Zurück"/>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Version bearbeiten</h1>
            </div>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">{{ $version->display_name }}</p>
        </div>

        <div class="mb-4 p-4 bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800 rounded-xl text-sm text-blue-700 dark:text-blue-400">
            Ein geänderter Gültigkeitszeitraum kann die automatisch gewählte Version bestehender Wettkämpfe ändern.
            Die WPS-Punkte werden dabei nicht von selbst neu berechnet — betroffene Wettkämpfe zeigt die Detailseite
            der Version nach dem Speichern an.
        </div>

        <form method="POST" action="{{ route('wps.versions.update', $version) }}">
            @csrf
            @method('PUT')

            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4 mb-4">
                <flux:field>
                    <flux:label>Bezeichnung<span class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
                    <flux:input name="label" value="{{ old('label', $version->label) }}" required/>
                    <flux:error name="label"/>
                </flux:field>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Jahr<span class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
                        <flux:input type="number" name="year" value="{{ old('year', $version->year) }}" required/>
                        <flux:error name="year"/>
                    </flux:field>

                    <flux:field>
                        <flux:label>Version</flux:label>
                        <flux:input name="version" value="{{ old('version', $version->version) }}"/>
                        <flux:error name="version"/>
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>Quelle</flux:label>
                    <flux:input name="source" value="{{ old('source', $version->source) }}"
                                placeholder="World Para Swimming Point Scores for Senior Long Course Events"/>
                    <flux:error name="source"/>
                </flux:field>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Gültig ab<span class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
                        <flux:date-picker type="input" locale="de-AT" selectable-header name="valid_from"
                                          value="{{ old('valid_from', $version->valid_from?->format('Y-m-d')) }}"
                                          required/>
                        <flux:error name="valid_from"/>
                    </flux:field>

                    <flux:field>
                        <flux:label>Gültig bis</flux:label>
                        <flux:date-picker type="input" locale="de-AT" selectable-header name="valid_until"
                                          value="{{ old('valid_until', $version->valid_until?->format('Y-m-d')) }}"
                                          clearable/>
                        <flux:description>Leer lassen für "bis auf Weiteres".</flux:description>
                        <flux:error name="valid_until"/>
                    </flux:field>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button href="{{ route('wps.versions.show', $version) }}" variant="ghost">Abbrechen</flux:button>
                <flux:button type="submit" variant="primary">Speichern</flux:button>
            </div>
        </form>
    </div>
@endsection
