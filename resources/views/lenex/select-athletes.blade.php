@php use App\Services\LenexResolverService; use Carbon\Carbon; @endphp
@extends('layouts.app')

@section('title', 'LENEX Import — Athleten auswählen')

@section('content')
    @php
        $matchLabels = [
            LenexResolverService::MATCH_LICENSE => 'Lizenz',
            LenexResolverService::MATCH_LICENSE_IPC => 'SDMS-ID',
            LenexResolverService::MATCH_NAME => 'Name + Geburtsdatum',
        ];
    @endphp
    <div class="max-w-4xl">
        <div class="mb-6">
            <div class="flex items-center gap-2">
                <flux:button href="{{ route('lenex.import') }}" variant="primary" icon="arrow-left" size="sm"
                             title="Zurück" aria-label="Zurück"/>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Athleten auswählen</h1>
            </div>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                Nur die Einzelergebnisse der angehakten Athleten werden importiert. Staffeln, übrige Schwimmer und
                deren Vereine bleiben draußen.
            </p>
        </div>

        {{-- Datei und Ziel-Veranstaltung --}}
        <div class="bg-zinc-50 dark:bg-zinc-800/60 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 mb-5">
            <p class="text-xs font-semibold text-zinc-400 uppercase tracking-wider mb-2">Erkannt in der LENEX-Datei</p>
            <p class="font-medium text-zinc-900 dark:text-zinc-100">{{ $meta['name'] }}</p>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                {{ $meta['city'] ?? '–' }}
                @if($meta['start_date'] ?? null)
                    · {{ Carbon::parse($meta['start_date'])->format('d.m.Y') }}
                @endif
                · {{ $meet ? 'Import in "' . $meet->name . '"' : 'wird als neuer Wettkampf angelegt' }}
            </p>
        </div>

        @if($matches === [] && $candidates === [])
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 mb-6">
                <p class="font-medium text-zinc-900 dark:text-zinc-100">Keine bekannten Athleten in der Datei</p>
                <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">
                    Kein Schwimmer mit Einzelergebnis wurde in der Datenbank gefunden, und keiner hat einen Para-Hinweis
                    (Sportklasse) in der Datei.
                </p>
            </div>
            <flux:button href="{{ route('lenex.import') }}" variant="ghost">Abbrechen</flux:button>
        @else
            <form method="POST" action="{{ route('lenex.import.apply-athlete-selection') }}">
                @csrf
                <input type="hidden" name="import_session" value="{{ $importSession }}">

                {{-- Treffer: standardmäßig angehakt --}}
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100 mb-1">
                    In der Datenbank gefunden ({{ count($matches) }})
                </h2>
                <p class="text-xs text-zinc-500 dark:text-zinc-400 mb-3">
                    Gefunden über Lizenz, SDMS-ID oder Name mit Geburtsdatum — dieselben Regeln wie beim Import.
                    Treffer nur über den Namen bitte kurz prüfen.
                </p>
                @if($matches === [])
                    <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-6">Keine.</p>
                @else
                    <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 divide-y divide-zinc-100 dark:divide-zinc-700 mb-6">
                        @foreach($matches as $match)
                            <label class="p-4 flex items-start gap-3 cursor-pointer">
                                <input type="checkbox" name="athletes[]" value="{{ $match['lenex_id'] }}" checked
                                       class="mt-1 rounded border-zinc-300 dark:border-zinc-600 dark:bg-zinc-700">
                                <span class="flex-1 min-w-0">
                                    <span class="flex flex-wrap items-center gap-2">
                                        <span class="font-medium text-zinc-900 dark:text-zinc-100">
                                            {{ $match['athlete_name'] }}{{ $match['birth_year'] ? ' (' . $match['birth_year'] . ')' : '' }}
                                        </span>
                                        <flux:badge size="sm" :color="$match['matched_by'] === LenexResolverService::MATCH_NAME ? 'amber' : 'zinc'">
                                            {{ $matchLabels[$match['matched_by']] ?? $match['matched_by'] }}
                                        </flux:badge>
                                    </span>
                                    <span class="block text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
                                        Laut Datei: {{ $match['file_name'] }} · {{ $match['file_club'] ?: 'ohne Verein' }}
                                        · {{ $match['file_nation'] ?: '–' }}
                                    </span>
                                    <span class="block text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
                                        {{ count($match['events']) }} {{ count($match['events']) === 1 ? 'Start' : 'Starts' }}:
                                        {{ implode(', ', $match['events']) }}
                                    </span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                @endif

                {{-- Kandidaten ohne Treffer: eingeklappt, nicht angehakt --}}
                @if($candidates !== [])
                    <details class="mb-6 group">
                        <summary class="cursor-pointer text-lg font-semibold text-zinc-900 dark:text-zinc-100 mb-1">
                            Kandidaten ohne Treffer ({{ count($candidates) }})
                        </summary>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400 mb-3">
                            Nicht in der Datenbank gefunden, aber mit Para-Hinweis in der Datei (Sportklasse oder Start in
                            einem Bewerb mit Klassen). Angehakte kommen im nächsten Schritt auf die Klärungsseite: neu
                            anlegen oder einem bestehenden Athleten zuordnen. Ein neu angelegter Athlet bekommt den Verein
                            aus der Datei nur, wenn es ihn schon gibt — sonst werden seine Ergebnisse übersprungen, bis ein
                            Heimverein eingetragen ist und die Datei erneut importiert wird.
                        </p>
                        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 divide-y divide-zinc-100 dark:divide-zinc-700">
                            @foreach($candidates as $candidate)
                                <label class="p-4 flex items-start gap-3 cursor-pointer">
                                    <input type="checkbox" name="candidates[]" value="{{ $candidate['lenex_id'] }}"
                                           class="mt-1 rounded border-zinc-300 dark:border-zinc-600 dark:bg-zinc-700">
                                    <span class="flex-1 min-w-0">
                                        <span class="block font-medium text-zinc-900 dark:text-zinc-100">
                                            {{ $candidate['file_name'] }}
                                            @if($candidate['birth_date'] !== '')
                                                (*{{ Carbon::parse($candidate['birth_date'])->format('d.m.Y') }})
                                            @endif
                                        </span>
                                        <span class="block text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
                                            {{ $candidate['file_club'] ?: 'ohne Verein' }} · {{ $candidate['file_nation'] ?: '–' }}
                                            · {{ implode(', ', $candidate['events']) }}
                                        </span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </details>
                @endif

                {{-- Einstellungen --}}
                <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 mb-6 space-y-4">
                    <fieldset>
                        <legend class="text-sm font-medium text-zinc-800 dark:text-white mb-2">Plätze</legend>
                        <label class="flex items-start gap-2 text-sm text-zinc-700 dark:text-zinc-300 mb-1 cursor-pointer">
                            <input type="radio" name="places" value="file" class="mt-1 accent-blue-600"
                                {{ $defaultPlaces === 'file' ? 'checked' : '' }}>
                            <span>Aus der Datei übernehmen <span class="text-xs text-zinc-500 dark:text-zinc-400">— z. B. der
                                internationale Platz bei WM/EM</span></span>
                        </label>
                        <label class="flex items-start gap-2 text-sm text-zinc-700 dark:text-zinc-300 cursor-pointer">
                            <input type="radio" name="places" value="compute" class="mt-1 accent-blue-600"
                                {{ $defaultPlaces === 'compute' ? 'checked' : '' }}>
                            <span>Unter den importierten Schwimmern berechnen <span class="text-xs text-zinc-500 dark:text-zinc-400">— der
                                Platz der Datei gilt für das offene Feld</span></span>
                        </label>
                    </fieldset>

                    @if($meet?->cup)
                        <p class="text-sm text-amber-700 dark:text-amber-400">
                            Achtung: "{{ $meet->name }}" ist dem Cup "{{ $meet->cup->name }}" zugeordnet. Ergebnisse fremder
                            Veranstaltungen zählen nicht für den Cup — die Zuordnung bitte im Veranstaltungsformular prüfen.
                        </p>
                    @else
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                            Die Veranstaltung ist keinem Cup zugeordnet; die Ergebnisse zählen für Rekorde, Bestzeiten,
                            Richtzeiten und Statistik, nicht für den Cup.
                        </p>
                    @endif
                </div>

                <div class="flex gap-3">
                    <flux:button type="submit" variant="primary" icon="arrow-up-tray">Ausgewählte importieren</flux:button>
                    <flux:button href="{{ route('lenex.import') }}" variant="ghost">Abbrechen</flux:button>
                </div>
            </form>
        @endif
    </div>
@endsection
