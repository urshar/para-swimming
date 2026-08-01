@extends('layouts.app')

@section('title', 'Import-Vorschau')

@section('content')
    <div class="max-w-4xl">
        <div class="flex items-center gap-3 mb-6">
            <flux:button href="{{ route('wps.import') }}" variant="ghost" icon="arrow-left" size="sm"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Import-Vorschau</h1>
        </div>

        <div class="mb-4 text-sm text-zinc-600 dark:text-zinc-400">
            Datei: <span class="font-mono">{{ $fileName }}</span> —
            Zielversion: <span class="font-medium">{{ $version['label'] }}</span>
            {{-- Kein @if direkt vor der schließenden Klammer: eine Blade-Direktive,
                 der unmittelbar ')' folgt, wird als Direktiven-Parameter gelesen. --}}
            ({{ $version['year'] }}{{ $version['version'] ? ', Version '.$version['version'] : '' }})
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

        @if($preview->metadata !== [])
            <div class="mb-6 p-4 bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-200 dark:border-zinc-700 rounded-xl text-sm text-zinc-600 dark:text-zinc-400">
                <span class="font-medium text-zinc-700 dark:text-zinc-300">Angaben aus der Datei:</span>
                @foreach($preview->metadata as $key => $value)
                    <span class="ml-2">{{ $key }}: {{ $value }}</span>
                @endforeach
            </div>
        @endif

        @if($preview->rowCount() > 0)
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden mb-6">
                <div class="px-4 py-3 border-b border-zinc-200 dark:border-zinc-700 text-sm font-medium text-zinc-700 dark:text-zinc-300">
                    Erste Zeilen zur Kontrolle
                </div>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Geschlecht</flux:table.column>
                        <flux:table.column>Strecke</flux:table.column>
                        <flux:table.column>Klasse</flux:table.column>
                        <flux:table.column align="end">a</flux:table.column>
                        <flux:table.column align="end">b</flux:table.column>
                        <flux:table.column align="end">c</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows class="[&_td:first-child]:ps-4">
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
        @endif

        <div class="flex justify-end gap-2">
            <form method="POST" action="{{ route('wps.import.cancel') }}">
                @csrf
                <flux:button type="submit" variant="ghost">Abbrechen</flux:button>
            </form>

            @if($preview->isValid())
                <form method="POST" action="{{ route('wps.import.run') }}">
                    @csrf
                    <flux:button type="submit" variant="primary">
                        {{ $preview->rowCount() }} Parametersätze importieren
                    </flux:button>
                </form>
            @else
                <flux:button variant="primary" disabled>Import nicht möglich</flux:button>
            @endif
        </div>
    </div>
@endsection
