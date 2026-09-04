<div>
    @php
        $cup = $this->cup();
        $systemLabel = $this->system === 'start' ? 'Startwertung' : 'Leistungswertung';
        $fmt = fn ($v) => number_format((float) $v, 2, ',', '.');
    @endphp

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Vereinswertung</h1>
        <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
            {{ $cup->name }} · {{ $systemLabel }}
            @if($this->system === 'performance' && $this->calculatedAt())
                · Tageswertungen zuletzt berechnet am {{ $this->calculatedAt()->format('d.m.Y H:i') }} Uhr
            @endif
        </p>

        <div class="flex items-center flex-wrap gap-2 mt-4">
            <flux:button href="{{ route('cups.club-ranking.index') }}" variant="filled" icon="arrow-left" size="sm">
                Zurück
            </flux:button>

            @unless($this->ranking()->isEmpty())
                <div class="ml-auto flex items-center flex-wrap gap-2">
                    @if($this->system === 'performance')
                        <flux:button href="{{ $this->pdfUrl(0) }}" variant="filled" size="sm"
                                     icon="arrow-down-tray" class="text-purple-500!">
                            PDF Übersicht
                        </flux:button>
                        <flux:button href="{{ $this->pdfUrl(1) }}" variant="filled" size="sm"
                                     icon="arrow-down-tray" class="text-purple-500!">
                            PDF mit Athleten
                        </flux:button>
                    @else
                        <flux:button href="{{ $this->pdfUrl(0) }}" variant="filled" size="sm"
                                     icon="arrow-down-tray" class="text-purple-500!">
                            PDF
                        </flux:button>
                    @endif
                </div>
            @endunless
        </div>
    </div>

    {{-- Cup/Jahr und Kaderathleten je Verein bleiben Dropdowns (Erik, 04.09.2026: "bleiben
         dropdown"); nur Wertungssystem und Ausländische Vereine dazwischen sind Buttons.
         x-model + $watch statt x-on:change direkt am flux:select: Custom Element <ui-select>
         feuert sein internes "change"-Event mit bubbles:false, kommt darüber nicht zuverlässig
         an (siehe resources/js/wps-livewire-filters.js). --}}
    <div x-data="wpsLivewireFilters(@js(['cupId' => $cupId, 'kaderCount' => $kaderCount]), 'setFilter')">
        <div class="flex flex-wrap items-end gap-4 mb-4">
            <flux:field class="w-64">
                <flux:label>Cup / Jahr</flux:label>
                <flux:select variant="listbox" x-model="cupId">
                    @foreach($this->cups() as $c)
                        <flux:select.option value="{{ $c->id }}">{{ $c->year }} — {{ $c->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            {{-- variant="filled" statt "ghost" im inaktiven Zustand: ghost hatte im Dunkelmodus
                 praktisch keinen sichtbaren Rahmen/Hintergrund und sah wie reiner Text statt
                 einem Button aus (Erik, 04.09.2026: "als Button darstellen nicht als Text"). --}}
            <div>
                <span class="block text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">Wertungssystem</span>
                <div class="flex gap-1">
                    <flux:button wire:click="setSystem('start')" size="sm"
                                 :variant="$system === 'start' ? 'primary' : 'filled'">
                        Startwertung
                    </flux:button>
                    <flux:button wire:click="setSystem('performance')" size="sm"
                                 :variant="$system === 'performance' ? 'primary' : 'filled'">
                        Leistungswertung
                    </flux:button>
                </div>
            </div>

            <div>
                <span class="block text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">
                    Ausländische Vereine
                </span>
                {{-- Kein Icon im ausgeschlossenen Zustand: "plus" suggerierte fälschlich eine
                     Hinzufügen-Aktion statt eines reinen An/Aus-Zustands (Erik, 04.09.2026: "Ist
                     verwirrend"). Eingeschlossen bleibt das Häkchen als klares Zustands-Symbol. --}}
                <flux:button wire:click="toggleForeign" size="sm"
                             :variant="$includeForeign ? 'primary' : 'filled'"
                             :icon="$includeForeign ? 'check' : null">
                    {{ $includeForeign ? 'einbezogen' : 'ausgeschlossen' }}
                </flux:button>
            </div>

            @if($system === 'performance')
                <flux:field class="w-48">
                    <flux:label>Kaderathleten je Verein</flux:label>
                    <flux:select variant="listbox" x-model="kaderCount">
                        @for($i = 0; $i <= $this->maxCountedAthletes(); $i++)
                            <flux:select.option value="{{ $i }}">{{ $i === 0 ? 'keine' : $i }}</flux:select.option>
                        @endfor
                    </flux:select>
                </flux:field>
            @endif
        </div>
    </div>

    {{-- Staleness-Hinweis (nur Leistungswertung) --}}
    @if($this->isStale())
        <div
            class="mb-4 p-4 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800 rounded-xl text-sm text-amber-700 dark:text-amber-400 flex items-start gap-2">
            <flux:icon name="exclamation-triangle" class="w-4 h-4 mt-0.5 shrink-0"/>
            <span>{{ $this->staleReason() }} Bitte die Tageswertung der betroffenen Meets neu berechnen.</span>
        </div>
    @endif

    @if($this->ranking()->isEmpty())
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-8 text-center">
            <p class="text-sm text-zinc-400">
                Noch keine {{ $systemLabel }} verfügbar.
                @if($system === 'performance')
                    Für die Leistungswertung müssen zunächst die Tageswertungen der Cup-Meets berechnet werden.
                @endif
            </p>
        </div>
    @elseif($system === 'start')
        {{-- ── Startwertung (System A) ─────────────────────────────────── --}}
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
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
                    @foreach($this->ranking() as $row)
                        <tr wire:key="start-{{ $row->clubName }}"
                            class="border-b border-zinc-50 dark:border-zinc-700/50 hover:bg-zinc-50 dark:hover:bg-zinc-700/30">
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
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
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
                    @foreach($this->ranking() as $row)
                        <tbody wire:key="performance-{{ $row->clubName }}" x-data="{ open: false }"
                               class="border-b border-zinc-50 dark:border-zinc-700/50">
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-700/30 cursor-pointer" @click="open = ! open">
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
                                            <td class="py-1 pe-3">
                                                {{ $athlete->athleteName }}
                                                @if($athlete->isKader)
                                                    <span
                                                        class="ms-1 inline-block rounded bg-indigo-100 dark:bg-indigo-950/40 text-indigo-700 dark:text-indigo-300 px-1.5 py-0.5 text-[10px] font-medium align-middle">Kader</span>
                                                @endif
                                            </td>
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
