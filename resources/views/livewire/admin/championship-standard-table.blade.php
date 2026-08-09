<div>
    @php($istAdmin = auth()->user()?->is_admin)
    @php($punkte = $this->pointsByStandard())

    @if($statusMessage)
        <div
            class="mb-4 p-3 bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800 rounded-xl text-sm text-blue-700 dark:text-blue-400">
            {{ $statusMessage }}
        </div>
    @endif

    @if($this->pointVersion() === null)
        <div
            class="mb-4 p-3 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800 rounded-xl text-sm text-amber-700 dark:text-amber-400">
            Für das Ende des Qualifikationszeitraums ist keine aktive WPS-Punkteversion hinterlegt.
            Die Punktspalten bleiben deshalb leer.
        </div>
    @else
        <p class="mb-4 text-xs text-zinc-500 dark:text-zinc-400">
            Punkte berechnet mit {{ $this->pointVersion()->label }},
            Bahnlänge {{ $championship->course }}.
        </p>
    @endif

    @if($istAdmin)
        <p class="mb-4 text-xs text-zinc-500 dark:text-zinc-400">
            Zeitfelder: nur Ziffern tippen — „011319" wird zu 01:13.19. Gespeichert wird beim
            Verlassen des Feldes oder mit Enter.
        </p>
    @endif

    {{-- ── Filter ──────────────────────────────────────────────────────────── --}}
    <div class="mb-4 flex flex-wrap items-end gap-3">
        <flux:field class="w-48">
            <flux:label>Bewerb</flux:label>
            <flux:select x-on:change="$wire.setFilter('stroke', $event.target.value)">
                <option value="">Alle</option>
                @foreach($this->strokeTypes() as $strokeType)
                    <option value="{{ $strokeType->id }}"
                        @selected($filterStroke === (string) $strokeType->id)>{{ $strokeType->name_de }}</option>
                @endforeach
            </flux:select>
        </flux:field>

        <flux:field class="w-36">
            <flux:label>Geschlecht</flux:label>
            <flux:select x-on:change="$wire.setFilter('gender', $event.target.value)">
                <option value="">Alle</option>
                <option value="M" @selected($filterGender === 'M')>männlich</option>
                <option value="F" @selected($filterGender === 'F')>weiblich</option>
            </flux:select>
        </flux:field>

        <flux:field class="w-36">
            <flux:label>Sportklasse</flux:label>
            <flux:select x-on:change="$wire.setFilter('sportClass', $event.target.value)">
                <option value="">Alle</option>
                @foreach($this->availableSportClasses() as $sportClass)
                    <option value="{{ $sportClass }}"
                        @selected($filterSportClass === $sportClass)>{{ $sportClass }}</option>
                @endforeach
            </flux:select>
        </flux:field>

        <flux:button wire:click="resetFilters" variant="ghost" size="sm">Filter zurücksetzen</flux:button>
    </div>

    @if($istAdmin)
        {{-- ── Massenaktion ────────────────────────────────────────────────── --}}
        <div
            class="mb-4 p-4 bg-zinc-50 dark:bg-zinc-900/40 border border-zinc-200 dark:border-zinc-700 rounded-xl">
            <div class="flex flex-wrap items-end gap-3">
                <flux:field class="w-40">
                    <flux:label>ÖBSV-Verschärfung</flux:label>
                    <flux:input x-model="$wire.bulkPercent" placeholder="z.B. 2" type="number" step="0.01"/>
                </flux:field>
                <flux:button wire:click="applyBulkPercent" variant="primary" size="sm">
                    Auf alle offenen Zeilen anwenden
                </flux:button>
            </div>
            <flux:error name="bulkPercent"/>
            <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                Wirkt auf alle offenen Zeilen der Meisterschaft — auch auf die, die der Filter
                gerade ausblendet oder die auf einer anderen Seite stehen. Von Hand gesetzte
                Zeiten und bewusst auf 0 gesetzte Zeilen bleiben unverändert.
            </p>
        </div>
    @endif

    {{-- ── Normtabelle ─────────────────────────────────────────────────────── --}}
    <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-x-auto">
        <table class="w-full text-sm border-collapse">
            <thead>
            <tr class="border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900/40 text-left">
                <th class="px-4 py-2 font-medium text-zinc-600 dark:text-zinc-400">Bewerb</th>
                <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400">Klasse</th>
                <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400">MQS</th>
                <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400 text-right">Pkt.</th>
                <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400">MET</th>
                <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400">%</th>
                <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400">ÖBSV-Norm</th>
                <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400">Status</th>
                <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400 text-right">Pkt.</th>
                <th class="px-3 py-2"></th>
            </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700/50">
            @forelse($this->standards() as $standard)
                <tr wire:key="standard-{{ $standard->id }}"
                    @class(['bg-amber-50/40 dark:bg-amber-950/10' => $standard->isObsvOpen()])>
                    <td class="px-4 py-1.5 whitespace-nowrap text-zinc-900 dark:text-zinc-100">
                        {{ $standard->distance }} m {{ $standard->strokeType?->name_de }}
                    </td>
                    <td class="px-3 py-1.5 whitespace-nowrap font-mono text-xs text-zinc-900 dark:text-zinc-100">
                        {{ $standard->sport_class }}
                        <span class="text-zinc-500 dark:text-zinc-400">{{ $standard->gender === 'M' ? 'm' : 'w' }}</span>
                    </td>

                    <td class="px-3 py-1">
                        <x-championship-standard-cell
                            :standard="$standard"
                            field="mqs"
                            :value="$rows[$standard->getKey()]['mqs'] ?? ''"
                            :masked="true"
                            :editable="$istAdmin"
                            :display="$standard->formatted_mqs"
                            width="w-32"
                            placeholder="__:__.__"/>
                    </td>
                    <td class="px-3 py-1.5 text-right font-mono text-xs text-zinc-500">
                        {{ $punkte[$standard->getKey()]['mqs'] ?? '' }}
                    </td>

                    <td class="px-3 py-1">
                        <x-championship-standard-cell
                            :standard="$standard"
                            field="met"
                            :value="$rows[$standard->getKey()]['met'] ?? ''"
                            :masked="true"
                            :editable="$istAdmin"
                            :display="$standard->formatted_met"
                            width="w-32"
                            placeholder="__:__.__"/>
                    </td>

                    <td class="px-3 py-1">
                        <x-championship-standard-cell
                            :standard="$standard"
                            field="percent"
                            :value="$rows[$standard->getKey()]['percent'] ?? ''"
                            :masked="false"
                            :editable="$istAdmin"
                            :display="$standard->hasObsvPercent() ? $standard->obsv_percent.' %' : 'offen'"
                            width="w-20"
                            placeholder="offen"/>
                    </td>

                    <td class="px-3 py-1">
                        <x-championship-standard-cell
                            :standard="$standard"
                            field="obsv"
                            :value="$rows[$standard->getKey()]['obsv'] ?? ''"
                            :masked="true"
                            :editable="$istAdmin"
                            :display="$standard->formatted_obsv"
                            width="w-32"
                            placeholder="__:__.__"/>
                    </td>
                    <td class="px-3 py-1.5 whitespace-nowrap">
                        @if($standard->isObsvManual())
                            <flux:badge color="amber" size="sm">von Hand</flux:badge>
                        @elseif($standard->isObsvOpen())
                            <flux:badge color="zinc" size="sm">offen</flux:badge>
                        @endif
                    </td>
                    <td class="px-3 py-1.5 text-right font-mono text-xs text-zinc-500">
                        {{ $punkte[$standard->getKey()]['obsv'] ?? '' }}
                    </td>

                    <td class="px-3 py-1.5 text-right">
                        @if($istAdmin)
                            <flux:button wire:click="deleteRow({{ $standard->id }})"
                                         wire:confirm="Diese Norm wirklich löschen?"
                                         variant="ghost" size="sm" icon="trash"/>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" class="px-4 py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">
                        Keine Normen — mit dem Filter oder dem Formular unten beginnen.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if($this->standards()->hasPages())
        <div class="mt-4">
            {{ $this->standards()->links() }}
        </div>
    @endif

    @if($istAdmin)
        {{-- ── Neue Zeile ──────────────────────────────────────────────────── --}}
        <div class="mt-6 p-4 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl">
            <h3 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300 mb-3">Norm hinzufügen</h3>
            <div class="flex flex-wrap items-end gap-3">
                <flux:field class="w-48">
                    <flux:label>Bewerb</flux:label>
                    <flux:select x-on:change="$wire.newStrokeTypeId = $event.target.value">
                        <option value="">Bitte wählen</option>
                        @foreach($this->strokeTypes() as $strokeType)
                            <option value="{{ $strokeType->id }}"
                                @selected($newStrokeTypeId === (string) $strokeType->id)>{{ $strokeType->name_de }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="newStrokeTypeId"/>
                </flux:field>

                <flux:field class="w-28">
                    <flux:label>Strecke</flux:label>
                    <flux:input x-model="$wire.newDistance" type="number" placeholder="100"/>
                    <flux:error name="newDistance"/>
                </flux:field>

                <flux:field class="w-36">
                    <flux:label>Geschlecht</flux:label>
                    <flux:select x-on:change="$wire.newGender = $event.target.value">
                        <option value="M" @selected($newGender === 'M')>männlich</option>
                        <option value="F" @selected($newGender === 'F')>weiblich</option>
                    </flux:select>
                </flux:field>

                <flux:field class="w-32">
                    <flux:label>Sportklasse</flux:label>
                    <flux:input x-model="$wire.newSportClass" placeholder="S7"/>
                    <flux:error name="newSportClass"/>
                </flux:field>

                <flux:button wire:click="addRow" variant="primary" size="sm" icon="plus">Hinzufügen</flux:button>
            </div>
            <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                Ein leeres Zeitfeld bedeutet „nicht ausgeschrieben".
            </p>
        </div>
    @endif
</div>
