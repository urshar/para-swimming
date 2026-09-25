<div>
    {{-- Kopf: Titel links, Jahr + Anzahl Jahre + PDF rechts. flux:select über
         x-model + $watch (wpsLivewireFilters), da das interne change-Event des
         Custom Elements nicht zuverlässig bubbelt (siehe CLAUDE.md). --}}
    <div class="flex flex-wrap items-end justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Jahresvergleich</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                Kennzahlen im Verlauf über mehrere Jahre
            </p>
        </div>

        <div class="flex items-end gap-3"
             x-data="wpsLivewireFilters(@js(['year' => $year, 'span' => $span]), 'setFilter')">
            <div class="w-28">
                <flux:label>Bis Jahr</flux:label>
                <flux:select variant="listbox" x-model="year">
                    @foreach($this->availableYears as $availableYear)
                        <flux:select.option value="{{ $availableYear }}">{{ $availableYear }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="w-32">
                <flux:label>Anzahl Jahre</flux:label>
                <flux:select variant="listbox" x-model="span">
                    @foreach($this->spanOptions as $option)
                        <flux:select.option value="{{ $option }}">{{ $option }} Jahre</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:button variant="filled" icon="arrow-down-tray" class="shrink-0"
                         href="{{ route('statistics.comparison.pdf', ['year' => $year, 'span' => $span, 'show_regular' => $showRegularStatus ? 1 : 0]) }}">
                Als PDF
            </flux:button>
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
        @foreach($this->charts as $chart)
            @include('partials.year-comparison-chart', ['chart' => $chart])
        @endforeach
    </div>
</div>
