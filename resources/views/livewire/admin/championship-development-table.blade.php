<div>
    @php
        use App\Support\TimeParser;
    @endphp

    <div
        class="mb-4 p-4 bg-zinc-50 dark:bg-zinc-900/40 border border-zinc-200 dark:border-zinc-700 rounded-xl text-sm text-zinc-600 dark:text-zinc-400">
        Planungswerkzeug, kein Nachweis. Umgerechnete Kurzbahnzeiten sind als „rechnerisch
        erreicht" gekennzeichnet und qualifizieren niemanden. Der Abstand zur Norm wird auch bei
        Nichterfüllung ausgewiesen — er ist die eigentliche Information für die
        Förderentscheidung.
    </div>

    {{-- ── Filter ──────────────────────────────────────────────────────────── --}}
    <div class="mb-4 flex flex-wrap items-end gap-3">
        <flux:field class="w-48">
            <flux:label>Kaderart</flux:label>
            <flux:select x-on:change="$wire.setFilter('kader', $event.target.value)">
                <option value="">Alle</option>
                @foreach($this->kaderTypes() as $kaderType)
                    <option value="{{ $kaderType->name_de }}"
                        @selected($filterKader === $kaderType->name_de)>{{ $kaderType->name_de }}</option>
                @endforeach
            </flux:select>
        </flux:field>

        <flux:field class="w-56">
            <flux:label>Athlet suchen</flux:label>
            <flux:input x-model="$wire.search" placeholder="Name"/>
        </flux:field>

        <flux:button wire:click="resetFilters" variant="ghost" size="sm">Zurücksetzen</flux:button>

        <flux:button
            href="{{ $this->pdfUrl() }}"
            variant="filled" size="sm" icon="document-arrow-down">PDF
        </flux:button>
    </div>

    {{-- ── Auswahl ─────────────────────────────────────────────────────────── --}}
    <div
        class="mb-4 p-3 flex flex-wrap items-center gap-3 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl text-sm">
        @if($selected === [])
            <span class="text-zinc-500 dark:text-zinc-400">
                Keine Athleten ausgewählt — das PDF enthält alle {{ $this->page()->total() }} angezeigten.
            </span>
        @else
            <span class="font-medium text-zinc-700 dark:text-zinc-300">
                {{ count($selected) }} Athlet(en) ausgewählt
            </span>
            <flux:button wire:click="toggleOnlySelected" variant="ghost" size="sm">
                {{ $onlySelected ? 'Alle anzeigen' : 'Nur Auswahl anzeigen' }}
            </flux:button>
            <flux:button wire:click="clearSelection" variant="ghost" size="sm">Auswahl aufheben</flux:button>
        @endif

        <flux:button wire:click="selectPage" variant="ghost" size="sm">Seite auswählen</flux:button>
    </div>

    @if($this->page()->total() > 0)
        <p class="mb-4 text-xs text-zinc-500 dark:text-zinc-400">
            {{ $this->page()->firstItem() }}–{{ $this->page()->lastItem() }} von
            {{ $this->page()->total() }} Athleten
        </p>
    @endif

    @forelse($this->page() as $eintrag)
        <div wire:key="athlete-{{ $eintrag->athlete->id }}"
             class="mb-6 bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
            <div class="px-4 py-3 border-b border-zinc-200 dark:border-zinc-700 flex items-start gap-3">
                <flux:checkbox wire:click="toggleAthlete({{ $eintrag->athlete->id }})"
                               :checked="$this->isSelected($eintrag->athlete->id)"/>
                <div>
                    <span class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                        {{ $eintrag->athlete->full_name }}
                    </span>
                    <span class="block text-xs text-zinc-500 dark:text-zinc-400">
                        {{ $eintrag->athlete->gender === 'M' ? 'männlich' : 'weiblich' }}
                        · {{ $eintrag->displaySportClass() ?? '–' }}
                        · {{ $eintrag->athlete->club?->name }}
                        @if($eintrag->kaderName)
                            · {{ $eintrag->kaderName }}
                        @endif
                    </span>
                </div>
            </div>

            <flux:table class="[&_td:first-child]:ps-4 [&_th:first-child]:ps-4">
                <flux:table.columns>
                    <flux:table.column>Bewerb</flux:table.column>
                    <flux:table.column>Leistung</flux:table.column>
                    <flux:table.column>MQS</flux:table.column>
                    <flux:table.column>ÖBSV-Norm</flux:table.column>
                    <flux:table.column>Ziel {{ $championship->course === 'LCM' ? 'SCM' : 'LCM' }}</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach($eintrag->rows as $zeile)
                        @php($status = $zeile->status)
                        <flux:table.row>
                            <flux:table.cell class="whitespace-nowrap">
                                {{ $zeile->eventLabel }}
                                <span class="font-mono text-xs text-zinc-500">{{ $zeile->sportClass }}</span>
                            </flux:table.cell>

                            <flux:table.cell class="font-mono text-xs">
                                @if($status->swimTime === null)
                                    <span class="text-zinc-400">–</span>
                                @else
                                    {{ TimeParser::display($status->swimTime) }} {{ $status->course }}
                                    @if($status->estimatedLcmTime !== null)
                                        <span class="block text-amber-600 dark:text-amber-400">
                                            → {{ TimeParser::display($status->estimatedLcmTime) }}
                                            {{ $championship->course }} geschätzt
                                            (Faktor {{ number_format($status->conversionFactor, 4, ',', '.') }})
                                        </span>
                                    @endif
                                @endif
                            </flux:table.cell>

                            <flux:table.cell class="font-mono text-xs">
                                {{ $zeile->standard?->formatted_mqs ?? '–' }}
                            </flux:table.cell>
                            <flux:table.cell class="font-mono text-xs">
                                {{ $zeile->standard?->formatted_obsv ?? '–' }}
                            </flux:table.cell>
                            <flux:table.cell class="font-mono text-xs">
                                @if($zeile->targetTimeOtherCourse === null)
                                    <span class="text-zinc-400">–</span>
                                @else
                                    {{ TimeParser::display($zeile->targetTimeOtherCourse) }}
                                @endif
                            </flux:table.cell>

                            <flux:table.cell>
                                <flux:badge color="{{ $status->colour() }}" size="sm">
                                    {{ $status->label() }}
                                </flux:badge>

                                @if($status->formattedGap())
                                    <span class="block mt-0.5 font-mono text-xs text-zinc-500">
                                        Abstand: {{ $status->formattedGap() }}
                                    </span>
                                @endif

                                @if($zeile->metUsable === true)
                                    <span class="block mt-0.5 text-xs text-zinc-500">MET verwertbar</span>
                                @elseif($zeile->metUsable === false)
                                    <span class="block mt-0.5 text-xs text-zinc-500">MET ohne MQS — wirkungslos</span>
                                @endif

                                @if($status->swimTime !== null && ! $status->meetApproved)
                                    <span class="block mt-0.5 text-xs text-amber-600 dark:text-amber-400">
                                        Wettkampf nicht WPS-anerkannt
                                    </span>
                                @endif

                                @if($status->exhibition)
                                    <span class="block mt-0.5 text-xs text-zinc-500">außer Konkurrenz (EXH)</span>
                                @endif

                                @if($status->note)
                                    <span class="block mt-0.5 text-xs text-zinc-500">{{ $status->note }}</span>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>

            @php($ohneNorm = $this->eventsWithoutStandard($eintrag))

            @if($ohneNorm->isNotEmpty())
                <p class="px-4 py-2 border-t border-zinc-200 dark:border-zinc-700 text-xs text-zinc-500 dark:text-zinc-400">
                    {{ $ohneNorm->count() }} weitere(r) Bewerb(e) ohne ausgeschriebene Norm:
                    {{ $ohneNorm->join(', ') }}
                </p>
            @endif
        </div>
    @empty
        <div
            class="p-6 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl text-sm text-zinc-500 dark:text-zinc-400">
            Keine Athleten mit Ergebnissen im Qualifikationszeitraum.
        </div>
    @endforelse

    @if($this->page()->hasPages())
        <div class="mt-6">
            {{ $this->page()->links() }}
        </div>
    @endif
</div>
