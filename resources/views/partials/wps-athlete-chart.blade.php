{{--
    Flux' eigene Chart-Komponente (Design-Feedback Erik, 15.09.2026: "die Grafik von Flux
    verwenden") - nur am Bildschirm. Der PDF-Export (pdf/wps-athlete-analysis.blade.php) behält
    die bestehende reine SVG-Grafik (x-wps-chart): Flux' Chart-Komponente ist interaktiv/JS-basiert,
    dompdf führt kein JavaScript aus und würde eine leere Fläche ausgeben. Aus demselben Grund
    fehlen hier die Klassenwechsel-/Notizen-Markierungen der PDF-Grafik.

    Ausgelagert in ein eigenes @include statt direkt in livewire/wps-athlete-analysis.blade.php:
    Livewires morph-bewusster Blade-Precompiler (SupportMorphAwareBladeCompilation) verwechselt
    sich an der tief verschachtelten flux:chart.*-Struktur direkt im Root-Template einer
    Livewire-Komponente ("syntax error, unexpected token 'endif'") - als eigenes @include (statt
    eines Blade-x-Components, der ebenfalls inline kompiliert würde) bleibt die Verschachtelung
    außerhalb des von Livewire vorkompilierten Textes.

    Erwartet: $chartData (array<int, array{label: string, value: int|float|null, valueLabel: string}>),
    $chartMetricLabel (string), $chartCaption (string). "value" treibt Achsen/Linie/Punkte (muss eine
    echte Zahl sein), "valueLabel" ist nur für den Tooltip - bei der Zeit-Metrik mm:ss,cc statt roher
    Sekunden.
--}}
<flux:chart :value="$chartData" class="h-64">
    <flux:chart.svg>
        <flux:chart.axis axis="x" field="label">
            <flux:chart.axis.line></flux:chart.axis.line>
            <flux:chart.axis.mark></flux:chart.axis.mark>
            <flux:chart.axis.tick></flux:chart.axis.tick>
        </flux:chart.axis>

        <flux:chart.axis axis="y">
            <flux:chart.axis.grid></flux:chart.axis.grid>
            <flux:chart.axis.mark></flux:chart.axis.mark>
            <flux:chart.axis.tick></flux:chart.axis.tick>
        </flux:chart.axis>

        {{-- Farbe explizit: flux:chart.line bringt (anders als flux:chart.point) selbst keine
             Dark-Mode-Variante mit (nur "text-zinc-800", vendor/livewire/flux-pro/.../line.blade.php)
             - im Dunkelmodus sonst dunkelgrau auf dunklem Grund, praktisch unsichtbar. --}}
        <flux:chart.line field="value" class="text-zinc-800 dark:text-zinc-100"></flux:chart.line>
        <flux:chart.point field="value"></flux:chart.point>
        <flux:chart.cursor></flux:chart.cursor>
    </flux:chart.svg>

    <flux:chart.tooltip>
        <flux:chart.tooltip.heading field="label"></flux:chart.tooltip.heading>
        <flux:chart.tooltip.value field="valueLabel" label="{{ $chartMetricLabel }}"></flux:chart.tooltip.value>
    </flux:chart.tooltip>
</flux:chart>
<p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $chartCaption }}</p>
