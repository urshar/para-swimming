<div>
    @php
        use App\Support\TimeParser;
        use Illuminate\Support\Carbon;
    @endphp

    <div
        class="mb-4 p-4 bg-zinc-50 dark:bg-zinc-900/40 border border-zinc-200 dark:border-zinc-700 rounded-xl text-sm text-zinc-600 dark:text-zinc-400">
        Gezeigt werden ausschließlich reale Zeiten auf {{ $championship->course }} aus
        WPS-anerkannten Wettkämpfen im Qualifikationszeitraum. Bewerbe ohne ausgeschriebene Norm
        entfallen; nicht erfüllte Bewerbe bleiben mit dem Abstand zur Norm stehen.
        Kaderzuordnung zum Stichtag
        {{ Carbon::parse($this->kaderReferenceDate())->format('d.m.Y') }}.
    </div>

    @if($this->excluded()->isNotEmpty())
        <div
            class="mb-4 p-4 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800 rounded-xl">
            <p class="text-sm font-medium text-amber-700 dark:text-amber-400 mb-2">
                {{ $this->excluded()->count() }} Ergebnis(se) würden eine Norm erfüllen, zählen aber
                nicht: Der Wettkampf ist nicht als WPS-anerkannt gekennzeichnet.
            </p>
            <ul class="text-xs text-amber-700 dark:text-amber-400 space-y-1 list-disc list-inside">
                @foreach($this->excluded()->take(15) as $zeile)
                    <li>
                        {{ $zeile->athlete?->full_name }} — {{ $zeile->eventLabel }}
                        {{ $zeile->sportClass }},
                        {{ TimeParser::display($zeile->status->swimTime) }}
                        bei „{{ $zeile->status->meetName }}"
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- ── Filter ──────────────────────────────────────────────────────────── --}}
    <div class="mb-6 flex flex-wrap items-end gap-3">
        <flux:field class="w-56">
            <flux:label>Bewerbe</flux:label>
            <flux:select x-on:change="$wire.setFilter('fulfilment', $event.target.value)">
                <option value="alle" @selected($filterFulfilment === 'alle')>alle mit Norm</option>
                <option value="met" @selected($filterFulfilment === 'met')>nur erfüllte (MQS/MET)</option>
                <option value="open" @selected($filterFulfilment === 'open')>nur nicht erfüllte</option>
            </flux:select>
        </flux:field>

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

        {{-- Der PDF-Link trägt den Filterstand mit; das PDF zeigt sonst etwas anderes als der
             Bildschirm, von dem aus es erzeugt wurde. --}}
        <flux:button
            href="{{ $this->pdfUrl() }}"
            variant="filled" size="sm" icon="document-arrow-down">PDF
        </flux:button>
    </div>

    @forelse($this->groups() as $kaderName => $athleten)
        <h2 class="mt-8 mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
            {{ $kaderName }} <span class="font-normal">({{ $athleten->count() }})</span>
        </h2>

        @foreach($athleten as $eintrag)
            @php($zeilen = $this->visibleRows($eintrag))

            @continue($zeilen->isEmpty())

            <div wire:key="athlete-{{ $eintrag->athlete->id }}"
                 class="mb-4 bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                <div
                    class="px-4 py-3 border-b border-zinc-200 dark:border-zinc-700 flex flex-wrap items-baseline justify-between gap-2">
                    <div>
                        <span class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                            {{ $eintrag->athlete->full_name }}
                        </span>
                        <span class="ml-2 text-xs text-zinc-500 dark:text-zinc-400">
                            {{ $eintrag->athlete->gender === 'M' ? 'männlich' : 'weiblich' }}
                            · {{ $eintrag->displaySportClass() }}
                            · {{ $eintrag->athlete->club?->name }}
                        </span>
                    </div>
                    <div class="flex gap-2 text-xs">
                        <flux:badge color="green" size="sm">{{ $eintrag->mqsCount() }} × MQS</flux:badge>
                        @if($eintrag->metCount() > 0)
                            <flux:badge color="amber" size="sm">{{ $eintrag->metCount() }} × MET</flux:badge>
                        @endif
                        @if($eintrag->openCount() > 0)
                            <flux:badge color="zinc" size="sm">{{ $eintrag->openCount() }} offen</flux:badge>
                        @endif
                    </div>
                </div>

                <table class="w-full text-sm border-collapse">
                    <thead>
                    <tr class="border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900/40 text-left">
                        <th class="px-4 py-2 font-medium text-zinc-600 dark:text-zinc-400">Bewerb</th>
                        <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400">Platz</th>
                        <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400">Zeit</th>
                        <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400 text-right">Punkte</th>
                        <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400">Wettkampf</th>
                        <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400">Norm</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700/50">
                    @foreach($zeilen as $zeile)
                        @php($schluessel = $eintrag->athlete->id.'-'.$zeile->eventLabel.'-'.$zeile->sportClass)
                        @php($beste = $zeile->history->firstWhere('swimTime', $zeile->status->swimTime))

                        <tr wire:key="row-{{ $schluessel }}">
                            <td class="px-4 py-1.5 whitespace-nowrap text-zinc-900 dark:text-zinc-100">
                                {{ $zeile->eventLabel }}
                                <span class="font-mono text-xs text-zinc-500">{{ $zeile->sportClass }}</span>
                            </td>
                            <td class="px-3 py-1.5 text-zinc-900 dark:text-zinc-100">
                                {{ $beste?->place ?? '–' }}
                            </td>
                            <td class="px-3 py-1.5 font-mono text-xs text-zinc-900 dark:text-zinc-100">
                                {{ $zeile->status->swimTime === null ? '–' : TimeParser::display($zeile->status->swimTime) }}
                            </td>
                            <td class="px-3 py-1.5 text-right font-mono text-xs text-zinc-500">
                                {{ $beste?->points ?? '' }}
                            </td>
                            <td class="px-3 py-1.5 text-xs text-zinc-600 dark:text-zinc-400">
                                {{ $zeile->status->meetName }}
                                @if($zeile->status->meetDate)
                                    <span class="block">{{ Carbon::parse($zeile->status->meetDate)->format('d.m.Y') }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-1.5">
                                <flux:badge color="{{ $zeile->status->colour() }}" size="sm">
                                    {{ $zeile->status->label() }}
                                </flux:badge>
                                @if($zeile->status->formattedGap())
                                    <span class="block mt-0.5 font-mono text-xs text-zinc-500">
                                        {{ $zeile->status->formattedGap() }}
                                    </span>
                                @endif
                                @if($zeile->metUsable === false)
                                    <span class="block mt-0.5 text-xs text-zinc-500">MET ohne MQS — wirkungslos</span>
                                @endif
                            </td>
                            <td class="px-3 py-1.5 text-right whitespace-nowrap">
                                @if($zeile->history->count() > 1)
                                    <flux:button wire:click="toggle('{{ $schluessel }}')"
                                                 variant="ghost" size="sm">
                                        {{ $this->isExpanded($schluessel) ? 'weniger' : $zeile->history->count().' Ergebnisse' }}
                                    </flux:button>
                                @endif
                            </td>
                        </tr>

                        @if($this->isExpanded($schluessel))
                            <tr wire:key="history-{{ $schluessel }}" class="bg-zinc-50 dark:bg-zinc-900/40">
                                <td colspan="7" class="px-8 py-3">
                                    <p class="mb-2 text-xs text-zinc-500 dark:text-zinc-400">
                                        Alle Ergebnisse im Qualifikationszeitraum, chronologisch.
                                        @if($zeile->trend() !== null)
                                            Vom ersten zum letzten:
                                            <span class="font-mono">
                                                {{ $zeile->trend() < 0 ? '−' : '+' }}{{ number_format(abs($zeile->trend()) / 100, 2, ',', '.') }} s
                                            </span>
                                        @endif
                                    </p>
                                    <table class="w-full text-xs">
                                        <tbody>
                                        @foreach($zeile->history as $ergebnis)
                                            <tr class="text-zinc-600 dark:text-zinc-400">
                                                <td class="py-0.5 pe-4 whitespace-nowrap">
                                                    @if($ergebnis->meetDate){{ Carbon::parse($ergebnis->meetDate)->format('d.m.Y') }}@endif
                                                </td>
                                                <td class="py-0.5 pe-4">{{ $ergebnis->meetName }}</td>
                                                <td class="py-0.5 pe-4">{{ $ergebnis->place ?? '' }}</td>
                                                <td class="py-0.5 pe-4 font-mono">
                                                    {{ TimeParser::display($ergebnis->swimTime) }}
                                                </td>
                                                <td class="py-0.5 pe-4 font-mono text-right">{{ $ergebnis->points ?? '' }}</td>
                                                <td class="py-0.5">
                                                    @if($ergebnis->standardLabel())
                                                        <flux:badge
                                                            color="{{ $ergebnis->standardLabel() === 'MQS' ? 'green' : 'amber' }}"
                                                            size="sm">{{ $ergebnis->standardLabel() }}</flux:badge>
                                                    @endif
                                                    @if($ergebnis->exhibition)
                                                        <flux:badge color="zinc" size="sm">EXH</flux:badge>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
    @empty
        <div
            class="p-6 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl text-sm text-zinc-500 dark:text-zinc-400">
            Keine Athleten mit Ergebnissen in Bewerben, für die eine Norm ausgeschrieben ist.
            @if($this->excluded()->isEmpty())
                Falls das überrascht: Prüfe, ob bei den betreffenden Wettkämpfen die WPS-Anerkennung
                gesetzt ist und ob die Normen der Meisterschaft gepflegt sind.
            @endif
        </div>
    @endforelse
</div>
