@extends('layouts.app')

@section('title', 'Import-Überprüfung')

@section('content')
    <div class="max-w-4xl">
        <div class="flex items-center gap-3 mb-2">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Import-Überprüfung</h1>
        </div>
        <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-6">
            Bitte entscheide für jeden Eintrag, ob er einem bestehenden Datensatz zugeordnet, neu angelegt oder
            übersprungen werden soll. Übersprungene Einträge werden samt ihren Ergebnissen nicht importiert.
        </p>

        @error('resolve')
            <flux:callout variant="danger" icon="exclamation-triangle" class="mb-4">{{ $message }}</flux:callout>
        @enderror

        {{-- ── Schritt 0: Rahmenbewerbe (vor dem ersten Import-Lauf) ── --}}
        @if(!empty($pendingEvents))
            <form method="POST" action="{{ route('lenex.import.resolve-events') }}">
                @csrf
                <input type="hidden" name="import_session" value="{{ $importSession }}">

                <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100 mb-1">
                    Bewerbe ohne Wertungsklassen ({{ count($pendingEvents) }})
                </h2>
                <p class="text-xs text-zinc-500 dark:text-zinc-400 mb-3">
                    Diese Einzelbewerbe haben in der Datei keine Klassenangabe. Rahmenbewerbe (z. B. Schnupperbewerbe für
                    nicht klassifizierte Schwimmer) ankreuzen: Sie werden angelegt und exportiert, aber nicht gewertet —
                    ihre Ergebnisse und Schwimmer werden nicht importiert. Später im Disziplin-Formular änderbar.
                </p>
                <div
                    class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 divide-y divide-zinc-100 dark:divide-zinc-700 mb-6">
                    @foreach($pendingEvents as $pending)
                        <label class="p-4 flex items-start gap-3 cursor-pointer">
                            <input type="checkbox" name="unscored_events[]" value="{{ $pending['number'] }}"
                                   class="mt-1 rounded border-zinc-300 dark:border-zinc-600 dark:bg-zinc-700">
                            <span>
                                <span class="block font-medium text-zinc-900 dark:text-zinc-100">
                                    Bewerb {{ $pending['number'] }} – {{ $pending['label'] }}
                                </span>
                                <span class="block text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
                                    Wertungsgruppen: {{ $pending['groups'] ?: 'keine' }} · Ankreuzen = Rahmenbewerb, nicht gewertet
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>

                <div class="flex gap-3">
                    <flux:button type="submit" variant="primary" icon="check">
                        Bewerbe bestätigen &amp; Import starten
                    </flux:button>
                    <flux:button href="{{ route('lenex.import') }}" variant="ghost">Abbrechen</flux:button>
                </div>
            </form>

            {{-- ── Schritt A: Vereine ── --}}
        @elseif(!empty($unresolvedClubs))
            <form method="POST" action="{{ route('lenex.import.resolve-clubs') }}">
                @csrf
                <input type="hidden" name="import_session" value="{{ $importSession }}">

                <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100 mb-1">
                    Unbekannte Vereine ({{ count($unresolvedClubs) }})
                </h2>
                <p class="text-xs text-zinc-500 dark:text-zinc-400 mb-3">
                    Diese Vereine wurden über Code und Name nicht gefunden.
                </p>
                <div
                    class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 divide-y divide-zinc-100 dark:divide-zinc-700 mb-6">
                    @foreach($unresolvedClubs as $i => $club)
                        @php $clubSuggestedIds = array_column($club['suggestions'], 'id'); @endphp
                        <div class="p-4 flex flex-col md:flex-row md:items-center gap-3">
                            <div class="flex-1 min-w-0">
                                <div class="font-medium text-zinc-900 dark:text-zinc-100">
                                    {{ $club['name'] }}
                                    @if($club['preselect'] !== null)
                                        <flux:badge size="sm" color="green" class="ml-1">Vorschlag vorbelegt</flux:badge>
                                    @elseif(!empty($club['suggestions']))
                                        <flux:badge size="sm" color="amber" class="ml-1">Vorschlag</flux:badge>
                                    @endif
                                </div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
                                    Code: {{ $club['code'] ?: '–' }} · Nation: {{ $club['nation_code'] ?: '–' }}
                                    @if($club['regional_association'] ?? null)
                                        · Verband: {{ $club['regional_association'] }}
                                    @endif
                                </div>
                            </div>
                            <flux:select variant="listbox" name="clubs[{{ $i }}][selection]" size="sm"
                                         class="w-full md:w-96" searchable
                                         aria-label="Zuordnung für Verein {{ $club['name'] }}">
                                <flux:select.option value="new" :selected="is_null($club['preselect'])">Neu anlegen</flux:select.option>
                                <flux:select.option value="skip">Überspringen</flux:select.option>
                                @if(!empty($club['suggestions']))
                                    <flux:select.group label="Vorschlag — gleicher/ähnlicher Name oder Code">
                                        @foreach($club['suggestions'] as $sug)
                                            <flux:select.option value="{{ $sug['id'] }}"
                                                                :selected="$club['preselect'] === $sug['id']">{{ $sug['label'] }}</flux:select.option>
                                        @endforeach
                                    </flux:select.group>
                                @endif
                                <flux:select.group label="Bestehendem Verein zuordnen">
                                    @foreach($clubOptions as $existingClub)
                                        @continue(in_array($existingClub['id'], $clubSuggestedIds, true))
                                        <flux:select.option value="{{ $existingClub['id'] }}">{{ $existingClub['label'] }}</flux:select.option>
                                    @endforeach
                                </flux:select.group>
                            </flux:select>
                        </div>
                    @endforeach
                </div>

                <div class="flex gap-3">
                    <flux:button type="submit" variant="primary" icon="check">
                        Vereine bestätigen &amp; weiter
                    </flux:button>
                    <flux:button href="{{ route('lenex.import') }}" variant="ghost">Abbrechen</flux:button>
                </div>
            </form>

            {{-- ── Schritt B: Athleten ── --}}
        @elseif(!empty($unresolvedAthletes))
            <form method="POST" action="{{ route('lenex.import.resolve-athletes') }}">
                @csrf
                <input type="hidden" name="import_session" value="{{ $importSession }}">

                <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100 mb-1">
                    Unbekannte Athleten ({{ count($unresolvedAthletes) }})
                </h2>
                <p class="text-xs text-zinc-500 dark:text-zinc-400 mb-3">
                    Diese Athleten wurden über Lizenz, SDMS-ID sowie Name und Geburtsdatum nicht gefunden.
                </p>
                <div
                    class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 divide-y divide-zinc-100 dark:divide-zinc-700 mb-6">
                    @foreach($unresolvedAthletes as $i => $athlete)
                        @php
                            // Bestehende Athleten auf denselben Anfangsbuchstaben des Nachnamens eingegrenzt
                            // (sonst 500+ Namen); bereits vorgeschlagene nicht doppelt zeigen.
                            $suggestedIds = array_column($athlete['suggestions'], 'id');
                            $initial = mb_strtoupper(mb_substr($athlete['last_name'], 0, 1));
                            $sameInitial = $athletes->filter(fn ($a) => $a['initial'] === $initial && ! in_array($a['id'], $suggestedIds, true));
                        @endphp
                        <div class="p-4 flex flex-col md:flex-row md:items-center gap-3">
                            <div class="flex-1 min-w-0">
                                <div class="font-medium text-zinc-900 dark:text-zinc-100">
                                    {{ $athlete['last_name'] }}, {{ $athlete['first_name'] }}
                                    @if($athlete['preselect'] !== null)
                                        <flux:badge size="sm" color="green" class="ml-1">Jahrgang-Treffer vorbelegt</flux:badge>
                                    @elseif(!empty($athlete['suggestions']))
                                        <flux:badge size="sm" color="amber" class="ml-1">Vorschlag</flux:badge>
                                    @endif
                                </div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
                                    {{ $athlete['gender'] ?: '–' }} ·
                                    *{{ $athlete['birth_date_display'] ?: '–' }} ·
                                    Lizenz: {{ $athlete['license'] ?: '–' }}
                                    @if($athlete['club_name'])
                                        · {{ $athlete['club_name'] }}
                                    @endif
                                    @if($athlete['sport_class'] ?? null)
                                        · Klasse: {{ $athlete['sport_class'] }}
                                    @endif
                                </div>
                            </div>
                            <flux:select variant="listbox" name="athletes[{{ $i }}][selection]" size="sm"
                                         class="w-full md:w-[28rem]" searchable
                                         aria-label="Zuordnung für {{ $athlete['last_name'] }}, {{ $athlete['first_name'] }}">
                                <flux:select.option value="new" :selected="is_null($athlete['preselect'])">Neu anlegen</flux:select.option>
                                <flux:select.option value="skip">Überspringen</flux:select.option>
                                @if(!empty($athlete['suggestions']))
                                    <flux:select.group label="Vorschlag — gleicher Name{{ $athlete['birth_date'] !== '' ? ' & Geburtsjahr' : '' }}">
                                        @foreach($athlete['suggestions'] as $sug)
                                            <flux:select.option value="{{ $sug['id'] }}"
                                                                :selected="$athlete['preselect'] === $sug['id']">{{ $sug['label'] }}</flux:select.option>
                                        @endforeach
                                    </flux:select.group>
                                @endif
                                @if($sameInitial->isNotEmpty())
                                    <flux:select.group label="Bestehendem Athleten zuordnen (Nachname {{ $initial }}…)">
                                        @foreach($sameInitial as $existing)
                                            <flux:select.option value="{{ $existing['id'] }}">{{ $existing['label'] }}</flux:select.option>
                                        @endforeach
                                    </flux:select.group>
                                @endif
                            </flux:select>
                        </div>
                    @endforeach
                </div>

                <div class="flex gap-3">
                    <flux:button type="submit" variant="primary" icon="check">
                        Import abschließen
                    </flux:button>
                    <flux:button href="{{ route('lenex.import') }}" variant="ghost">Abbrechen</flux:button>
                </div>
            </form>

        @else
            {{-- Sollte nicht vorkommen — direkt weiterleiten --}}
            <p class="text-zinc-400 text-sm">Keine offenen Einträge — Import wird fortgesetzt.</p>
        @endif

    </div>
@endsection
