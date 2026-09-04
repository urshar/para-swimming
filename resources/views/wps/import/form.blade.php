@extends('layouts.app')

@section('title', 'WPS Point Scores importieren')

@section('content')
    <div class="max-w-4xl">
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">WPS Point Scores importieren</h1>
            <div class="mt-4">
                <flux:button href="{{ route('wps.versions.index') }}" variant="filled" icon="arrow-left" size="sm">
                    Zurück
                </flux:button>
            </div>
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

            {{-- Nebeneinander statt untereinander (Design-Feedback Erik, 2026-09-04) — "Datei" ist
                 deutlich kürzer als "Version", items-start lässt beide Cards ihre eigene Höhe
                 behalten statt sich künstlich zu strecken. --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start mb-4">
                <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4">
                    <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">Datei</h2>

                    <flux:field x-data="fileUploadField()">
                        <flux:label>WPS Point Scores (.xlsx)<span class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
                        <flux:file-upload name="wps_file" accept=".xlsx" x-on:change="onChange">
                            <flux:file-upload.dropzone heading="Datei hierher ziehen" text="oder klicken zum Auswählen"/>
                        </flux:file-upload>
                        <p x-show="fileName" x-cloak class="mt-1 text-sm text-zinc-600 dark:text-zinc-400" x-text="fileName"></p>
                        <flux:error name="wps_file"/>
                    </flux:field>
                </div>

                <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4">
                    <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">Version</h2>

                    <flux:field>
                        <flux:label>Bezeichnung<span class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
                        <flux:input name="label" value="{{ old('label') }}" placeholder="WPS 2026" required/>
                        <flux:error name="label"/>
                    </flux:field>

                    <div class="grid grid-cols-2 gap-4">
                        <flux:field>
                            <flux:label>Jahr<span class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
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
                            <flux:label>Gültig ab<span class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
                            <flux:date-picker type="input" locale="de-AT" selectable-header name="valid_from"
                                               value="{{ old('valid_from') }}" required/>
                            <flux:error name="valid_from"/>
                        </flux:field>

                        <flux:field>
                            <flux:label>Gültig bis</flux:label>
                            <flux:date-picker type="input" locale="de-AT" selectable-header name="valid_until"
                                               value="{{ old('valid_until') }}" clearable/>
                            <flux:description>Leer lassen für "bis auf Weiteres".</flux:description>
                            <flux:error name="valid_until"/>
                        </flux:field>
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button href="{{ route('wps.versions.index') }}" variant="ghost">Abbrechen</flux:button>
                <flux:button type="submit" variant="primary">Vorschau anzeigen</flux:button>
            </div>
        </form>
    </div>
@endsection
