{{--
    5-Jahres-Vergleichsgrafiken (offener Punkt "Statistik: 5-Jahres-Vergleichsgrafik").
    Drei getrennte Liniendiagramme (Design-Entscheidung Erik, 22.09.2026):
      1. Einzelstarts nach Geschlecht
      2. Teilnehmer nach Geschlecht
      3. Staffelstarts nach Typ (pro Athlet gezählt, Herren/Damen/Mixed)

    Nur am Bildschirm — flux:chart ist interaktiv/JS-basiert; dompdf führt kein JavaScript
    aus. Der Jahresbericht (PDF/Excel/CSV) zeigt dieselben Zahlen als Tabelle (Phase 3).

    Ausgelagert in ein eigenes @include statt direkt in livewire/statistics-dashboard.blade.php:
    Livewires morph-bewusster Blade-Precompiler verwechselt sich sonst an der tief
    verschachtelten flux:chart.*-Struktur im Root-Template einer Livewire-Komponente
    (siehe CLAUDE.md / partials/wps-athlete-chart.blade.php).

    Erwartet: $series (Rückgabe von MultiYearStatisticsService::series()).
--}}
@php
    /** @var array<string, mixed> $series */
    use App\Services\MultiYearStatisticsService;
    $rows = $series['rows'];

    // Linienfarbe über text-* (stroke = currentColor); flux:chart.line bringt selbst keine
    // Dark-Mode-Variante mit → explizit dark:* setzen (CLAUDE.md). Der Legenden-Punkt nutzt bg-*.
    $lineColors = [
        'M' => 'text-blue-500 dark:text-blue-400',
        'F' => 'text-pink-500 dark:text-pink-400',
        'N' => 'text-amber-500 dark:text-amber-400',
        'X' => 'text-violet-500 dark:text-violet-400',
    ];
    $dotColors = [
        'M' => 'bg-blue-500 dark:bg-blue-400',
        'F' => 'bg-pink-500 dark:bg-pink-400',
        'N' => 'bg-amber-500 dark:bg-amber-400',
        'X' => 'bg-violet-500 dark:bg-violet-400',
    ];

    $genderLabels = MultiYearStatisticsService::GENDER_LABELS;
    $relayGenderLabels = MultiYearStatisticsService::RELAY_GENDER_LABELS;

    $charts = [
        [
            'title' => 'Einzelstarts nach Geschlecht',
            'prefix' => 'starts',
            'genders' => $series['individual_genders'],
            'labels' => $genderLabels,
        ],
        [
            'title' => 'Teilnehmer nach Geschlecht',
            'prefix' => 'participants',
            'genders' => $series['individual_genders'],
            'labels' => $genderLabels,
        ],
        [
            'title' => 'Staffelstarts nach Typ',
            'prefix' => 'relay',
            'genders' => $series['relay_genders'],
            'labels' => $relayGenderLabels,
        ],
    ];

    // Gibt es über alle Jahre/Serien überhaupt einen Wert > 0? Sonst nur ein Hinweis
    // statt drei durchgehender Null-Linien.
    $hasData = collect($rows)->contains(fn (array $row): bool => collect($row)
        ->except('year')
        ->contains(fn (int $value): bool => $value > 0));
@endphp

<div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 mb-6">
    <div class="flex items-baseline justify-between mb-4">
        <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">5-Jahres-Vergleich</h2>
        <span class="text-xs text-zinc-400">
            {{ $series['years'][0] }}–{{ $series['years'][count($series['years']) - 1] }}
        </span>
    </div>

    @if(! $hasData)
        <p class="text-sm text-zinc-400 dark:text-zinc-500 py-8 text-center">
            Für die letzten {{ $series['span'] }} Jahre sind keine Starts erfasst.
        </p>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
            @foreach($charts as $chart)
                <div>
                    <h3 class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">{{ $chart['title'] }}</h3>

                    <div class="flex flex-wrap gap-x-3 gap-y-1 mb-2">
                        @foreach($chart['genders'] as $g)
                            <span class="inline-flex items-center gap-1.5 text-xs text-zinc-600 dark:text-zinc-300">
                                <span class="size-2.5 rounded-full {{ $dotColors[$g] }}"></span>
                                {{ $chart['labels'][$g] }}
                            </span>
                        @endforeach
                    </div>

                    <flux:chart :value="$rows" class="aspect-4/3">
                        <flux:chart.svg>
                            <flux:chart.axis axis="x" field="year">
                                <flux:chart.axis.line></flux:chart.axis.line>
                                <flux:chart.axis.tick></flux:chart.axis.tick>
                            </flux:chart.axis>

                            <flux:chart.axis axis="y" :min="0">
                                <flux:chart.axis.grid></flux:chart.axis.grid>
                                <flux:chart.axis.tick></flux:chart.axis.tick>
                            </flux:chart.axis>

                            @foreach($chart['genders'] as $g)
                                <flux:chart.line :field="$chart['prefix'].'_'.strtolower($g)"
                                                 class="{{ $lineColors[$g] }}"></flux:chart.line>
                                <flux:chart.point :field="$chart['prefix'].'_'.strtolower($g)"
                                                  class="{{ $lineColors[$g] }}"></flux:chart.point>
                            @endforeach

                            <flux:chart.cursor></flux:chart.cursor>
                        </flux:chart.svg>

                        <flux:chart.tooltip>
                            <flux:chart.tooltip.heading field="year"></flux:chart.tooltip.heading>
                            @foreach($chart['genders'] as $g)
                                <flux:chart.tooltip.value :field="$chart['prefix'].'_'.strtolower($g)"
                                                          label="{{ $chart['labels'][$g] }}"></flux:chart.tooltip.value>
                            @endforeach
                        </flux:chart.tooltip>
                    </flux:chart>
                </div>
            @endforeach
        </div>

        <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">
            Volle Kalenderjahre, verankert am gewählten Jahr. Staffelstarts pro eingesetztem Schwimmer;
            unabhängig von der Veranstaltungsauswahl oben.
        </p>
    @endif
</div>
