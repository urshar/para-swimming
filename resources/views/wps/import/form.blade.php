@extends('layouts.app')

@section('title', 'WPS Point Scores importieren')

@section('content')
    <div class="max-w-xl">
        <div class="flex items-center gap-3 mb-6">
            <flux:button href="{{ route('wps.versions.index') }}" variant="ghost" icon="arrow-left" size="sm"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">WPS Point Scores importieren</h1>
        </div>

        @if($errors->any())
            <div class="mb-4 p-4 bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-800 rounded-xl text-sm text-red-700 dark:text-red-400">
                @foreach($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <div class="mb-4 p-4 bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800 rounded-xl text-sm text-blue-700 dark:text-blue-400">
            Erwartet wird die offizielle Datei von World Para Swimming (xlsx) mit dem
            Arbeitsblatt <span class="font-mono">Parameters</span>. Die Datei enthält
            ausschließlich Langbahn-Parameter (LCM); Kurzbahn-Werte werden separat abgeleitet.
        </div>

        <form method="POST" action="{{ route('wps.import.preview') }}" enctype="multipart/form-data">
            @csrf

            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4 mb-4">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">Datei</h2>

                <flux:field>
                    <flux:label>WPS Point Scores (.xlsx) *</flux:label>
                    <input type="file" name="wps_file" accept=".xlsx" required
                           class="block w-full text-sm text-zinc-700 dark:text-zinc-300 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-zinc-100 dark:file:bg-zinc-700 file:text-zinc-700 dark:file:text-zinc-200">
                    <flux:error name="wps_file"/>
                </flux:field>
            </div>

            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4 mb-4">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">Version</h2>

                <flux:field>
                    <flux:label>Bezeichnung *</flux:label>
                    <flux:input name="label" value="{{ old('label') }}" placeholder="WPS 2026" required/>
                    <flux:error name="label"/>
                </flux:field>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Jahr *</flux:label>
                        <flux:input type="number" name="year" value="{{ old('year', now()->year) }}" required/>
                        <flux:error name="year"/>
                    </flux:field>

                    <flux:field>
                        <flux:label>Version</flux:label>
                        <flux:input name="version" value="{{ old('version') }}" placeholder="1"/>
                        <flux:error name="version"/>
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>Quelle</flux:label>
                    <flux:input name="source" value="{{ old('source') }}"
                                placeholder="World Para Swimming Point Scores for Senior Long Course Events"/>
                    <flux:error name="source"/>
                </flux:field>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Gültig ab *</flux:label>
                        <flux:input type="date" name="valid_from" value="{{ old('valid_from') }}" required/>
                        <flux:error name="valid_from"/>
                    </flux:field>

                    <flux:field>
                        <flux:label>Gültig bis</flux:label>
                        <flux:input type="date" name="valid_until" value="{{ old('valid_until') }}"/>
                        <flux:description>Leer lassen für "bis auf Weiteres".</flux:description>
                        <flux:error name="valid_until"/>
                    </flux:field>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button href="{{ route('wps.versions.index') }}" variant="ghost">Abbrechen</flux:button>
                <flux:button type="submit" variant="primary">Vorschau anzeigen</flux:button>
            </div>
        </form>
    </div>
@endsection
