{{--
    Manueller Veranstaltungs-Vergleich (Spec „Veranstaltungen vergleichen“):
    frei gewählte Meets jahresübergreifend nebeneinander, Status als Zeilen.

    Eigenes @include, weil Livewires morph-bewusster Blade-Precompiler sich an
    tief verschachtelten flux:chart.*-Strukturen im Root-Template verschluckt
    (siehe CLAUDE.md / partials/year-comparison-chart.blade.php).

    Zugriff auf die Computed-Properties der Komponente über $this.
--}}
@php
    use App\Livewire\YearComparison;

    $meets = $this->comparisonMeets;
    $chart = $this->comparisonChart;

    // Farb-Slot → Tailwind-Klassen (Literale, damit Tailwind sie beim Scan erkennt).
    $barClass = [
        'blue' => 'text-blue-500 dark:text-blue-400', 'pink' => 'text-pink-500 dark:text-pink-400',
        'amber' => 'text-amber-500 dark:text-amber-400', 'violet' => 'text-violet-500 dark:text-violet-400',
        'emerald' => 'text-emerald-500 dark:text-emerald-400', 'sky' => 'text-sky-500 dark:text-sky-400',
        'zinc' => 'text-zinc-500 dark:text-zinc-400', 'red' => 'text-red-500 dark:text-red-400',
        'orange' => 'text-orange-500 dark:text-orange-400', 'rose' => 'text-rose-500 dark:text-rose-400',
    ];
    $dotClass = [
        'blue' => 'bg-blue-500 dark:bg-blue-400', 'pink' => 'bg-pink-500 dark:bg-pink-400',
        'amber' => 'bg-amber-500 dark:bg-amber-400', 'violet' => 'bg-violet-500 dark:bg-violet-400',
        'emerald' => 'bg-emerald-500 dark:bg-emerald-400', 'sky' => 'bg-sky-500 dark:bg-sky-400',
        'zinc' => 'bg-zinc-500 dark:bg-zinc-400', 'red' => 'bg-red-500 dark:bg-red-400',
        'orange' => 'bg-orange-500 dark:bg-orange-400', 'rose' => 'bg-rose-500 dark:bg-rose-400',
    ];
@endphp

<div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 mt-6">
    <div class="flex items-start justify-between gap-3 mb-1">
        <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">Veranstaltungen vergleichen</h2>
        @if($meets->isNotEmpty())
            <button type="button" wire:click="resetMeetComparison"
                    class="text-xs text-zinc-500 dark:text-zinc-400 underline hover:no-underline shrink-0">
                Auswahl aufheben
            </button>
        @endif
    </div>
    <p class="text-xs text-zinc-400 mb-4">
        Beliebige Veranstaltungen (auch aus verschiedenen Jahren) auswählen und ihre Status nebeneinander
        vergleichen — z. B. dieselbe Meisterschaft über mehrere Jahre. Nur Einzelbewerbe.
    </p>

    {{-- Auswahl per Kaskade: Jahr → Monat → gefilterte Liste. Die eigentliche
         Auswahl (selectedMeetIds) sammelt sich über Filterwechsel hinweg an; die
         gewählten Veranstaltungen stehen als Chips darunter. --}}
    <div class="flex flex-wrap items-end gap-3 mb-3"
         x-data="wpsLivewireFilters(@js(['cmpYear' => $cmpYear, 'cmpMonth' => $cmpMonth]), 'setComparisonFilter')">
        <div class="w-28">
            <flux:label>Jahr</flux:label>
            <flux:select variant="listbox" x-model="cmpYear">
                @foreach($this->availableYears as $availableYear)
                    <flux:select.option value="{{ $availableYear }}">{{ $availableYear }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        <div class="w-40">
            <flux:label>Monat</flux:label>
            <flux:select variant="listbox" x-model="cmpMonth">
                @foreach($this->comparisonMonths as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </div>

    <div class="mb-4">
        @if($this->pickableMeets->isEmpty())
            <p class="text-sm text-zinc-400">Für diesen Zeitraum sind keine Veranstaltungen erfasst.</p>
        @else
            <div class="max-h-56 space-y-1.5 overflow-y-auto rounded-lg border
                        border-zinc-200 dark:border-zinc-700 p-3">
                {{-- wire:key je Veranstaltung ist hier zwingend: ohne ihn matcht
                     Livewires DOM-Morph die Checkboxen positionsbasiert und
                     verwendet beim Filterwechsel den angehakten DOM-Knoten der
                     alten ersten Zeile für die neue erste Veranstaltung weiter
                     (der checked-Property bleibt hängen, obwohl der Server sie
                     korrekt als nicht ausgewählt rendert). --}}
                @foreach($this->pickableMeets as $meet)
                    <label wire:key="cmp-meet-{{ $meet->id }}"
                           class="flex items-center gap-2 text-sm cursor-pointer text-zinc-700 dark:text-zinc-200">
                        <input type="checkbox" wire:model.live="selectedMeetIds" value="{{ $meet->id }}"
                               class="rounded border-zinc-300 text-blue-600 focus:ring-blue-500
                                      dark:border-zinc-600 dark:bg-zinc-900">
                        <span>{{ $meet->name }} ({{ $meet->start_date?->format('d.m.Y') ?? '—' }})</span>
                    </label>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Übersicht der (filterübergreifend) gewählten Veranstaltungen. --}}
    @if($this->selectedMeets->isNotEmpty())
        <div class="mb-4">
            <div class="text-xs text-zinc-500 dark:text-zinc-400 mb-1">
                Ausgewählt ({{ $this->selectedMeets->count() }}):
            </div>
            <div class="flex flex-wrap gap-1.5">
                @foreach($this->selectedMeets as $meet)
                    <span wire:key="cmp-chip-{{ $meet->id }}"
                          class="inline-flex items-center gap-1.5 rounded-full bg-zinc-100 dark:bg-zinc-700
                                 px-2.5 py-1 text-xs text-zinc-700 dark:text-zinc-200">
                        {{ $meet->name }} ({{ $meet->start_date?->format('Y') ?? '—' }})
                        <button type="button" wire:click="removeMeet({{ $meet->id }})"
                                class="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-100"
                                aria-label="Entfernen">&times;</button>
                    </span>
                @endforeach
            </div>
        </div>
    @endif

    @if($meets->isEmpty())
        <p class="text-sm text-zinc-400 dark:text-zinc-500 py-6 text-center">
            Mindestens eine Veranstaltung auswählen, um den Vergleich anzuzeigen.
        </p>
    @else
        {{-- Balkendiagramm: Sonderstatus auf der x-Achse, je Veranstaltung eine Balkenreihe. --}}
        @if($chart['hasData'])
            <div class="flex flex-wrap gap-x-3 gap-y-1 mb-2">
                @foreach($chart['series'] as $serie)
                    <span class="inline-flex items-center gap-1.5 text-xs text-zinc-600 dark:text-zinc-300">
                        <span class="size-2.5 rounded-full {{ $dotClass[$serie['color']] ?? 'bg-zinc-500' }}"></span>
                        {{ $serie['label'] }}
                    </span>
                @endforeach
            </div>

            <flux:chart :value="$chart['points']" class="aspect-2/1">
                <flux:chart.svg>
                    <flux:chart.axis axis="x" field="status">
                        <flux:chart.axis.line></flux:chart.axis.line>
                        <flux:chart.axis.tick></flux:chart.axis.tick>
                    </flux:chart.axis>

                    <flux:chart.axis axis="y" :min="0">
                        <flux:chart.axis.grid></flux:chart.axis.grid>
                        <flux:chart.axis.tick></flux:chart.axis.tick>
                    </flux:chart.axis>

                    @foreach($chart['series'] as $serie)
                        <flux:chart.bar :field="$serie['key']"
                                        class="{{ $barClass[$serie['color']] ?? 'text-zinc-500' }}"></flux:chart.bar>
                    @endforeach
                </flux:chart.svg>

                <flux:chart.tooltip>
                    <flux:chart.tooltip.heading field="status"></flux:chart.tooltip.heading>
                    @foreach($chart['series'] as $serie)
                        <flux:chart.tooltip.value :field="$serie['key']"
                                                  label="{{ $serie['label'] }}"></flux:chart.tooltip.value>
                    @endforeach
                </flux:chart.tooltip>
            </flux:chart>

            <p class="text-xs text-zinc-400 mt-1">
                Diagramm ohne „Regulär“ (dominiert die Skala) — vollständige Zahlen in der Tabelle.
            </p>
        @endif

        {{-- Vergleichstabelle: Status als Zeilen, Veranstaltungen als Spalten. --}}
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-zinc-500 dark:text-zinc-400 align-bottom">
                        <th class="text-left font-medium py-1 pe-3">Status</th>
                        @foreach($meets as $row)
                            <th class="text-right font-medium py-1 px-2 min-w-24">
                                <span class="block text-zinc-700 dark:text-zinc-200">{{ $row['meet'] }}</span>
                                <span class="block text-xs font-normal">
                                    {{ $row['start_date'] ? \Illuminate\Support\Carbon::parse($row['start_date'])->format('d.m.Y') : '—' }}
                                </span>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach(YearComparison::STATUS_LABELS as $key => $label)
                        <tr class="border-t border-zinc-100 dark:border-zinc-700">
                            <td class="py-1 pe-3 text-zinc-700 dark:text-zinc-200">{{ $label }}</td>
                            @foreach($meets as $row)
                                <td class="py-1 px-2 text-right font-mono text-zinc-800 dark:text-zinc-100">
                                    {{ $row['statuses'][$key] }}
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    <tr class="border-t-2 border-zinc-200 dark:border-zinc-600 font-semibold">
                        <td class="py-1 pe-3 text-zinc-700 dark:text-zinc-200">Gesamt</td>
                        @foreach($meets as $row)
                            <td class="py-1 px-2 text-right font-mono text-zinc-900 dark:text-zinc-50">
                                {{ $row['total'] }}
                            </td>
                        @endforeach
                    </tr>
                </tbody>
            </table>
        </div>
    @endif
</div>
