@extends('layouts.app')

@section('title', 'LENEX Export')

@section('content')
    <div class="max-w-xl" x-data="{ tab: 'meet' }">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100 mb-6">LENEX Export</h1>

        {{-- ── Tab-Switcher ────────────────────────────────────────────────── --}}
        <div class="flex gap-1 mb-6 p-1 bg-zinc-100 dark:bg-zinc-800 rounded-lg w-fit">
            <button type="button"
                    @click="tab = 'meet'"
                    :class="tab === 'meet'
                        ? 'bg-white dark:bg-zinc-700 text-zinc-900 dark:text-zinc-100 shadow-sm'
                        : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200'"
                    class="px-4 py-1.5 text-sm font-medium rounded-md transition-all">
                Wettkampf
            </button>
            <button type="button"
                    @click="tab = 'records'"
                    :class="tab === 'records'
                        ? 'bg-white dark:bg-zinc-700 text-zinc-900 dark:text-zinc-100 shadow-sm'
                        : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200'"
                    class="px-4 py-1.5 text-sm font-medium rounded-md transition-all">
                Rekorde
            </button>
        </div>

        {{-- ── Tab: Wettkampf-Export (bestehend) ─────────────────────────── --}}
        <div x-show="tab === 'meet'" x-transition>
            <form method="POST" action="{{ route('lenex.export.download') }}">
                @csrf
                <div
                    class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-5">
                    <flux:field>
                        <flux:label>Wettkampf *</flux:label>
                        <flux:select name="meet_id" required>
                            <option value="">Bitte wählen…</option>
                            @foreach($meets as $meet)
                                <option value="{{ $meet->id }}">{{ $meet->name }} ({{ $meet->start_date->format('Y') }}
                                    )
                                </option>
                            @endforeach
                        </flux:select>
                        <flux:error name="meet_id"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Export-Typ *</flux:label>
                        <div class="space-y-2 mt-1">
                            @foreach([
                                'structure' => ['Struktur',   'Nur Meet, Sessions und Disziplinen'],
                                'entries'   => ['Meldungen',  '+ Vereine, Athleten und Meldungen'],
                                'results'   => ['Ergebnisse', '+ Ergebnisse und Splitzeiten'],
                            ] as $val => [$label, $desc])
                                <label
                                    class="flex items-start gap-3 p-3 rounded-lg border border-zinc-200 dark:border-zinc-700 cursor-pointer hover:border-blue-400 transition-colors has-checked:border-blue-500 has-checked:bg-blue-50 dark:has-checked:bg-blue-950/20">
                                    <input type="radio" name="export_type" value="{{ $val }}"
                                           class="mt-0.5 text-blue-600"
                                           @if($val === 'results') checked @endif>
                                    <div>
                                        <div
                                            class="font-medium text-sm text-zinc-900 dark:text-zinc-100">{{ $label }}</div>
                                        <div class="text-xs text-zinc-400 mt-0.5">{{ $desc }}</div>
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    </flux:field>
                </div>
                <flux:button type="submit" variant="primary" icon="arrow-down-tray" class="mt-6">
                    LENEX Datei herunterladen
                </flux:button>
            </form>
        </div>

        {{-- ── Tab: Rekord-Export (neu) ───────────────────────────────────── --}}
        <div x-show="tab === 'records'" x-transition x-data="{ category: 'national' }">
            <form method="POST" action="{{ route('records.export.download') }}">
                @csrf
                <div
                    class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-5">

                    {{-- Kategorie --}}
                    <flux:field>
                        <flux:label>Rekord-Kategorie *</flux:label>
                        <div class="space-y-2 mt-1">
                            @foreach([
                                'national'      => ['Nationale Rekorde',      'AUT + AUT Jugend'],
                                'regional'      => ['Regionale Rekorde',      'Landesverbände inkl. Jugend'],
                                'international' => ['Internationale Rekorde', 'WR, ER, OR'],
                            ] as $val => [$label, $desc])
                                <label
                                    class="flex items-start gap-3 p-3 rounded-lg border border-zinc-200 dark:border-zinc-700 cursor-pointer hover:border-blue-400 transition-colors has-checked:border-blue-500 has-checked:bg-blue-50 dark:has-checked:bg-blue-950/20">
                                    <input type="radio" name="category" value="{{ $val }}"
                                           x-model="category"
                                           class="mt-0.5 text-blue-600"
                                           @if($val === 'national') checked @endif>
                                    <div>
                                        <div
                                            class="font-medium text-sm text-zinc-900 dark:text-zinc-100">{{ $label }}</div>
                                        <div class="text-xs text-zinc-400 mt-0.5">{{ $desc }}</div>
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    </flux:field>

                    {{-- Verbands-Auswahl — nur bei regional --}}
                    <div x-show="category === 'regional'" x-transition>
                        <flux:field>
                            <flux:label>Verbände <span class="font-normal text-zinc-400">(leer = alle)</span>
                            </flux:label>
                            <div class="grid grid-cols-2 gap-y-2 gap-x-4 mt-1">
                                @foreach ($regionalTypes as $code => $name)
                                    <label class="flex items-start gap-2 cursor-pointer">
                                        <input type="checkbox" name="associations[]" value="{{ $code }}"
                                               class="mt-0.5 rounded text-blue-600">
                                        <span class="text-sm text-zinc-700 dark:text-zinc-300">
                                            <span class="font-mono text-xs text-zinc-400 mr-1">{{ $code }}</span>{{ $name }}
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </flux:field>
                    </div>

                    {{-- Bahn --}}
                    <flux:field>
                        <flux:label>Bahn <span class="font-normal text-zinc-400">(leer = alle)</span></flux:label>
                        <div class="flex flex-wrap gap-4 mt-1">
                            @foreach(['LCM' => '50m', 'SCM' => '25m', 'SCY' => '25 Yards'] as $val => $desc)
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" name="courses[]" value="{{ $val }}"
                                           class="rounded text-blue-600">
                                    <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $val }}</span>
                                    <span class="text-xs text-zinc-400">{{ $desc }}</span>
                                </label>
                            @endforeach
                        </div>
                    </flux:field>

                    {{-- Geschlecht --}}
                    <flux:field>
                        <flux:label>Geschlecht</flux:label>
                        <div class="flex flex-wrap gap-4 mt-1">
                            @foreach(['' => 'Alle', 'M' => 'Männlich', 'F' => 'Weiblich'] as $val => $label)
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" name="gender" value="{{ $val }}"
                                           class="text-blue-600"
                                           @if($val === '') checked @endif>
                                    <span class="text-sm text-zinc-900 dark:text-zinc-100">{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </flux:field>

                </div>
                <flux:button type="submit" variant="primary" icon="arrow-down-tray" class="mt-6">
                    Rekorde exportieren
                </flux:button>
            </form>
        </div>

    </div>
@endsection
