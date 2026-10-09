@extends('layouts.app')

@section('title', 'Vorschau Team Manager')

@section('content')
    @php
        $kennzahlen = [
            'Vereine neu' => $preview->counts['clubs_new'],
            'Vereine geändert' => $preview->counts['clubs_changed'],
            'Athleten neu' => $preview->counts['athletes_new'],
            'Athleten vorhanden' => $preview->counts['athletes_existing'],
            'davon inaktiv' => $preview->counts['athletes_inactive'],
            'Klassifizierungen' => $preview->counts['classifications'],
            'Sportklassen' => $preview->counts['sport_classes'],
            'Ausnahme-Codes' => $preview->counts['exceptions'],
        ];
    @endphp

    <div class="max-w-4xl">
        <div class="flex items-center gap-2 mb-6">
            <flux:button href="{{ route('team-manager-import') }}" variant="primary"
                         icon="arrow-left" size="sm" title="Zurück" aria-label="Zurück"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Vorschau Team Manager</h1>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
            @foreach($kennzahlen as $label => $wert)
                <div class="p-3 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl">
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $label }}</div>
                    <div class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">{{ $wert }}</div>
                </div>
            @endforeach
        </div>

        @if($preview->counts['athletes_existing'] > 0)
            <div
                class="mb-6 p-4 bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800 rounded-xl text-sm text-blue-700 dark:text-blue-400">
                {{ $preview->counts['athletes_existing'] }} Athleten gibt es schon (gleiche Lizenznummer oder gleicher
                Name und Geburtsdatum). Ihre Stammdaten, Sportklassen und Ausnahme-Codes werden mit den Werten aus der
                Datei überschrieben.
            </div>
        @endif

        <form method="POST" action="{{ route('team-manager-import.run') }}" class="space-y-6">
            @csrf

            @if($preview->unknownClassifiers !== [])
                <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                    <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">
                        Unbekannte Klassifizierer ({{ count($preview->unknownClassifiers) }})
                    </h2>
                    <p class="mt-1 mb-4 text-sm text-zinc-500 dark:text-zinc-400">
                        Diese Namen passen zu keinem angelegten Klassifizierer. Fehlende Klassifizierer
                        <a href="{{ route('classifiers.create') }}" target="_blank"
                           class="text-blue-600 dark:text-blue-400 hover:underline">neu anlegen</a>
                        und danach die
                        <a href="{{ route('team-manager-import.preview') }}"
                           class="text-blue-600 dark:text-blue-400 hover:underline">Vorschau aktualisieren</a>
                        — oder hier einem vorhandenen zuordnen bzw. übergehen.
                    </p>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-xl table-fixed text-sm">
                            <colgroup>
                                <col>
                                <col class="w-28">
                                <col class="w-72">
                            </colgroup>
                            <thead>
                            <tr class="text-left text-zinc-500 dark:text-zinc-400 border-b border-zinc-200 dark:border-zinc-700">
                                <th class="py-2 pe-3 font-medium">Name in der Datei</th>
                                <th class="py-2 pe-3 font-medium text-right">Vorkommen</th>
                                <th class="py-2 font-medium">Zuordnung</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($preview->unknownClassifiers as $key => $unknown)
                                @php($feld = 'classifier_map.'.md5($key))
                                <tr class="border-b border-zinc-100 dark:border-zinc-700/50">
                                    <td class="py-2 pe-3 text-zinc-900 dark:text-zinc-100 truncate"
                                        title="{{ $unknown['name'] }}">{{ $unknown['name'] }}</td>
                                    <td class="py-2 pe-3 text-right tabular-nums text-zinc-600 dark:text-zinc-400">{{ $unknown['count'] }}</td>
                                    <td class="py-2">
                                        <flux:select variant="listbox" searchable
                                                     name="classifier_map[{{ md5($key) }}]"
                                                     placeholder="Bitte wählen"
                                                     aria-label="Zuordnung für {{ $unknown['name'] }}">
                                            <flux:select.option value="{{ $skipValue }}" :selected="old($feld) === $skipValue">Nicht zuordnen</flux:select.option>
                                            @foreach($classifiers as $classifier)
                                                <flux:select.option value="{{ $classifier->id }}" :selected="old($feld) === (string) $classifier->id">{{ $classifier->last_name }} {{ $classifier->first_name }} ({{ $classifier->type }})</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                        <flux:error :name="$feld"/>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            @if($preview->warnings !== [])
                <div
                    class="p-4 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800 rounded-xl">
                    <p class="text-sm font-medium text-amber-700 dark:text-amber-400 mb-2">
                        {{ count($preview->warnings) }} Hinweis(e) — der Import ist trotzdem möglich.
                    </p>
                    <ul class="text-xs text-amber-700 dark:text-amber-400 space-y-1 list-disc list-inside max-h-96 overflow-y-auto">
                        @foreach($preview->warnings as $hinweis)
                            <li>{{ $hinweis }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="flex gap-3">
                <flux:button type="submit" variant="primary">Importieren</flux:button>
                <flux:button href="{{ route('athletes.index') }}" variant="ghost">Abbrechen</flux:button>
            </div>
        </form>
    </div>
@endsection
