@php use Carbon\Carbon; @endphp
@extends('layouts.app')

@section('title', 'LENEX Import — Wettkampf zuordnen')

@section('content')
    <div class="max-w-2xl">
        <div class="mb-6">
            <div class="flex items-center gap-2">
                <flux:button href="{{ route('lenex.import') }}" variant="primary" icon="arrow-left" size="sm"
                             title="Zurück" aria-label="Zurück"/>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Wettkampf zuordnen</h1>
            </div>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                {{ $type === 'entries' ? 'Meldungen' : 'Ergebnisse' }} werden importiert
            </p>
        </div>

        {{-- Erkannter Wettkampf aus der Datei --}}
        <div class="bg-zinc-50 dark:bg-zinc-800/60 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 mb-5">
            <p class="text-xs font-semibold text-zinc-400 uppercase tracking-wider mb-2">Erkannt in der LENEX-Datei</p>
            <p class="font-medium text-zinc-900 dark:text-zinc-100">{{ $meta['name'] }}</p>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                {{ $meta['city'] ?? '–' }}
                @if($meta['start_date'] ?? null)
                    · {{ Carbon::parse($meta['start_date'])->format('d.m.Y') }}
                @endif
                @if($meta['course'] ?? null)
                    · {{ $meta['course'] }}
                @endif
            </p>
        </div>

        <form method="POST" action="{{ route('lenex.import.run') }}">
            @csrf
            <input type="hidden" name="import_session" value="{{ $importSession }}">

            <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-3">
                Zu welchem Wettkampf sollen die {{ $type === 'entries' ? 'Meldungen' : 'Ergebnisse' }} importiert
                werden?
            </p>

            <div class="space-y-2">

                {{-- Vorhandene Wettkämpfe --}}
                @foreach($candidates as $meet)
                    <label class="flex items-start gap-3 p-4 rounded-xl border cursor-pointer transition-colors
                                  border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800
                                  hover:border-blue-400 dark:hover:border-blue-500
                                  has-checked:border-blue-500 has-checked:bg-blue-50 dark:has-checked:bg-blue-950/30">
                        <input type="radio" name="meet_id" value="{{ $meet->id }}"
                               class="mt-1 accent-blue-600" required>
                        <div class="flex-1 min-w-0">
                            <p class="font-medium text-zinc-900 dark:text-zinc-100">{{ $meet->name }}</p>
                            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                                {{ $meet->city ?? '–' }}
                                @if($meet->start_date)
                                    · {{ Carbon::parse($meet->start_date)->format('d.m.Y') }}
                                @endif
                                @if($meet->course)
                                    · {{ $meet->course }}
                                @endif
                            </p>
                            <div class="flex gap-2 mt-1.5 flex-wrap">
                                <flux:badge size="sm" color="zinc">
                                    {{ $meet->swim_events_count ?? $meet->swimEvents()->count() }} Disziplinen
                                </flux:badge>
                                @if($meet->entries_count ?? $meet->entries()->count())
                                    <flux:badge size="sm" color="zinc">
                                        {{ $meet->entries_count ?? $meet->entries()->count() }} Meldungen
                                    </flux:badge>
                                @endif
                            </div>
                        </div>
                        {{-- Datum-Match-Indikator --}}
                        @if($meta['start_date'] && $meet->start_date?->format('Y-m-d') === $meta['start_date'])
                            <flux:badge size="sm" color="green">Datum stimmt überein</flux:badge>
                        @endif
                    </label>
                @endforeach

                {{-- Option: Neues Meet anlegen --}}
                <label class="flex items-start gap-3 p-4 rounded-xl border cursor-pointer transition-colors
                              border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800
                              hover:border-blue-400 dark:hover:border-blue-500
                              has-checked:border-blue-500 has-checked:bg-blue-50 dark:has-checked:bg-blue-950/30">
                    <input type="radio" name="meet_id" value=""
                           class="mt-1 accent-blue-600" {{ $candidates->isEmpty() ? 'checked' : '' }}>
                    <div>
                        <p class="font-medium text-zinc-900 dark:text-zinc-100">Als neuen Wettkampf importieren</p>
                        <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                            Legt „{{ $meta['name'] }}" neu in der Datenbank an
                        </p>
                    </div>
                </label>

            </div>

            {{-- Nation der Veranstaltung fehlt in der Datei: für eine neue Veranstaltung Pflicht (meets.nation_id). --}}
            @if($meetNations->isNotEmpty())
                <div class="mt-5 p-4 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800">
                    <flux:field>
                        <flux:label>Nation der Veranstaltung</flux:label>
                        <flux:select variant="listbox" searchable name="meet_nation" placeholder="Bitte wählen…" class="max-w-xs">
                            @foreach($meetNations as $meetNation)
                                <flux:select.option value="{{ $meetNation->code }}">{{ $meetNation->name_de }} ({{ $meetNation->code }})</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:description>Die Datei enthält keine bekannte Nation der Veranstaltung. Nur nötig, wenn sie neu angelegt wird.</flux:description>
                        <flux:error name="meet_nation"/>
                    </flux:field>
                </div>
            @endif

            {{-- Nationenfilter: nur bei Dateien mit mehreren Nationen (internationale Veranstaltungen, Gäste). --}}
            @if(count($nations) > 1)
                <div class="mt-5 p-4 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800">
                    <flux:field>
                        <flux:label>Nur Schwimmer dieser Nation importieren</flux:label>
                        <flux:select variant="listbox" name="only_nation" class="max-w-xs">
                            <flux:select.option value="ALL" :selected="$defaultNation === 'ALL'">Alle Nationen</flux:select.option>
                            @foreach($nations as $nationCode)
                                <flux:select.option value="{{ $nationCode }}"
                                                    :selected="$defaultNation === $nationCode">{{ $nationCode }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:description>
                            Die Datei enthält {{ count($nations) }} Nationen. Mit Filter werden nur deren Schwimmer, Staffeln und
                            Bewerbe importiert; nicht gefundene Vereine (z. B. Nationalteams) werden nicht abgefragt — die
                            Ergebnisse gehen an den Heimverein der Schwimmer.
                        </flux:description>
                    </flux:field>
                </div>
            @endif

            @error('import')
            <p class="text-sm text-red-500 mt-3">{{ $message }}</p>
            @enderror

            <div class="flex gap-3 mt-6">
                <flux:button type="submit" variant="primary" icon="arrow-up-tray">
                    Import starten
                </flux:button>
                <flux:button href="{{ route('lenex.import') }}" variant="ghost">
                    Abbrechen
                </flux:button>
            </div>
        </form>
    </div>
@endsection
