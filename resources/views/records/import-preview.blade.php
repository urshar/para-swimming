@php
    use App\Models\Club;
@endphp

@extends('layouts.app')

@section('title', 'Import Vorschau')

@section('content')
    <div class="max-w-3xl">

        <div class="flex items-center gap-3 mb-6">
            <flux:button href="{{ route('records.import') }}" variant="ghost" icon="arrow-left" size="sm"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Import Vorschau</h1>
            <flux:badge color="zinc" size="sm">{{ $fileName }}</flux:badge>
        </div>

        {{-- ── Zusammenfassung ─────────────────────────────────────────────── --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
            @foreach([
                ['Rekorde',      count($preview['records']),                                    'blue'],
                ['Regionale',    array_sum(array_map('count', $preview['regional_records'])),   'violet'],
                ['Ausstehend',   count($preview['pending_records']),                            'amber'],
                ['Übersprungen', $preview['skipped'],                                           'zinc'],
            ] as [$label, $count, $color])
                <div
                    class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 text-center">
                    <div class="text-2xl font-bold text-{{ $color }}-600 dark:text-{{ $color }}-400">{{ $count }}</div>
                    <div class="text-xs text-zinc-500 mt-1">{{ $label }}</div>
                </div>
            @endforeach
        </div>

        <form method="POST" action="{{ route('records.import.run') }}">
            @csrf

            {{-- ── Unbekannte Clubs ─────────────────────────────────────────── --}}
            @if(count($preview['unknown_clubs']) > 0)
                <div
                    class="bg-white dark:bg-zinc-800 rounded-xl border border-amber-300 dark:border-amber-700 p-5 mb-4">
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 mb-1 flex items-center gap-2">
                        <flux:icon.exclamation-triangle class="size-4 text-amber-500"/>
                        Unbekannte Vereine ({{ count($preview['unknown_clubs']) }})
                    </h2>
                    <p class="text-xs text-zinc-400 mb-4">Diese Vereine wurden in der Datenbank nicht gefunden. Bitte
                        entscheiden Sie für jeden Verein.</p>

                    <div class="space-y-3">
                        @foreach($preview['unknown_clubs'] as $club)
                            <div
                                class="p-3 bg-zinc-50 dark:bg-zinc-900/50 rounded-lg border border-zinc-200 dark:border-zinc-700">
                                <div class="flex items-center justify-between mb-3">
                                    <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                        {{ $club['name'] }}
                                        <span class="font-mono text-xs text-zinc-400 ml-1">{{ $club['code'] }}</span>
                                        <flux:badge size="sm" color="zinc"
                                                    class="ml-1">{{ $club['nation'] }}</flux:badge>
                                    </span>
                                    <flux:select name="clubs[{{ $club['key'] }}]" size="sm" class="w-40">
                                        <option value="new" selected>Neu anlegen</option>
                                        <option value="skip">Überspringen</option>
                                    </flux:select>
                                </div>
                                <div class="grid grid-cols-3 gap-2">
                                    <flux:input name="new_clubs[{{ $club['key'] }}][name]"
                                                value="{{ $club['name'] }}"
                                                placeholder="Name" size="sm"/>
                                    <flux:input name="new_clubs[{{ $club['key'] }}][code]"
                                                value="{{ $club['code'] }}"
                                                placeholder="Code" size="sm"/>
                                    <flux:input name="new_clubs[{{ $club['key'] }}][nation]"
                                                value="{{ $club['nation'] }}"
                                                placeholder="Nation" size="sm"/>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- ── Unbekannte Athleten ──────────────────────────────────────── --}}
            @if(count($preview['unknown_athletes']) > 0)
                <div
                    class="bg-white dark:bg-zinc-800 rounded-xl border border-amber-300 dark:border-amber-700 p-5 mb-4">
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 mb-1 flex items-center gap-2">
                        <flux:icon.user-plus class="size-4 text-amber-500"/>
                        Unbekannte Athleten ({{ count($preview['unknown_athletes']) }})
                    </h2>
                    <p class="text-xs text-zinc-400 mb-4">Diese Athleten wurden in der Datenbank nicht gefunden.</p>

                    <div class="space-y-3">
                        @foreach($preview['unknown_athletes'] as $ath)
                            <div
                                class="p-3 bg-zinc-50 dark:bg-zinc-900/50 rounded-lg border border-zinc-200 dark:border-zinc-700">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                        {{ $ath['last_name'] }}, {{ $ath['first_name'] }}
                                        <span class="text-zinc-400 text-xs ml-1">
                                            {{ $ath['birth_date'] }} · {{ $ath['gender'] }}
                                        </span>
                                        @if($ath['club_name'])
                                            <span class="text-zinc-400 text-xs">· {{ $ath['club_name'] }}</span>
                                        @endif
                                        <flux:badge size="sm" color="blue"
                                                    class="ml-1">{{ $ath['sport_class'] }}</flux:badge>
                                    </span>
                                    <flux:select name="athletes[{{ $ath['key'] }}]" size="sm" class="w-40">
                                        <option value="new" selected>Neu anlegen</option>
                                        <option value="skip">Überspringen</option>
                                    </flux:select>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- ── Ausstehende Rekorde (Nationalität unklar) ───────────────── --}}
            @if(count($preview['pending_records']) > 0)
                <div
                    class="bg-white dark:bg-zinc-800 rounded-xl border border-amber-400 dark:border-amber-600 mb-4 overflow-hidden">
                    <details open>
                        <summary class="flex items-center justify-between p-5 cursor-pointer list-none">
                            <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                                <flux:icon.question-mark-circle class="size-4 text-amber-500"/>
                                Ausstehende Rekorde — Nationalität unklar
                                <flux:badge color="amber"
                                            size="sm">{{ count($preview['pending_records']) }}</flux:badge>
                            </h2>
                            <flux:icon.chevron-down class="size-4 text-zinc-400"/>
                        </summary>

                        <div class="px-5 pb-5">
                            <p class="text-xs text-zinc-500 dark:text-zinc-400 mb-3">
                                Für diese Rekorde fehlt die Nationalität-Information im LENEX-File (kein
                                <code class="font-mono bg-zinc-100 dark:bg-zinc-900 px-1 rounded">nation</code>-Attribut
                                am Club-Element). Bestätigte Rekorde werden mit Status
                                <flux:badge size="sm" color="amber">PENDING</flux:badge>
                                importiert und müssen manuell geprüft werden.
                            </p>

                            <div class="space-y-2">
                                @foreach($preview['pending_records'] as $rec)
                                    @php
                                        $athName    = ($rec['athlete']['last_name'] ?? '') . ', ' . ($rec['athlete']['first_name'] ?? '');
                                        $clubName   = $rec['club']['name'] ?? '–';
                                        $pendingKey = $rec['pending_key'];
                                    @endphp
                                    <div
                                        class="flex items-center justify-between p-3 bg-amber-50 dark:bg-amber-950/20 rounded-lg border border-amber-200 dark:border-amber-800">
                                        <div class="text-sm">
                                            <span
                                                class="font-medium text-zinc-900 dark:text-zinc-100">{{ $athName }}</span>
                                            <span class="text-zinc-400 mx-1">·</span>
                                            <flux:badge size="sm" color="blue">{{ $rec['sport_class'] }}</flux:badge>
                                            <span class="text-zinc-500 ml-1">
                                                {{ $rec['distance'] }}m {{ $rec['record_type'] }} {{ $rec['course'] }}
                                            </span>
                                            <span class="text-zinc-400 ml-1 text-xs">{{ $clubName }}</span>
                                        </div>
                                        <div class="flex gap-3 shrink-0 ml-4">
                                            <label class="flex items-center gap-1.5 text-sm cursor-pointer">
                                                <input type="radio" name="pending[{{ $pendingKey }}]"
                                                       value="import" class="text-amber-500">
                                                <span class="text-zinc-700 dark:text-zinc-300">Importieren</span>
                                            </label>
                                            <label class="flex items-center gap-1.5 text-sm cursor-pointer">
                                                <input type="radio" name="pending[{{ $pendingKey }}]"
                                                       value="skip" checked class="text-zinc-400">
                                                <span class="text-zinc-500">Überspringen</span>
                                            </label>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </details>
                </div>
            @endif

            {{-- ── Regionale Rekorde ────────────────────────────────────────── --}}
            @if(count($preview['regional_records']) > 0)
                <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-5 mb-4">
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 mb-1 flex items-center gap-2">
                        <flux:icon.map-pin class="size-4 text-violet-500"/>
                        Regionale Rekorde
                    </h2>
                    <p class="text-xs text-zinc-400 mb-4">Pro Landesverband entscheiden ob die Rekorde importiert werden
                        sollen.</p>

                    <div class="space-y-3">
                        @foreach($preview['regional_records'] as $assocCode => $regionalRecs)
                            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">

                                {{-- Header: Import/Skip Radio + Aufklapp-Toggle --}}
                                <div class="flex items-center justify-between p-3 bg-zinc-50 dark:bg-zinc-900/50">
                                    <details class="flex-1 min-w-0">
                                        <summary
                                            class="flex items-center gap-2 text-sm font-medium text-zinc-900 dark:text-zinc-100 cursor-pointer list-none">
                                            <flux:icon.chevron-right class="size-3.5 text-zinc-400 shrink-0"/>
                                            <flux:badge color="violet" size="sm">{{ $assocCode }}</flux:badge>
                                            <span>{{ Club::REGIONAL_ASSOCIATIONS[$assocCode] ?? $assocCode }}</span>
                                            <span
                                                class="text-zinc-400 font-normal">({{ count($regionalRecs) }} Rekorde)</span>
                                        </summary>

                                        {{-- Detail-Liste --}}
                                        <div class="mt-2 divide-y divide-zinc-100 dark:divide-zinc-800">
                                            @foreach($regionalRecs as $rec)
                                                <div
                                                    class="px-2 py-2 flex items-center justify-between text-xs text-zinc-600 dark:text-zinc-400">
                                                    <span>
                                                        <flux:badge size="sm"
                                                                    color="blue">{{ $rec['sport_class'] }}</flux:badge>
                                                        {{ $rec['gender'] === 'F' ? '♀' : '♂' }}
                                                        {{ $rec['distance'] }}m {{ $rec['record_type'] }} · {{ $rec['course'] }}
                                                    </span>
                                                    <span class="font-mono">
                                                        {{ $rec['athlete']['last_name'] ?? '' }}{{ $rec['athlete'] ? ', '.$rec['athlete']['first_name'] : 'Staffel' }}
                                                    </span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </details>

                                    <div class="flex gap-4 shrink-0 ml-4">
                                        <label class="flex items-center gap-1.5 text-sm cursor-pointer">
                                            <input type="radio" name="regional[{{ $assocCode }}]" value="import"
                                                   class="text-violet-600">
                                            <span>Importieren</span>
                                        </label>
                                        <label class="flex items-center gap-1.5 text-sm cursor-pointer">
                                            <input type="radio" name="regional[{{ $assocCode }}]" value="skip"
                                                   checked class="text-zinc-400">
                                            <span class="text-zinc-500">Überspringen</span>
                                        </label>
                                    </div>
                                </div>

                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- ── Normale Rekorde Vorschau ─────────────────────────────────── --}}
            @if(count($preview['records']) > 0)
                <div
                    class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 mb-6 overflow-hidden">
                    <details>
                        <summary class="flex items-center justify-between p-5 cursor-pointer list-none">
                            <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                                <flux:icon.trophy class="size-4 text-blue-500"/>
                                Nationale Rekorde
                                <flux:badge color="blue" size="sm">{{ count($preview['records']) }}</flux:badge>
                            </h2>
                            <flux:icon.chevron-down class="size-4 text-zinc-400"/>
                        </summary>

                        <div class="px-5 pb-5 divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach($preview['records'] as $rec)
                                <div
                                    class="py-2 flex items-center justify-between text-xs text-zinc-600 dark:text-zinc-400">
                                    <span>
                                        <flux:badge size="sm" color="blue">{{ $rec['sport_class'] }}</flux:badge>
                                        {{ $rec['gender'] === 'F' ? '♀' : '♂' }}
                                        {{ $rec['distance'] }}m {{ $rec['record_type'] }} · {{ $rec['course'] }}
                                    </span>
                                    <span class="font-mono">
                                        {{ $rec['athlete']['last_name'] ?? '' }}{{ $rec['athlete'] ? ', '.$rec['athlete']['first_name'] : 'Staffel' }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </details>
                </div>
            @endif

            {{-- ── Aktionen ─────────────────────────────────────────────────── --}}
            <div class="flex items-center gap-3">
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
