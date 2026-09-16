@extends('layouts.app')

@section('title', 'WPS-Analyse')

@section('content')
    <div class="max-w-2xl">
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">WPS-Analyse</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                Athletin oder Athlet wählen, um die Leistungsentwicklung nach WPS-Punkten anzusehen.
            </p>
            <div class="mt-4">
                <flux:button href="{{ route('statistics.index') }}" variant="filled" icon="arrow-left" size="sm">
                    Zurück
                </flux:button>
            </div>
        </div>

        {{-- Kein eigenes, zweites Athleten-Verzeichnis (das gäbe es mit der Athletenverwaltung
             schon) - nur ein durchsuchbares Auswahlfeld, das direkt zur bestehenden Analyse
             weiterleitet. Reines Alpine, kein Livewire: x-model funktioniert hier ohne den
             sonst nötigen Umweg über resources/js/wps-livewire-filters.js (der betrifft nur
             das $wire-Binding einer Livewire-Komponente, nicht lokale Alpine-Zustände). --}}
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6"
             x-data="{ athleteId: '', base: @js(url('/wps/athletes')) }"
             x-init="$watch('athleteId', (v) => { if (v) window.location = base + '/' + v; })">
            <flux:field>
                <flux:label>Athletin / Athlet</flux:label>
                <flux:select variant="listbox" searchable placeholder="Suchen …" x-model="athleteId">
                    @foreach($athletes as $athlete)
                        <flux:select.option value="{{ $athlete->id }}">
                            {{ $athlete->last_name }}, {{ $athlete->first_name }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>
        </div>
    </div>
@endsection
