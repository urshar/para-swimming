<div>
    @php
        $cup = $this->cup();
        $systemLabel = $this->system === 'start' ? 'Startwertung' : 'Leistungswertung';
        $fmt = fn ($v) => number_format((float) $v, 2, ',', '.');
    @endphp

    <div class="mb-6">
        <div class="flex items-center gap-2">
            <flux:button href="{{ route('cups.club-ranking.index') }}" variant="primary" icon="arrow-left"
                         size="sm" title="Zurück" aria-label="Zurück"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Vereinswertung</h1>
        </div>
        <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
            {{ $cup->name }} · {{ $systemLabel }}
            @if($this->forecastActive())
                · Prognose
            @endif
            @if($this->system === 'performance' && $this->calculatedAt())
                · Tageswertungen zuletzt berechnet am {{ $this->calculatedAt()->format('d.m.Y H:i') }} Uhr
            @endif
        </p>

        {{-- Kein PDF der Prognose (Spec §9, Phase 5 optional): Der Export zeigt immer die aktuelle
             Wertung, deshalb sind die Buttons bei aktiver Prognose ausgeblendet. --}}
        @unless($this->ranking()->isEmpty() || $this->forecastActive())
            <div class="flex items-center flex-wrap justify-end gap-2 mt-4">
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
                 einem Button aus (Erik, 04.09.2026: "als Button darstellen nicht als Text").
                 Kein size="sm": flux:select ohne eigenes size-Attribut rendert in "default"-Höhe
                 - ein Geschwister-Button mit size="sm" wäre dann niedriger (Erik, 15.09.2026:
                 "gleich hoch wie die Dropdown Felder"). --}}
            <div>
                <span class="block text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">Wertungssystem</span>
                <div class="flex gap-1">
                    <flux:button wire:click="setSystem('start')"
                                 :variant="$system === 'start' ? 'primary' : 'filled'">
                        Startwertung
                    </flux:button>
                    <flux:button wire:click="setSystem('performance')"
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
                <flux:button wire:click="toggleForeign"
                             :variant="$includeForeign ? 'primary' : 'filled'"
                             :icon="$includeForeign ? 'check' : null">
                    {{ $includeForeign ? 'einbezogen' : 'ausgeschlossen' }}
                </flux:button>
            </div>

            {{-- Prognose nur für Admins und nur, wenn der Cup ein Meet mit Meldungen, aber ohne
                 Ergebnisse hat (docs/specs/cup-club-ranking-forecast.md §3/§7). --}}
            @if($this->canForecast())
                <div>
                    <span id="forecast-toggle-label"
                          class="block text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">Prognose</span>
                    <flux:button wire:click="toggleForecast"
                                 aria-labelledby="forecast-toggle-label"
                                 aria-pressed="{{ $this->forecastActive() ? 'true' : 'false' }}"
                                 :variant="$this->forecastActive() ? 'primary' : 'filled'"
                                 :icon="$this->forecastActive() ? 'check' : null">
                        {{ $this->forecastActive() ? 'eingeblendet' : 'ausgeblendet' }}
                    </flux:button>
                </div>
            @endif

            {{-- w-40 statt w-48: Mit dem Prognose-Umschalter passen sonst bei 1024 px Inhaltsbreite
                 nicht mehr alle Filter in eine Zeile (Erik, 07.11.2026). Das Label braucht 148 px. --}}
            @if($system === 'performance')
                <flux:field class="w-40">
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

    {{-- Prognose-Hinweis: worauf die voraussichtliche Endwertung beruht --}}
    @if($this->forecastActive())
        <div role="note"
             class="mb-4 p-4 bg-sky-50 dark:bg-sky-950/20 border border-sky-200 dark:border-sky-800 rounded-xl text-sm text-sky-800 dark:text-sky-300 flex items-start gap-2">
            <flux:icon name="information-circle" class="w-4 h-4 mt-0.5 shrink-0"/>
            <div>
                <p>
                    Voraussichtliche Endwertung: echte Ergebnisse plus die Meldungen von
                    {{ $this->forecastMeets()->map(fn ($m) => $m->name.' ('.$m->start_date?->format('d.m.Y').')')->join(', ') }}.
                    Meldezeiten gelten als erwartete Zeiten; Meldungen ohne Zeit (NT) zählen nur als Start;
                    Abmeldungen und Starts außer Konkurrenz zählen nicht.
                </p>
                @if($this->unratedSummary()->isNotEmpty())
                    <p class="mt-2">
                        Ohne Punkte (nur als Start gezählt):
                        {{ $this->unratedSummary()->map(fn ($count, $reason) => $reason.': '.$count)->join(' · ') }}
                    </p>
                @endif
            </div>
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
                        @if($this->forecastActive())
                            <th class="font-medium px-4 py-3 w-24">Rang jetzt</th>
                        @endif
                        <th class="font-medium px-4 py-3">Verein</th>
                        <th class="font-medium px-4 py-3 text-right w-24">Starts</th>
                        @if($this->forecastActive())
                            <th class="font-medium px-4 py-3 text-right w-28">Differenz</th>
                        @endif
                        <th class="font-medium px-4 py-3 text-right w-32">Athleten</th>
                        <th class="font-medium px-4 py-3 text-right w-32">Cup-Meets</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($this->ranking() as $row)
                        <tr wire:key="start-{{ $row->clubName }}"
                            class="border-b border-zinc-50 dark:border-zinc-700/50 hover:bg-zinc-50 dark:hover:bg-zinc-700/30">
                            <td class="px-4 py-3 font-semibold text-zinc-500 dark:text-zinc-400">{{ $row->rank }}</td>
                            @if($this->forecastActive())
                                @include('partials.cup-club-ranking-forecast-rank', ['currentRank' => $this->comparisonFor($row->clubId)?->currentRank, 'change' => $this->rankChangeDisplay($row->clubId)])
                            @endif
                            <td class="px-4 py-3 font-medium text-zinc-900 dark:text-zinc-100">{{ $row->clubName }}</td>
                            <td class="px-4 py-3 text-right font-semibold text-zinc-900 dark:text-zinc-100">{{ $row->starts }}</td>
                            @if($this->forecastActive())
                                <td class="px-4 py-3 text-right tabular-nums text-zinc-600 dark:text-zinc-300">{{ $this->valueDeltaDisplay($row->clubId) }}</td>
                            @endif
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
                        @if($this->forecastActive())
                            <th class="font-medium px-4 py-3 w-24">Rang jetzt</th>
                        @endif
                        <th class="font-medium px-4 py-3">Verein</th>
                        <th class="font-medium px-4 py-3 text-right w-32">Gesamtpunkte</th>
                        @if($this->forecastActive())
                            <th class="font-medium px-4 py-3 text-right w-32">Differenz</th>
                        @endif
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
                            @if($this->forecastActive())
                                @include('partials.cup-club-ranking-forecast-rank', ['currentRank' => $this->comparisonFor($row->clubId)?->currentRank, 'change' => $this->rankChangeDisplay($row->clubId)])
                            @endif
                            <td class="px-4 py-3 font-medium text-zinc-900 dark:text-zinc-100">{{ $row->clubName }}</td>
                            <td class="px-4 py-3 text-right font-semibold text-zinc-900 dark:text-zinc-100">{{ $fmt($row->totalPoints) }}</td>
                            @if($this->forecastActive())
                                <td class="px-4 py-3 text-right tabular-nums text-zinc-600 dark:text-zinc-300">{{ $this->valueDeltaDisplay($row->clubId) }}</td>
                            @endif
                            <td class="px-4 py-3 text-right text-zinc-600 dark:text-zinc-300">{{ $row->countedAthletes }}</td>
                            <td class="px-4 py-3 text-right text-zinc-600 dark:text-zinc-300">{{ $row->countedMeets }}</td>
                            <td class="px-4 py-3 text-right">
                                <flux:icon name="chevron-down" class="w-4 h-4 text-zinc-400 transition-transform"
                                           x-bind:class="open && 'rotate-180'"/>
                            </td>
                        </tr>
                        <tr x-show="open" x-cloak>
                            <td colspan="{{ $this->forecastActive() ? 8 : 6 }}" class="px-4 py-3 bg-zinc-50 dark:bg-zinc-900/40">
                                <div class="text-xs text-zinc-500 dark:text-zinc-400 mb-2">
                                    Gewertete Athleten (beste je Verein), gewichtet nach Position:
                                </div>
                                <table class="w-full table-fixed text-xs">
                                    <thead>
                                    <tr class="text-left text-zinc-400">
                                        <th class="font-medium py-1 pe-3 w-10">#</th>
                                        <th class="font-medium py-1 pe-3 w-48">Athlet</th>
                                        <th class="font-medium py-1 pe-3">Meet-Punkte</th>
                                        <th class="font-medium py-1 pe-3 text-right w-24">Saisonwert</th>
                                        <th class="font-medium py-1 pe-3 text-right w-20">Gewicht</th>
                                        <th class="font-medium py-1 text-right w-20">Beitrag</th>
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
                                            <td class="py-1 pe-3 tabular-nums">
                                                @foreach($this->meetPointParts($athlete) as $part)
                                                    @unless($loop->first)
                                                        +
                                                    @endunless
                                                    @if($part['note'])
                                                        <span class="italic text-sky-700 dark:text-sky-300">{{ $part['points'] }}
                                                            <span class="not-italic text-[10px]">({{ $part['note'] }})</span></span>
                                                    @else
                                                        {{ $part['points'] }}
                                                    @endif
                                                @endforeach
                                            </td>
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
