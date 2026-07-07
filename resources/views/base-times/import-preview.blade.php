@extends('layouts.app')

@section('title', 'Basiswerte importieren – Vorschau')

@section('content')
    <div class="max-w-3xl">
        <div class="flex items-center gap-3 mb-6">
            <flux:button href="{{ route('base-times.import') }}" variant="ghost" icon="arrow-left" size="sm"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Import-Vorschau</h1>
        </div>

        <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-6">
            Datei: <span class="font-mono">{{ $fileName }}</span>
        </p>

        {{-- ── Zusammenfassung ──────────────────────────────────────────────── --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            @php
                $cellsByType = collect($parsed['cells'])->countBy('value_type');
            @endphp
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
                <p class="text-xs text-zinc-400 mb-1">Kategorien</p>
                <p class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ count($parsed['categories']) }}</p>
            </div>
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
                <p class="text-xs text-zinc-400 mb-1">Bewerbe</p>
                <p class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ count($parsed['disciplines']) }}</p>
            </div>
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
                <p class="text-xs text-zinc-400 mb-1">Sportklassen</p>
                <p class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ count($parsed['sportClasses']) }}</p>
            </div>
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
                <p class="text-xs text-zinc-400 mb-1">Basiswert-Zellen</p>
                <p class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ count($parsed['cells']) }}</p>
            </div>
        </div>

        <div class="flex gap-3 mb-6 text-sm">
            <flux:badge color="zinc">{{ $cellsByType['MANUAL'] ?? 0 }} manuell (schwarz)</flux:badge>
            <flux:badge color="orange">{{ $cellsByType['CALCULATED'] ?? 0 }} berechnet (orange)</flux:badge>
            <flux:badge color="zinc">{{ $cellsByType['NOT_APPLICABLE'] ?? 0 }} nicht anwendbar (0,0)</flux:badge>
        </div>

        {{-- ── Kategorien-Übersicht ─────────────────────────────────────────── --}}
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden mb-6">
            <div class="px-4 py-3 border-b border-zinc-200 dark:border-zinc-700">
                <h2 class="font-semibold text-sm text-zinc-900 dark:text-zinc-100">Erkannte Kategorien</h2>
            </div>
            <table class="w-full text-sm">
                <thead>
                <tr class="border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900/40">
                    <th class="text-left px-4 py-2 font-medium text-zinc-600 dark:text-zinc-400">Code</th>
                    <th class="text-left px-4 py-2 font-medium text-zinc-600 dark:text-zinc-400">Kurs</th>
                    <th class="text-left px-4 py-2 font-medium text-zinc-600 dark:text-zinc-400">Geschlecht</th>
                </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700/50">
                @foreach($parsed['categories'] as $code => $attrs)
                    <tr>
                        <td class="px-4 py-2 font-mono text-xs text-zinc-500">{{ $code }}</td>
                        <td class="px-4 py-2">{{ $attrs['course'] ?? '–' }}</td>
                        <td class="px-4 py-2">{{ $attrs['gender'] ?? '–' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        {{-- ── Hinweise ──────────────────────────────────────────────────────── --}}
        @if(!empty($parsed['warnings']))
            <div class="bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800 rounded-xl p-4 mb-6">
                <h2 class="font-semibold text-sm text-amber-800 dark:text-amber-400 mb-2">
                    Hinweise ({{ count($parsed['warnings']) }})
                </h2>
                <ul class="text-sm text-amber-700 dark:text-amber-400 space-y-1 list-disc list-inside">
                    @foreach($parsed['warnings'] as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- ── Aktionen ──────────────────────────────────────────────────────── --}}
        <form method="POST" action="{{ route('base-times.import.run') }}">
            @csrf
            <div class="flex items-center gap-3">
                <flux:button type="submit" variant="primary" icon="check">
                    Import durchführen
                </flux:button>
                <flux:button href="{{ route('base-times.import') }}" variant="ghost">
                    Abbrechen
                </flux:button>
            </div>
        </form>
    </div>
@endsection
