{{--
    Ein Vergleichs-Chart der Jahresvergleich-Seite: interaktives flux:chart +
    Legende + Zahlentabelle. Erwartet $chart (eine Definition aus
    App\Support\YearComparisonCharts).

    Als eigenes @include, weil Livewires morph-bewusster Blade-Precompiler sich an
    tief verschachtelten flux:chart.*-Strukturen im Root-Template verschluckt
    (siehe CLAUDE.md / partials/wps-athlete-chart.blade.php).

    Die Farb-Slots (aus dem Presenter) werden hier auf Tailwind-Klassen abgebildet —
    bewusst als Literale im Blade, damit Tailwind sie beim Scan erkennt.
--}}
@php
    $lineClass = [
        'blue' => 'text-blue-500 dark:text-blue-400',
        'pink' => 'text-pink-500 dark:text-pink-400',
        'amber' => 'text-amber-500 dark:text-amber-400',
        'violet' => 'text-violet-500 dark:text-violet-400',
        'emerald' => 'text-emerald-500 dark:text-emerald-400',
        'sky' => 'text-sky-500 dark:text-sky-400',
        'zinc' => 'text-zinc-500 dark:text-zinc-400',
        'red' => 'text-red-500 dark:text-red-400',
        'orange' => 'text-orange-500 dark:text-orange-400',
        'rose' => 'text-rose-500 dark:text-rose-400',
    ];
    $dotClass = [
        'blue' => 'bg-blue-500 dark:bg-blue-400',
        'pink' => 'bg-pink-500 dark:bg-pink-400',
        'amber' => 'bg-amber-500 dark:bg-amber-400',
        'violet' => 'bg-violet-500 dark:bg-violet-400',
        'emerald' => 'bg-emerald-500 dark:bg-emerald-400',
        'sky' => 'bg-sky-500 dark:bg-sky-400',
        'zinc' => 'bg-zinc-500 dark:bg-zinc-400',
        'red' => 'bg-red-500 dark:bg-red-400',
        'orange' => 'bg-orange-500 dark:bg-orange-400',
        'rose' => 'bg-rose-500 dark:bg-rose-400',
    ];
@endphp

<div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
    <div class="flex items-center justify-between gap-3 mb-2">
        <h3 class="text-sm font-semibold text-zinc-800 dark:text-zinc-100">{{ $chart['title'] }}</h3>

        {{-- Nur beim Status-Diagramm: "Regulär" ist um ein Vielfaches häufiger und drückt die
             übrigen Status sonst auf die Nulllinie — daher ausblendbar (Standard: aus). --}}
        @if($chart['key'] === 'status')
            <flux:switch wire:model.live="showRegularStatus" label="Regulär" align="right"
                         class="shrink-0 text-xs"/>
        @endif
    </div>

    @if(! $chart['hasData'])
        <p class="text-sm text-zinc-400 dark:text-zinc-500 py-8 text-center">
            Für diesen Zeitraum liegen keine Daten vor.
        </p>
    @else
        {{-- Legende --}}
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
                <flux:chart.axis axis="x" field="year">
                    <flux:chart.axis.line></flux:chart.axis.line>
                    <flux:chart.axis.tick></flux:chart.axis.tick>
                </flux:chart.axis>

                <flux:chart.axis axis="y" :min="0">
                    <flux:chart.axis.grid></flux:chart.axis.grid>
                    <flux:chart.axis.tick></flux:chart.axis.tick>
                </flux:chart.axis>

                @foreach($chart['series'] as $serie)
                    <flux:chart.line :field="$serie['key']"
                                     class="{{ $lineClass[$serie['color']] ?? 'text-zinc-500' }}"></flux:chart.line>
                    <flux:chart.point :field="$serie['key']"
                                      class="{{ $lineClass[$serie['color']] ?? 'text-zinc-500' }}"></flux:chart.point>
                @endforeach

                <flux:chart.cursor></flux:chart.cursor>
            </flux:chart.svg>

            <flux:chart.tooltip>
                <flux:chart.tooltip.heading field="year"></flux:chart.tooltip.heading>
                @foreach($chart['series'] as $serie)
                    <flux:chart.tooltip.value :field="$serie['key']"
                                              label="{{ $serie['label'] }}"></flux:chart.tooltip.value>
                @endforeach
            </flux:chart.tooltip>
        </flux:chart>

        {{-- Zahlentabelle: Serien als Zeilen, Jahre als Spalten --}}
        <div class="mt-3 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-zinc-500 dark:text-zinc-400">
                        <th class="text-left font-medium py-1 pe-3">&nbsp;</th>
                        @foreach($chart['years'] as $year)
                            <th class="text-right font-medium py-1 px-2 font-mono">{{ $year }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($chart['series'] as $serie)
                        <tr class="border-t border-zinc-100 dark:border-zinc-700">
                            <td class="py-1 pe-3 text-zinc-700 dark:text-zinc-200">
                                <span class="inline-flex items-center gap-1.5">
                                    <span class="size-2 rounded-full {{ $dotClass[$serie['color']] ?? 'bg-zinc-500' }}"></span>
                                    {{ $serie['label'] }}
                                </span>
                            </td>
                            @foreach($serie['values'] as $value)
                                <td class="py-1 px-2 text-right font-mono text-zinc-800 dark:text-zinc-100">{{ $value }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
