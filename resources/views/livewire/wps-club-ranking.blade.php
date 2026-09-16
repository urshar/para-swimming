<div>
    @php
        use App\Support\WpsClubRankingConfiguration;
        use App\Support\WpsRankingFilter;
    @endphp

    {{-- Die Vereinswertung des Cup-Moduls ist die offizielle ÖBSV-Wertung; wer die beiden
         verwechselt, zieht falsche Schlüsse (§9). --}}
    <div
        class="mb-4 p-4 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800 rounded-xl text-sm text-amber-700 dark:text-amber-400">
        <strong>Analysewerkzeug, keine offizielle Wertung.</strong>
        Die offizielle ÖBSV-Vereinswertung ist die Cup-Wertung. Diese Auswertung dient der
        Einschätzung; ihre Rechenweise ist wählbar, und je nach Methode ergeben sich
        unterschiedliche Reihenfolgen.
    </div>

    {{-- x-model + $watch statt x-on:change direkt am flux:select: Custom Element <ui-select>
         feuert sein internes "change"-Event mit bubbles:false, kommt darüber nicht zuverlässig
         an (siehe resources/js/wps-livewire-filters.js). --}}
    <div x-data="wpsLivewireFilters(@js(['year' => $year, 'course' => $course, 'strokeTypeId' => $strokeTypeId, 'gender' => $gender, 'method' => $method]), 'setInput')">
        {{-- ── Filter und Bewertungsmethode in einer Zeile ────────────────────── --}}
        <div class="mb-4 flex flex-wrap items-end gap-3">
            <flux:field class="w-28">
                <flux:label>Jahr</flux:label>
                <flux:select variant="listbox" x-model="year">
                    @foreach($this->availableYears() as $jahr)
                        <flux:select.option value="{{ $jahr }}">{{ $jahr }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field class="w-32">
                <flux:label>Bahnlänge</flux:label>
                <flux:select variant="listbox" x-model="course">
                    <flux:select.option value="SCM">Kurzbahn</flux:select.option>
                    <flux:select.option value="LCM">Langbahn</flux:select.option>
                    <flux:select.option value="MIXED">beide</flux:select.option>
                </flux:select>
            </flux:field>

            <flux:field class="w-40">
                <flux:label>Bewerb</flux:label>
                <flux:select variant="listbox" x-model="strokeTypeId" placeholder="Alle" clearable>
                    @foreach($this->strokeTypes() as $strokeType)
                        <flux:select.option value="{{ $strokeType->id }}">{{ $strokeType->name_de }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field class="w-32">
                <flux:label>Geschlecht</flux:label>
                <flux:select variant="listbox" x-model="gender" placeholder="Alle" clearable>
                    <flux:select.option value="M">männlich</flux:select.option>
                    <flux:select.option value="F">weiblich</flux:select.option>
                </flux:select>
            </flux:field>

            <flux:field class="w-64">
                <flux:label>Bewertungsmethode</flux:label>
                <flux:select variant="listbox" x-model="method">
                    <flux:select.option value="sum">Summe der besten Leistungen</flux:select.option>
                    <flux:select.option value="average">Durchschnitt der besten Leistungen</flux:select.option>
                    <flux:select.option value="count">Leistungen über einer Schwelle</flux:select.option>
                </flux:select>
            </flux:field>

            @if($method === WpsClubRankingConfiguration::METHOD_COUNT)
                <flux:field class="w-36">
                    <flux:label>Punktschwelle</flux:label>
                    <flux:input x-model="$wire.threshold" type="number" min="1"
                                x-on:change="$wire.setInput('threshold', $event.target.value)"/>
                </flux:field>
            @else
                <flux:field class="w-44">
                    <flux:label>Leistungen je Athlet</flux:label>
                    <flux:input x-model="$wire.countedPerAthlete" type="number" min="1"
                                x-on:change="$wire.setInput('countedPerAthlete', $event.target.value)"/>
                </flux:field>
            @endif

            <flux:field class="w-44">
                <flux:label>Mind. Leistungen je Verein</flux:label>
                <flux:input x-model="$wire.minEntriesPerClub" type="number" min="1"
                            x-on:change="$wire.setInput('minEntriesPerClub', $event.target.value)"/>
            </flux:field>

            <flux:button href="{{ $this->pdfUrl() }}" variant="filled" icon="document-arrow-down"
                         class="ml-auto text-purple-500!">
                PDF
            </flux:button>
        </div>
    </div>

    @php($einstellungen = $this->configuration())
    @php($istDurchschnitt = $einstellungen->method === WpsClubRankingConfiguration::METHOD_AVERAGE)

    <p class="mb-3 text-xs text-zinc-500 dark:text-zinc-400">
        {{ $einstellungen->describe() }} · {{ implode(' · ', $this->filter()->describe()) }}
    </p>

    {{-- ── Vereine ─────────────────────────────────────────────────────────── --}}
    <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
        <table class="w-full text-sm border-collapse">
            <thead>
            <tr class="border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900/40 text-left">
                <th class="px-4 py-2 font-medium text-zinc-600 dark:text-zinc-400">Rang</th>
                <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400">Verein</th>
                <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400 text-right">
                    @if($einstellungen->countsEntries()) Leistungen @else Wert @endif
                </th>
                <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400 text-right">Athleten</th>
                <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400 text-right">gewertet</th>
                <th class="px-3 py-2"></th>
            </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700/50">
            @forelse($this->ranked() as $eintrag)
                <tr wire:key="verein-{{ $eintrag->club->id }}">
                    <td class="px-4 py-1.5 font-mono text-zinc-900 dark:text-zinc-100">{{ $eintrag->rank }}</td>
                    <td class="px-3 py-1.5 text-zinc-900 dark:text-zinc-100">{{ $eintrag->club->display_name }}</td>
                    <td class="px-3 py-1.5 text-right font-mono text-zinc-900 dark:text-zinc-100">
                        {{ $eintrag->formattedValue($istDurchschnitt) }}
                    </td>
                    <td class="px-3 py-1.5 text-right font-mono text-xs text-zinc-500">{{ $eintrag->athleteCount }}</td>
                    <td class="px-3 py-1.5 text-right font-mono text-xs text-zinc-500">{{ $eintrag->entryCount }}</td>
                    <td class="px-3 py-1.5 text-right whitespace-nowrap">
                        <flux:button wire:click="toggle({{ $eintrag->club->id }})" variant="ghost" size="sm">
                            {{ $this->isExpanded($eintrag->club->id) ? 'weniger' : 'Athleten' }}
                        </flux:button>
                    </td>
                </tr>

                {{-- Eine Vereinssumme ohne Aufschlüsselung ist nicht prüfbar. --}}
                @if($this->isExpanded($eintrag->club->id))
                    <tr wire:key="details-{{ $eintrag->club->id }}" class="bg-zinc-50 dark:bg-zinc-900/40">
                        <td colspan="6" class="px-8 py-3">
                            <table class="w-full text-xs">
                                <tbody>
                                @foreach($eintrag->details as $detail)
                                    <tr class="text-zinc-600 dark:text-zinc-400">
                                        <td class="py-0.5 pe-4">{{ $detail->athlete->full_name }}</td>
                                        <td class="py-0.5 pe-4 font-mono">
                                            {{ $detail->entryCount }} Leistung(en)
                                        </td>
                                        <td class="py-0.5 pe-4 font-mono">
                                            beste {{ $detail->bestPoints }} Punkte
                                        </td>
                                        <td class="py-0.5 font-mono text-right">
                                            Beitrag {{ number_format($detail->contribution, 0, ',', '.') }}
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </td>
                    </tr>
                @endif
            @empty
                <tr>
                    <td colspan="6" class="px-4 py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">
                        Keine Vereine mit gewerteten Leistungen für diese Auswahl.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{-- Ein Verein mit einem einzigen starken Athleten soll sichtbar bleiben. --}}
    @if($this->belowMinimum()->isNotEmpty())
        <div
            class="mt-6 p-4 bg-zinc-50 dark:bg-zinc-900/40 border border-zinc-200 dark:border-zinc-700 rounded-xl">
            <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">
                Unter {{ $einstellungen->minEntriesPerClub }} gewerteten Leistungen — nicht platziert
                ({{ $this->belowMinimum()->count() }})
            </p>
            <ul class="text-xs text-zinc-600 dark:text-zinc-400 space-y-1 list-disc list-inside">
                @foreach($this->belowMinimum() as $eintrag)
                    <li>
                        {{ $eintrag->club->display_name }} —
                        {{ $eintrag->formattedValue($istDurchschnitt) }},
                        {{ $eintrag->entryCount }} Leistung(en)
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
