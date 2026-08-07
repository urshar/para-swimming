<div>
    @php($istAdmin = auth()->user()?->is_admin)

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

    {{-- ── Filter ──────────────────────────────────────────────────────────── --}}
    <div class="mb-4 flex flex-wrap items-end gap-3">
        <flux:field class="w-48">
            <flux:label>Bewerb</flux:label>
            <flux:select x-model="$wire.filterStroke">
                <option value="">Alle</option>
                @foreach($this->strokeTypes() as $strokeType)
                    <option value="{{ $strokeType->id }}">{{ $strokeType->name_de }}</option>
                @endforeach
            </flux:select>
        </flux:field>

        <flux:field class="w-36">
            <flux:label>Geschlecht</flux:label>
            <flux:select x-model="$wire.filterGender">
                <option value="">Alle</option>
                <option value="M">männlich</option>
                <option value="F">weiblich</option>
            </flux:select>
        </flux:field>

        <flux:field class="w-36">
            <flux:label>Sportklasse</flux:label>
            <flux:select x-model="$wire.filterSportClass">
                <option value="">Alle</option>
                @foreach($this->availableSportClasses() as $sportClass)
                    <option value="{{ $sportClass }}">{{ $sportClass }}</option>
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
            @error('bulkPercent')
            <p class="mt-2 text-xs text-red-500">{{ $message }}</p>
            @enderror
            <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                Wirkt ausschließlich auf Zeilen ohne Prozentsatz. Von Hand gesetzte Zeiten und
                bewusst auf 0 gesetzte Zeilen bleiben unverändert.
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
                <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400 text-right">Pkt.</th>
                <th class="px-3 py-2"></th>
            </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700/50">
            @forelse($this->standards() as $standard)
                <tr wire:key="standard-{{ $standard->id }}"
                    class="{{ $standard->isObsvOpen() ? 'bg-amber-50/40 dark:bg-amber-950/10' : '' }}">
                    <td class="px-4 py-1.5 whitespace-nowrap">
                        {{ $standard->distance }} m {{ $standard->strokeType?->name_de }}
                    </td>
                    <td class="px-3 py-1.5 whitespace-nowrap font-mono text-xs">
                        {{ $standard->sport_class }}
                        <span class="text-zinc-400">{{ $standard->gender === 'M' ? 'm' : 'w' }}</span>
                    </td>

                    {{-- MQS --}}
                    <td class="px-3 py-1">
                        @if($istAdmin)
                            <flux:input wire:model.blur="rows.{{ $standard->id }}.mqs"
                                        size="sm" class="w-28 font-mono text-xs"/>
                            @error('rows.'.$standard->id.'.mqs')
                            <span class="block text-red-500 text-xs mt-0.5">{{ $message }}</span>
                            @enderror
                        @else
                            <span class="font-mono text-xs">{{ $standard->formatted_mqs ?? '–' }}</span>
                        @endif
                    </td>
                    <td class="px-3 py-1.5 text-right font-mono text-xs text-zinc-500">
                        {{ $this->pointsFor($standard->mqs_centiseconds, $standard) ?? '' }}
                    </td>

                    {{-- MET --}}
                    <td class="px-3 py-1">
                        @if($istAdmin)
                            <flux:input wire:model.blur="rows.{{ $standard->id }}.met"
                                        size="sm" class="w-28 font-mono text-xs"/>
                            @error('rows.'.$standard->id.'.met')
                            <span class="block text-red-500 text-xs mt-0.5">{{ $message }}</span>
                            @enderror
                        @else
                            <span class="font-mono text-xs">{{ $standard->formatted_met ?? '–' }}</span>
                        @endif
                    </td>

                    {{-- ÖBSV-Prozentsatz --}}
                    <td class="px-3 py-1">
                        @if($istAdmin)
                            <flux:input wire:model.blur="rows.{{ $standard->id }}.percent"
                                        size="sm" class="w-20 font-mono text-xs" placeholder="offen"/>
                            @error('rows.'.$standard->id.'.percent')
                            <span class="block text-red-500 text-xs mt-0.5">{{ $message }}</span>
                            @enderror
                        @else
                            <span class="font-mono text-xs">
                                {{ $standard->hasObsvPercent() ? $standard->obsv_percent.' %' : 'offen' }}
                            </span>
                        @endif
                    </td>

                    {{-- ÖBSV-Zeit --}}
                    <td class="px-3 py-1">
                        @if($istAdmin)
                            <flux:input wire:model.blur="rows.{{ $standard->id }}.obsv"
                                        size="sm" class="w-28 font-mono text-xs"/>
                            @error('rows.'.$standard->id.'.obsv')
                            <span class="block text-red-500 text-xs mt-0.5">{{ $message }}</span>
                            @enderror
                        @else
                            <span class="font-mono text-xs">{{ $standard->formatted_obsv ?? '–' }}</span>
                        @endif
                        @if($standard->isObsvManual())
                            <flux:badge color="amber" size="sm">von Hand</flux:badge>
                        @elseif($standard->isObsvOpen())
                            <flux:badge color="zinc" size="sm">offen</flux:badge>
                        @endif
                    </td>
                    <td class="px-3 py-1.5 text-right font-mono text-xs text-zinc-500">
                        {{ $this->pointsFor($standard->obsv_centiseconds, $standard) ?? '' }}
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
                    <td colspan="9" class="px-4 py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">
                        Keine Normen — mit dem Filter oder dem Formular unten beginnen.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if($istAdmin)
        {{-- ── Neue Zeile ──────────────────────────────────────────────────── --}}
        <div class="mt-6 p-4 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl">
            <h3 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300 mb-3">Norm hinzufügen</h3>
            <div class="flex flex-wrap items-end gap-3">
                <flux:field class="w-48">
                    <flux:label>Bewerb</flux:label>
                    <flux:select x-model="$wire.newStrokeTypeId">
                        <option value="">Bitte wählen</option>
                        @foreach($this->strokeTypes() as $strokeType)
                            <option value="{{ $strokeType->id }}">{{ $strokeType->name_de }}</option>
                        @endforeach
                    </flux:select>
                    @error('newStrokeTypeId')
                    <flux:error>{{ $message }}</flux:error>
                    @enderror
                </flux:field>

                <flux:field class="w-28">
                    <flux:label>Strecke</flux:label>
                    <flux:input x-model="$wire.newDistance" type="number" placeholder="100"/>
                    @error('newDistance')
                    <flux:error>{{ $message }}</flux:error>
                    @enderror
                </flux:field>

                <flux:field class="w-36">
                    <flux:label>Geschlecht</flux:label>
                    <flux:select x-model="$wire.newGender">
                        <option value="M">männlich</option>
                        <option value="F">weiblich</option>
                    </flux:select>
                </flux:field>

                <flux:field class="w-32">
                    <flux:label>Sportklasse</flux:label>
                    <flux:input x-model="$wire.newSportClass" placeholder="S7"/>
                    @error('newSportClass')
                    <flux:error>{{ $message }}</flux:error>
                    @enderror
                </flux:field>

                <flux:button wire:click="addRow" variant="primary" size="sm" icon="plus">Hinzufügen</flux:button>
            </div>
            <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                Zeiten im Format 01:13.19 eingeben. Ein leeres Feld bedeutet „nicht ausgeschrieben".
            </p>
        </div>
    @endif
</div>
