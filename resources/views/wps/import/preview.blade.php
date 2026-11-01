@extends('layouts.app')

@section('title', 'Import-Vorschau')

@section('content')
    <div class="max-w-5xl">
        <div class="mb-6">
            <div class="flex items-center gap-2">
                <flux:button href="{{ route('wps.import') }}" variant="primary" icon="arrow-left"
                             size="sm" title="Zurück" aria-label="Zurück"/>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Import-Vorschau</h1>
                <flux:badge color="zinc" size="sm">{{ $fileName }}</flux:badge>
            </div>
        </div>

        @if($preview->errorCount() > 0)
            <div class="mb-4 p-4 bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-800 rounded-xl">
                <p class="font-medium text-red-700 dark:text-red-400 mb-2">
                    {{ $preview->errorCount() }} Fehler — es wird nichts importiert:
                </p>
                <ul class="text-sm text-red-700 dark:text-red-400 space-y-1 max-h-64 overflow-y-auto">
                    @foreach($preview->errors as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="grid grid-cols-4 gap-4 mb-6">
            @foreach([
                'Parametersätze' => $preview->counts['rows'] ?? 0,
                'Geschlechter' => $preview->counts['genders'] ?? 0,
                'Bewerbe' => $preview->counts['events'] ?? 0,
                'Sportklassen' => $preview->counts['sport_classes'] ?? 0,
            ] as $label => $value)
                <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
                    <div class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $value }}</div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $label }}</div>
                </div>
            @endforeach
        </div>

        {{-- Anzeigenamen der Metadaten-Schlüssel (Excel: version/date/comment, Text: name/short_name/version/id). --}}
        @php
            $metadataLabels = [
                'version' => 'Version',
                'date' => 'Datum',
                'comment' => 'Kommentar',
                'name' => 'Name',
                'short_name' => 'Kurzname',
                'id' => 'Id',
            ];
        @endphp

        @if($preview->metadata !== [])
            <div class="mb-6 p-4 bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-200 dark:border-zinc-700 rounded-xl text-sm text-zinc-600 dark:text-zinc-400">
                <span class="font-medium text-zinc-700 dark:text-zinc-300">Angaben aus der Datei:</span>
                @foreach($preview->metadata as $key => $value)
                    <span class="ml-2">{{ $metadataLabels[$key] ?? $key }}: {{ $value }}</span>
                @endforeach
            </div>
        @endif

        @if($preview->rowCount() > 0)
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden mb-6">
                <div class="px-4 py-3 border-b border-zinc-200 dark:border-zinc-700 text-sm font-medium text-zinc-700 dark:text-zinc-300">
                    Erste Zeilen zur Kontrolle
                </div>
                <div class="p-4 [--flux-bleed:1rem]">
                    <flux:table bleed>
                    <flux:table.columns>
                        <flux:table.column>Geschlecht</flux:table.column>
                        <flux:table.column>Strecke</flux:table.column>
                        <flux:table.column>Klasse</flux:table.column>
                        <flux:table.column align="end">a</flux:table.column>
                        <flux:table.column align="end">b</flux:table.column>
                        <flux:table.column align="end">c</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach(array_slice($preview->rows, 0, 10) as $row)
                            <flux:table.row>
                                <flux:table.cell>{{ $row['gender'] }}</flux:table.cell>
                                <flux:table.cell>{{ $row['distance'] }} m</flux:table.cell>
                                <flux:table.cell>{{ $row['sport_class'] }}</flux:table.cell>
                                <flux:table.cell align="end">{{ $row['parameter_a'] }}</flux:table.cell>
                                <flux:table.cell align="end">{{ $row['parameter_b'] }}</flux:table.cell>
                                <flux:table.cell align="end">{{ $row['parameter_c'] }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                    </flux:table>
                </div>
            </div>
        @endif

        @if($preview->isValid())
            {{-- Versionsdaten erst hier: vorbelegt aus den Angaben der Datei (WpsImportPreview::versionSuggestion()),
                 nach einem Fehler beim Import aus der letzten Eingabe. "Abbrechen" schickt dasselbe Formular per
                 formaction an die Abbrechen-Route — verschachtelte Formulare sind nicht erlaubt. --}}
            <form method="POST" action="{{ route('wps.import.run') }}">
                @csrf

                <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4 mb-4">
                    <div>
                        <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">Version</h2>
                        <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                            Vorschlag aus den Angaben der Datei — bitte prüfen, insbesondere "Gültig ab".
                        </p>
                    </div>

                    <flux:field>
                        <flux:label>Bezeichnung<span class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
                        <flux:input name="label" value="{{ old('label', $suggestion['label']) }}" placeholder="WPS 2026"
                                    required/>
                        <flux:error name="label"/>
                    </flux:field>

                    <div class="grid grid-cols-2 gap-4">
                        <flux:field>
                            <flux:label>Jahr<span class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
                            <flux:input type="number" name="year"
                                        value="{{ old('year', $suggestion['year'] ?? now()->year) }}" required/>
                            <flux:error name="year"/>
                        </flux:field>

                        <flux:field>
                            <flux:label>Version</flux:label>
                            <flux:input name="version" value="{{ old('version', $suggestion['version']) }}"/>
                            <flux:error name="version"/>
                        </flux:field>
                    </div>

                    <flux:field>
                        <flux:label>Quelle</flux:label>
                        <flux:input name="source" value="{{ old('source', $suggestion['source']) }}"/>
                        <flux:error name="source"/>
                    </flux:field>

                    <div class="grid grid-cols-2 gap-4">
                        <flux:field>
                            <flux:label>Gültig ab<span class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
                            <flux:date-picker type="input" locale="de-AT" selectable-header name="valid_from"
                                              value="{{ old('valid_from', $suggestion['valid_from']) }}" required/>
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

                <div class="flex justify-end gap-2">
                    <flux:button type="submit" variant="ghost" formaction="{{ route('wps.import.cancel') }}"
                                 formnovalidate>
                        Abbrechen
                    </flux:button>
                    <flux:button type="submit" variant="primary">
                        {{ $preview->rowCount() }} Parametersätze importieren
                    </flux:button>
                </div>
            </form>
        @else
            <div class="flex justify-end gap-2">
                <form method="POST" action="{{ route('wps.import.cancel') }}">
                    @csrf
                    <flux:button type="submit" variant="ghost">Abbrechen</flux:button>
                </form>
                <flux:button variant="primary" disabled>Import nicht möglich</flux:button>
            </div>
        @endif
    </div>
@endsection
