@php
    use App\Models\Club;
    use App\Models\StrokeType;
    use App\Support\TimeParser;
@endphp

@extends('layouts.app')

@section('title', 'Rekord-Import bestätigen')

@section('content')
    <div class="max-w-4xl">
        <div class="flex items-center gap-3 mb-6">
            <flux:button href="{{ route('records.import') }}" variant="ghost" icon="arrow-left" size="sm"/>
            <div>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Import bestätigen</h1>
                <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">{{ $fileName }}</p>
            </div>
        </div>

        {{-- Zusammenfassung --}}
        <div class="grid grid-cols-3 gap-4 mb-6">
            <div
                class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 text-center">
                <div class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ count($preview['records']) }}</div>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Rekorde mit Zeit</div>
            </div>
            <div
                class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 text-center">
                <div class="text-2xl font-bold text-amber-600">{{ count($preview['unknown_clubs']) }}</div>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Unbekannte Vereine</div>
            </div>
            <div
                class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 text-center">
                <div class="text-2xl font-bold text-amber-600">{{ count($preview['unknown_athletes']) }}</div>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Unbekannte Athleten</div>
            </div>
            @if(count($preview['regional_records']) > 0)
                <div
                    class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 text-center">
                    <div class="text-2xl font-bold text-violet-600">{{ count($preview['regional_records']) }}</div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">Regionalverbände</div>
                </div>
            @endif
        </div>

        <form method="POST" action="{{ route('records.import.run') }}">
            @csrf

            {{-- Unbekannte Vereine --}}
            @if(count($preview['unknown_clubs']) > 0)
                <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 mb-4">
                    <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-1">Unbekannte Vereine</h2>
                    <p class="text-xs text-zinc-400 mb-4">Diese Vereine sind nicht in der Datenbank. Bitte für jeden
                        eine Aktion wählen.</p>

                    <div class="space-y-4">
                        @foreach($preview['unknown_clubs'] as $club)
                            <div class="border border-zinc-100 dark:border-zinc-700 rounded-lg p-4"
                                 x-data="{ action: 'new' }">
                                <div class="flex items-center justify-between mb-3">
                                    <div>
                                        <span
                                            class="font-medium text-zinc-900 dark:text-zinc-100">{{ $club['name'] }}</span>
                                        <span
                                            class="text-xs text-zinc-400 ml-2">{{ $club['code'] }} · {{ $club['nation'] }}</span>
                                    </div>
                                    <flux:select name="clubs[{{ $club['key'] }}]"
                                                 x-model="action"
                                                 class="w-40">
                                        <option value="new">Neu anlegen</option>
                                        <option value="skip">Überspringen</option>
                                    </flux:select>
                                </div>

                                {{-- Felder für neuen Verein --}}
                                <div x-show="action === 'new'" class="grid grid-cols-3 gap-3 mt-2">
                                    <flux:field>
                                        <flux:label>Name *</flux:label>
                                        <flux:input name="new_clubs[{{ $club['key'] }}][name]"
                                                    value="{{ $club['name'] }}" required/>
                                    </flux:field>
                                    <flux:field>
                                        <flux:label>Code</flux:label>
                                        <flux:input name="new_clubs[{{ $club['key'] }}][code]"
                                                    value="{{ $club['code'] }}"/>
                                    </flux:field>
                                    <flux:field>
                                        <flux:label>Nation</flux:label>
                                        <flux:input name="new_clubs[{{ $club['key'] }}][nation]"
                                                    value="{{ $club['nation'] }}"/>
                                    </flux:field>
                                </div>
                                <div x-show="action === 'skip'" class="mt-2">
                                    <p class="text-xs text-amber-600 dark:text-amber-400">
                                        Alle Rekorde von Athleten dieses Vereins werden ebenfalls übersprungen.
                                    </p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Unbekannte Athleten --}}
            @if(count($preview['unknown_athletes']) > 0)
                <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 mb-4">
                    <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-1">Unbekannte Athleten</h2>
                    <p class="text-xs text-zinc-400 mb-4">Diese Athleten wurden nicht in der Datenbank gefunden. Bitte
                        für jeden eine Aktion wählen.</p>

                    <div class="space-y-4">
                        @foreach($preview['unknown_athletes'] as $athlete)
                            <div class="border border-zinc-100 dark:border-zinc-700 rounded-lg p-4"
                                 x-data="{ action: 'new' }">
                                <div class="flex items-center justify-between mb-3">
                                    <div>
                                        <span class="font-medium text-zinc-900 dark:text-zinc-100">
                                            {{ $athlete['last_name'] }} {{ $athlete['first_name'] }}
                                        </span>
                                        <span class="text-xs text-zinc-400 ml-2">
                                            {{ $athlete['birth_date'] ?: 'kein Geburtsdatum' }}
                                            · {{ $athlete['gender'] === 'F' ? 'Damen' : 'Herren' }}
                                            @if($athlete['club_name'])
                                                · {{ $athlete['club_name'] }}
                                            @endif
                                        </span>
                                    </div>
                                    <flux:select name="athletes[{{ $athlete['key'] }}]"
                                                 x-model="action"
                                                 class="w-40">
                                        <option value="new">Neu anlegen</option>
                                        <option value="skip">Überspringen</option>
                                    </flux:select>
                                </div>

                                {{-- Felder für neuen Athleten --}}
                                <div x-show="action === 'new'" class="grid grid-cols-2 gap-3 mt-2">
                                    <flux:field>
                                        <flux:label>Nachname *</flux:label>
                                        <flux:input name="new_athletes[{{ $athlete['key'] }}][last_name]"
                                                    value="{{ $athlete['last_name'] }}" required/>
                                    </flux:field>
                                    <flux:field>
                                        <flux:label>Vorname *</flux:label>
                                        <flux:input name="new_athletes[{{ $athlete['key'] }}][first_name]"
                                                    value="{{ $athlete['first_name'] }}" required/>
                                    </flux:field>
                                </div>
                                <div x-show="action === 'skip'" class="mt-2">
                                    <p class="text-xs text-amber-600 dark:text-amber-400">
                                        Alle Rekorde dieses Athleten werden übersprungen.
                                    </p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Regionale Rekorde --}}
            @if(count($preview['regional_records']) > 0)
                <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 mb-4">
                    <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-1">Regionale Rekorde</h2>
                    <p class="text-xs text-zinc-400 mb-4">
                        Für jeden Regionalverband wählen ob die Rekorde importiert werden sollen.
                    </p>

                    <div class="space-y-4">
                        @foreach($preview['regional_records'] as $assocCode => $recs)
                            @php
                                $assocName = Club::REGIONAL_ASSOCIATIONS[$assocCode]
                                    ?? $assocCode;
                                $juniorCount  = collect($recs)->filter(fn($r) => str_ends_with($r['record_type'], '.JR'))->count();
                                $seniorCount  = count($recs) - $juniorCount;
                            @endphp
                            <div class="border border-zinc-100 dark:border-zinc-700 rounded-lg p-4"
                                 x-data="{ open: false }">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-3">
                                        <flux:badge color="violet" size="sm">{{ $assocCode }}</flux:badge>
                                        <div>
                                            <span class="font-medium text-zinc-900 dark:text-zinc-100">
                                                {{ $assocName }}
                                            </span>
                                            <span class="text-xs text-zinc-400 ml-2">
                                                {{ count($recs) }} Rekord(e)
                                                @if($seniorCount > 0)
                                                    · {{ $seniorCount }} Senior
                                                @endif
                                                @if($juniorCount > 0)
                                                    · {{ $juniorCount }} Jugend
                                                @endif
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <button type="button"
                                                @click="open = !open"
                                                class="text-xs text-blue-500 hover:underline"
                                                x-text="open ? 'Ausblenden' : 'Details anzeigen'">
                                        </button>
                                        <flux:select name="regional[{{ $assocCode }}]" class="w-36">
                                            <option value="import">Importieren</option>
                                            <option value="skip">Überspringen</option>
                                        </flux:select>
                                    </div>
                                </div>

                                {{-- Detail-Tabelle aufklappbar --}}
                                <div x-show="open" x-cloak class="mt-4">
                                    <flux:table>
                                        <flux:table.columns>
                                            <flux:table.column>Typ</flux:table.column>
                                            <flux:table.column>Klasse</flux:table.column>
                                            <flux:table.column>Disziplin</flux:table.column>
                                            <flux:table.column>Bahn</flux:table.column>
                                            <flux:table.column>Zeit</flux:table.column>
                                            <flux:table.column>Athlet</flux:table.column>
                                            <flux:table.column>Datum</flux:table.column>
                                        </flux:table.columns>
                                        <flux:table.rows>
                                            @foreach($recs as $rec)
                                                <flux:table.row>
                                                    <flux:table.cell>
                                                        <flux:badge size="sm"
                                                                    color="violet">{{ $rec['record_type'] }}</flux:badge>
                                                    </flux:table.cell>
                                                    <flux:table.cell
                                                        class="font-mono text-sm">{{ $rec['sport_class'] }}</flux:table.cell>
                                                    <flux:table.cell class="text-sm">
                                                        {{ $rec['distance'] }}m
                                                        {{ StrokeType::find($rec['stroke_type_id'])?->name_de ?? '?' }}
                                                        @if($rec['relay_count'] > 1)
                                                            <span
                                                                class="text-zinc-400">{{ $rec['relay_count'] }}x</span>
                                                        @endif
                                                    </flux:table.cell>
                                                    <flux:table.cell>
                                                        <flux:badge size="sm"
                                                                    color="zinc">{{ $rec['course'] }}</flux:badge>
                                                    </flux:table.cell>
                                                    <flux:table.cell class="font-mono text-sm font-bold">
                                                        {{ TimeParser::display($rec['swim_time']) }}
                                                    </flux:table.cell>
                                                    <flux:table.cell class="text-sm text-zinc-500">
                                                        @if($rec['athlete'])
                                                            {{ $rec['athlete']['last_name'] }} {{ $rec['athlete']['first_name'] }}
                                                        @else
                                                            <span class="text-zinc-400">Staffel</span>
                                                        @endif
                                                    </flux:table.cell>
                                                    <flux:table.cell class="text-sm text-zinc-500">
                                                        {{ $rec['set_date'] ?: '–' }}
                                                    </flux:table.cell>
                                                </flux:table.row>
                                            @endforeach
                                        </flux:table.rows>
                                    </flux:table>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Rekord-Vorschau --}}
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 mb-6">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-4">
                    Rekorde ({{ count($preview['records']) }})
                    <span class="text-sm font-normal text-zinc-400 ml-2">· {{ $preview['skipped'] }} NT-Einträge übersprungen</span>
                </h2>

                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Typ</flux:table.column>
                        <flux:table.column>Klasse</flux:table.column>
                        <flux:table.column>Disziplin</flux:table.column>
                        <flux:table.column>Bahn</flux:table.column>
                        <flux:table.column>Zeit</flux:table.column>
                        <flux:table.column>Athlet</flux:table.column>
                        <flux:table.column>Datum</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach($preview['records'] as $rec)
                            <flux:table.row>
                                <flux:table.cell>
                                    <flux:badge size="sm" color="blue">{{ $rec['record_type'] }}</flux:badge>
                                </flux:table.cell>
                                <flux:table.cell class="font-mono text-sm">{{ $rec['sport_class'] }}</flux:table.cell>
                                <flux:table.cell class="text-sm">
                                    {{ $rec['distance'] }}m
                                    {{ StrokeType::find($rec['stroke_type_id'])?->name_de ?? '?' }}
                                    @if($rec['relay_count'] > 1)
                                        <span class="text-zinc-400">{{ $rec['relay_count'] }}x</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm" color="zinc">{{ $rec['course'] }}</flux:badge>
                                </flux:table.cell>
                                <flux:table.cell class="font-mono font-bold text-sm">
                                    {{ TimeParser::display($rec['swim_time']) }}
                                </flux:table.cell>
                                <flux:table.cell class="text-sm text-zinc-500">
                                    @if($rec['athlete'])
                                        {{ $rec['athlete']['last_name'] }} {{ $rec['athlete']['first_name'] }}
                                    @else
                                        <span class="text-zinc-400">Staffel</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="text-sm text-zinc-500">
                                    {{ $rec['set_date'] ?: '–' }}
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>

            <div class="flex gap-3">
                <flux:button type="submit" variant="primary" icon="arrow-down-tray">
                    Import durchführen
                </flux:button>
                <flux:button href="{{ route('records.import') }}" variant="ghost">
                    Abbrechen
                </flux:button>
            </div>
        </form>
    </div>
@endsection
