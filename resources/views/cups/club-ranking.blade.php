@extends('layouts.app')

@section('title', 'Vereinswertung — '.$cup->name)

@section('content')
    <style>[x-cloak] {
            display: none !important
        }</style>

    @php
        $foreignFlag = $includeForeign ? 1 : 0;
        $linkFor = fn (string $sys, int $foreign) => route('cups.club-ranking.show', [
            'cup' => $cup, 'system' => $sys, 'foreign' => $foreign,
        ]);
        $systemLabel = $system === 'start' ? 'Startwertung' : 'Leistungswertung';
        $fmt = fn ($v) => number_format((float) $v, 2, ',', '.');
    @endphp

    <div class="max-w-5xl">
        <div class="flex items-start justify-between mb-6">
            <div class="flex items-center gap-3">
                <flux:button href="{{ route('cups.club-ranking.index') }}" variant="ghost" icon="arrow-left" size="sm"/>
                <div>
                    <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Vereinswertung</h1>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                        {{ $cup->name }} · {{ $systemLabel }}
                        @if($system === 'performance' && $calculatedAt)
                            · Tageswertungen zuletzt berechnet am {{ $calculatedAt->format('d.m.Y H:i') }} Uhr
                        @endif
                    </p>
                </div>
            </div>
        </div>

        {{-- Filterleiste --}}
        <div class="flex flex-wrap items-end gap-4 mb-4">
            <div>
                <label class="block text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">Cup / Jahr</label>
                <select onchange="window.location.href=this.value"
                        class="rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-sm px-3 py-2 text-zinc-900 dark:text-zinc-100">
                    @foreach($cups as $c)
                        <option
                            value="{{ route('cups.club-ranking.show', ['cup' => $c, 'system' => $system, 'foreign' => $foreignFlag]) }}"
                            @selected($c->id === $cup->id)>
                            {{ $c->year }} — {{ $c->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <span class="block text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">Wertungssystem</span>
                <div class="flex gap-1">
                    <flux:button href="{{ $linkFor('start', $foreignFlag) }}" size="sm"
                                 :variant="$system === 'start' ? 'primary' : 'ghost'">
                        Startwertung
                    </flux:button>
                    <flux:button href="{{ $linkFor('performance', $foreignFlag) }}" size="sm"
                                 :variant="$system === 'performance' ? 'primary' : 'ghost'">
                        Leistungswertung
                    </flux:button>
                </div>
            </div>

            <div>
                <span
                    class="block text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">Ausländische Vereine</span>
                <flux:button href="{{ $linkFor($system, $includeForeign ? 0 : 1) }}" size="sm"
                             :variant="$includeForeign ? 'primary' : 'ghost'"
                             :icon="$includeForeign ? 'check' : 'plus'">
                    {{ $includeForeign ? 'einbezogen' : 'ausgeschlossen' }}
                </flux:button>
            </div>
        </div>

        {{-- Staleness-Hinweis (nur Leistungswertung) --}}
        @if($system === 'performance' && $isStale)
            <div
                class="mb-4 p-4 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800 rounded-xl text-sm text-amber-700 dark:text-amber-400 flex items-start gap-2">
                <flux:icon name="exclamation-triangle" class="w-4 h-4 mt-0.5 shrink-0"/>
                <span>{{ $staleReason }} Bitte die Tageswertung der betroffenen Meets neu berechnen.</span>
            </div>
        @endif

        @if($ranking->isEmpty())
            <div
                class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-8 text-center">
                <p class="text-sm text-zinc-400">
                    Noch keine {{ $systemLabel }} verfügbar.
                    @if($system === 'performance')
                        Für die Leistungswertung müssen zunächst die Tageswertungen der Cup-Meets berechnet werden.
                    @endif
                </p>
            </div>
        @elseif($system === 'start')
            {{-- ── Startwertung (System A) ─────────────────────────────────── --}}
            <div
                class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm min-w-140">
                        <thead>
                        <tr class="text-left text-zinc-500 dark:text-zinc-400 border-b border-zinc-100 dark:border-zinc-700">
                            <th class="font-medium px-4 py-3 w-16">Rang</th>
                            <th class="font-medium px-4 py-3">Verein</th>
                            <th class="font-medium px-4 py-3 text-right w-24">Starts</th>
                            <th class="font-medium px-4 py-3 text-right w-32">Athleten</th>
                            <th class="font-medium px-4 py-3 text-right w-32">Cup-Meets</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($ranking as $row)
                            <tr class="border-b border-zinc-50 dark:border-zinc-700/50 hover:bg-zinc-50 dark:hover:bg-zinc-700/30">
                                <td class="px-4 py-3 font-semibold text-zinc-500 dark:text-zinc-400">{{ $row->rank }}</td>
                                <td class="px-4 py-3 font-medium text-zinc-900 dark:text-zinc-100">{{ $row->clubName }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-zinc-900 dark:text-zinc-100">{{ $row->starts }}</td>
                                <td class="px-4 py-3 text-right text-zinc-600 dark:text-zinc-300">{{ $row->athletes }}</td>
                                <td class="px-4 py-3 text-right text-zinc-600 dark:text-zinc-300">{{ $row->meets }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @else
            {{-- ── Leistungswertung (System B) ─────────────────────────────── --}}
            <div
                class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm min-w-160">
                        <thead>
                        <tr class="text-left text-zinc-500 dark:text-zinc-400 border-b border-zinc-100 dark:border-zinc-700">
                            <th class="font-medium px-4 py-3 w-16">Rang</th>
                            <th class="font-medium px-4 py-3">Verein</th>
                            <th class="font-medium px-4 py-3 text-right w-32">Gesamtpunkte</th>
                            <th class="font-medium px-4 py-3 text-right w-36">gew. Athleten</th>
                            <th class="font-medium px-4 py-3 text-right w-32">gew. Meets</th>
                            <th class="font-medium px-4 py-3 w-10"></th>
                        </tr>
                        </thead>
                        @foreach($ranking as $row)
                            <tbody x-data="{ open: false }" class="border-b border-zinc-50 dark:border-zinc-700/50">
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-700/30 cursor-pointer"
                                @click="open = ! open">
                                <td class="px-4 py-3 font-semibold text-zinc-500 dark:text-zinc-400">{{ $row->rank }}</td>
                                <td class="px-4 py-3 font-medium text-zinc-900 dark:text-zinc-100">{{ $row->clubName }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-zinc-900 dark:text-zinc-100">{{ $fmt($row->totalPoints) }}</td>
                                <td class="px-4 py-3 text-right text-zinc-600 dark:text-zinc-300">{{ $row->countedAthletes }}</td>
                                <td class="px-4 py-3 text-right text-zinc-600 dark:text-zinc-300">{{ $row->countedMeets }}</td>
                                <td class="px-4 py-3 text-right">
                                    <flux:icon name="chevron-down" class="w-4 h-4 text-zinc-400 transition-transform"
                                               x-bind:class="open && 'rotate-180'"/>
                                </td>
                            </tr>
                            <tr x-show="open" x-cloak>
                                <td colspan="6" class="px-4 py-3 bg-zinc-50 dark:bg-zinc-900/40">
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400 mb-2">
                                        Gewertete Athleten (beste je Verein), gewichtet nach Position:
                                    </div>
                                    <table class="w-full text-xs">
                                        <thead>
                                        <tr class="text-left text-zinc-400">
                                            <th class="font-medium py-1 pe-3 w-10">#</th>
                                            <th class="font-medium py-1 pe-3">Athlet</th>
                                            <th class="font-medium py-1 pe-3">Meet-Punkte</th>
                                            <th class="font-medium py-1 pe-3 text-right">Saisonwert</th>
                                            <th class="font-medium py-1 pe-3 text-right">Gewicht</th>
                                            <th class="font-medium py-1 text-right">Beitrag</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @foreach($row->athletes as $athlete)
                                            <tr class="text-zinc-600 dark:text-zinc-300">
                                                <td class="py-1 pe-3">{{ $athlete->position }}</td>
                                                <td class="py-1 pe-3">{{ $athlete->athleteName }}</td>
                                                <td class="py-1 pe-3 tabular-nums">{{ implode(' + ', $athlete->meetPoints) }}</td>
                                                <td class="py-1 pe-3 text-right tabular-nums">{{ $athlete->seasonValue }}</td>
                                                <td class="py-1 pe-3 text-right tabular-nums">{{ $fmt($athlete->weight) }}</td>
                                                <td class="py-1 text-right font-semibold text-zinc-900 dark:text-zinc-100 tabular-nums">{{ $fmt($athlete->weightedValue) }}</td>
                                            </tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                            </tbody>
                        @endforeach
                    </table>
                </div>
            </div>
            <p class="text-xs text-zinc-400 mt-3">
                Tipp: Auf eine Vereinszeile klicken, um die gewerteten Athleten und ihren Wertungsbeitrag einzublenden.
            </p>
        @endif
    </div>
@endsection
