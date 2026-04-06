@extends('layouts.app')

@section('title', 'Rekorde exportieren')

@section('content')
    <div class="max-w-2xl" x-data="{ category: 'national' }">
        <div class="flex items-center gap-3 mb-6">
            <flux:button href="{{ route('records.index') }}" variant="ghost" icon="arrow-left" size="sm"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Rekorde exportieren</h1>
        </div>

        @if($errors->any())
            <div class="mb-4 p-4 bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-800 rounded-xl text-sm text-red-700 dark:text-red-400">
                @foreach($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('records.export.download') }}">
            @csrf

            {{-- ── Kategorie ──────────────────────────────────────────────── --}}
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 mb-4">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 mb-4">Rekord-Kategorie</h2>

                <div class="space-y-3">
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="radio" name="category" value="national" x-model="category" class="text-blue-600">
                        <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Nationale Rekorde</span>
                        <span class="text-xs text-zinc-400">AUT + AUT Jugend</span>
                    </label>

                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="radio" name="category" value="regional" x-model="category" class="text-blue-600">
                        <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Regionale Rekorde</span>
                        <span class="text-xs text-zinc-400">Landesverbände inkl. Jugend</span>
                    </label>

                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="radio" name="category" value="international" x-model="category" class="text-blue-600">
                        <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Internationale Rekorde</span>
                        <span class="text-xs text-zinc-400">WR, ER, OR</span>
                    </label>

                    {{-- Verbands-Auswahl — nur bei "regional" sichtbar --}}
                    <div x-show="category === 'regional'"
                         x-transition
                         class="ml-6 mt-1 p-4 bg-zinc-50 dark:bg-zinc-900/50 border border-zinc-200 dark:border-zinc-700 rounded-lg">
                        <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-3 uppercase tracking-wide">
                            Verbände <span class="normal-case font-normal">(leer = alle exportieren)</span>
                        </p>
                        <div class="grid grid-cols-2 gap-y-2 gap-x-6">
                            @foreach ($regionalTypes as $code => $name)
                                <label class="flex items-start gap-2 cursor-pointer">
                                    <input type="checkbox"
                                           name="associations[]"
                                           value="{{ $code }}"
                                           class="mt-0.5 rounded text-blue-600">
                                    <span class="text-sm text-zinc-700 dark:text-zinc-300">
                                        <span class="font-mono text-xs text-zinc-400 mr-1">{{ $code }}</span>{{ $name }}
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            {{-- ── Bahn ───────────────────────────────────────────────────── --}}
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 mb-4">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 mb-1">Bahn</h2>
                <p class="text-xs text-zinc-400 mb-4">Leer lassen = alle Bahnen exportieren</p>

                <div class="flex flex-wrap gap-5">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="courses[]" value="LCM" class="rounded text-blue-600">
                        <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">LCM</span>
                        <span class="text-xs text-zinc-400">50m</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="courses[]" value="SCM" class="rounded text-blue-600">
                        <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">SCM</span>
                        <span class="text-xs text-zinc-400">25m</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="courses[]" value="SCY" class="rounded text-blue-600">
                        <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">SCY</span>
                        <span class="text-xs text-zinc-400">25 Yards</span>
                    </label>
                </div>
            </div>

            {{-- ── Geschlecht ─────────────────────────────────────────────── --}}
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 mb-6">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 mb-4">Geschlecht</h2>

                <div class="flex flex-wrap gap-5">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="gender" value="" checked class="text-blue-600">
                        <span class="text-sm text-zinc-900 dark:text-zinc-100">Alle</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="gender" value="M" class="text-blue-600">
                        <span class="text-sm text-zinc-900 dark:text-zinc-100">Männlich</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="gender" value="F" class="text-blue-600">
                        <span class="text-sm text-zinc-900 dark:text-zinc-100">Weiblich</span>
                    </label>
                </div>
            </div>

            {{-- ── Submit ─────────────────────────────────────────────────── --}}
            <flux:button type="submit" variant="primary" icon="arrow-down-tray">
                LENEX exportieren
            </flux:button>

        </form>
    </div>
@endsection
