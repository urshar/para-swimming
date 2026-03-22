@extends('layouts.app')

@section('title', 'Import-Überprüfung')

@section('content')
    <div class="max-w-3xl">
        <div class="flex items-center gap-3 mb-2">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Import-Überprüfung</h1>
        </div>
        <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-6">
            Folgende Vereine und Athleten konnten nicht automatisch zugeordnet werden.
            Bitte entscheide für jeden Eintrag ob er neu angelegt oder übersprungen werden soll.
        </p>

        <form method="POST" action="{{ route('lenex.import.resolve') }}">
            @csrf
            <input type="hidden" name="import_session" value="{{ $importSession }}">

            {{-- Unbekannte Vereine --}}
            @if(!empty($unresolvedClubs))
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100 mb-3">
                    Unbekannte Vereine ({{ count($unresolvedClubs) }})
                </h2>
                <div
                    class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 divide-y divide-zinc-100 dark:divide-zinc-700 mb-6">
                    @foreach($unresolvedClubs as $i => $club)
                        <div class="p-4" x-data="{ action: 'skip' }">
                            <div class="flex items-start gap-4">
                                <div class="flex-1">
                                    <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ $club['name'] }}</div>
                                    <div class="text-xs text-zinc-400 mt-0.5">
                                        Code: {{ $club['code'] ?: '–' }} · Nation: {{ $club['nation_code'] }}
                                    </div>
                                </div>
                                <div class="flex gap-2">
                                    <label class="flex items-center gap-1.5 cursor-pointer">
                                        <input type="radio" :name="'clubs[' + {{ $i }} + '][action]'" value="create"
                                               x-model="action" class="text-blue-600">
                                        <span class="text-sm">Anlegen</span>
                                    </label>
                                    <label class="flex items-center gap-1.5 cursor-pointer">
                                        <input type="radio" :name="'clubs[' + {{ $i }} + '][action]'" value="skip"
                                               x-model="action" checked class="text-zinc-400">
                                        <span class="text-sm">Überspringen</span>
                                    </label>
                                </div>
                            </div>

                            {{-- Hidden fields --}}
                            <input type="hidden" name="clubs[{{ $i }}][name]" value="{{ $club['name'] }}">
                            <input type="hidden" name="clubs[{{ $i }}][code]" value="{{ $club['code'] }}">
                            <input type="hidden" name="clubs[{{ $i }}][nation_id]" value="{{ $club['nation_id'] }}">
                            <input type="hidden" name="clubs[{{ $i }}][lenex_id]" value="{{ $club['lenex_id'] }}">
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Unbekannte Athleten --}}
            @if(!empty($unresolvedAthletes))
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100 mb-3">
                    Unbekannte Athleten ({{ count($unresolvedAthletes) }})
                </h2>
                <div
                    class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 divide-y divide-zinc-100 dark:divide-zinc-700 mb-6">
                    @foreach($unresolvedAthletes as $i => $athlete)
                        <div class="p-4" x-data="{ action: 'skip' }">
                            <div class="flex items-start gap-4">
                                <div class="flex-1">
                                    <div class="font-medium text-zinc-900 dark:text-zinc-100">
                                        {{ $athlete['last_name'] }}, {{ $athlete['first_name'] }}
                                    </div>
                                    <div class="text-xs text-zinc-400 mt-0.5">
                                        {{ $athlete['gender'] }} ·
                                        {{ $athlete['birth_date'] ?: '–' }} ·
                                        Lizenz: {{ $athlete['license'] ?: '–' }} ·
                                        @if($athlete['sport_class'])
                                            Klasse: {{ $athlete['sport_class'] }}
                                        @endif
                                    </div>
                                </div>
                                <div class="flex gap-2">
                                    <label class="flex items-center gap-1.5 cursor-pointer">
                                        <input type="radio" :name="'athletes[' + {{ $i }} + '][action]'" value="create"
                                               x-model="action" class="text-blue-600">
                                        <span class="text-sm">Anlegen</span>
                                    </label>
                                    <label class="flex items-center gap-1.5 cursor-pointer">
                                        <input type="radio" :name="'athletes[' + {{ $i }} + '][action]'" value="skip"
                                               x-model="action" checked class="text-zinc-400">
                                        <span class="text-sm">Überspringen</span>
                                    </label>
                                </div>
                            </div>

                            <input type="hidden" name="athletes[{{ $i }}][first_name]"
                                   value="{{ $athlete['first_name'] }}">
                            <input type="hidden" name="athletes[{{ $i }}][last_name]"
                                   value="{{ $athlete['last_name'] }}">
                            <input type="hidden" name="athletes[{{ $i }}][birth_date]"
                                   value="{{ $athlete['birth_date'] }}">
                            <input type="hidden" name="athletes[{{ $i }}][gender]" value="{{ $athlete['gender'] }}">
                            <input type="hidden" name="athletes[{{ $i }}][nation_id]"
                                   value="{{ $athlete['nation_id'] }}">
                            <input type="hidden" name="athletes[{{ $i }}][club_id]" value="{{ $athlete['club_id'] }}">
                            <input type="hidden" name="athletes[{{ $i }}][license]" value="{{ $athlete['license'] }}">
                            <input type="hidden" name="athletes[{{ $i }}][lenex_id]" value="{{ $athlete['lenex_id'] }}">
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="flex gap-3">
                <flux:button type="submit" variant="primary" icon="check">
                    Import fortsetzen
                </flux:button>
                <flux:button href="{{ route('lenex.import') }}" variant="ghost">
                    Abbrechen
                </flux:button>
            </div>
        </form>
    </div>
@endsection
