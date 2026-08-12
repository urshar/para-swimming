<div>
    @php
        use App\Support\TimeParser;
        use App\Support\WpsRankingFilter;
        use App\Support\WpsTalentReportConfiguration;
        use Illuminate\Support\Carbon;
    @endphp

    {{-- ── Eingaben (§6.6.1) ───────────────────────────────────────────────── --}}
    <div class="mb-4 flex flex-wrap items-end gap-3">
        <flux:field class="w-28">
            <flux:label>Von</flux:label>
            <flux:select x-on:change="$wire.setInput('fromYear', $event.target.value)">
                @foreach($this->availableYears() as $jahr)
                    <option value="{{ $jahr }}" @selected($fromYear === (string) $jahr)>{{ $jahr }}</option>
                @endforeach
            </flux:select>
        </flux:field>

        <flux:field class="w-28">
            <flux:label>Bis</flux:label>
            <flux:select x-on:change="$wire.setInput('toYear', $event.target.value)">
                @foreach($this->availableYears() as $jahr)
                    <option value="{{ $jahr }}" @selected($toYear === (string) $jahr)>{{ $jahr }}</option>
                @endforeach
            </flux:select>
        </flux:field>

        <flux:field class="w-72">
            <flux:label>Referenznorm</flux:label>
            <flux:select x-on:change="$wire.setInput('referenceId', $event.target.value)">
                <option value="">Bitte wählen</option>
                @foreach($this->references() as $referenz)
                    <option value="{{ $referenz->id }}" @selected($referenceId === (string) $referenz->id)>
                        {{ $referenz->display_name }}
                    </option>
                @endforeach
            </flux:select>
        </flux:field>

        <flux:field class="w-32">
            <flux:label>Norm</flux:label>
            <flux:select x-on:change="$wire.setInput('normType', $event.target.value)">
                <option value="mqs" @selected($normType === WpsTalentReportConfiguration::NORM_MQS)>MQS</option>
                <option value="met" @selected($normType === WpsTalentReportConfiguration::NORM_MET)>MET</option>
            </flux:select>
        </flux:field>

        <flux:field class="w-36">
            <flux:label>Schwelle Jugend</flux:label>
            <flux:input x-model="$wire.youthThreshold"
                        x-on:change="$wire.setInput('youthThreshold', $event.target.value)"
                        type="number" step="0.5"/>
        </flux:field>

        <flux:field class="w-36">
            <flux:label>Schwelle Allgemein</flux:label>
            <flux:input x-model="$wire.generalThreshold"
                        x-on:change="$wire.setInput('generalThreshold', $event.target.value)"
                        type="number" step="0.5"/>
        </flux:field>

        <flux:field class="w-32">
            <flux:label>Bahnlänge</flux:label>
            <flux:select x-on:change="$wire.setInput('course', $event.target.value)">
                <option value="SCM" @selected($course === WpsRankingFilter::COURSE_SCM)>Kurzbahn</option>
                <option value="LCM" @selected($course === WpsRankingFilter::COURSE_LCM)>Langbahn</option>
            </flux:select>
        </flux:field>

        @if($this->config() !== null)
            <flux:button href="{{ $this->pdfUrl() }}" variant="filled" size="sm"
                         icon="document-arrow-down">PDF
            </flux:button>
        @endif
    </div>

    @if($this->config() === null)
        <div
            class="p-6 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl text-sm text-zinc-500 dark:text-zinc-400">
            Bitte eine Referenznorm wählen. Ohne sie gibt es keine Bezugsgröße für die Schwelle.
            Falls die Auswahl leer ist: Es muss eine Meisterschaft mit gepflegten Normen angelegt
            sein.
        </div>
    @else
        <p class="mb-4 text-xs text-zinc-500 dark:text-zinc-400">{{ $this->config()->describe() }}</p>

        {{-- Verpflichtender Hinweis nach §6.6.5 — immer, nicht nur bei vorhandenen
             Schätzungen: Diese Auswertung beruht ihrem Wesen nach auf umgerechneten Zeiten,
             und die Unsicherheit trifft genau die Zielgruppe. --}}
        <div
            class="mb-6 p-4 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800 rounded-xl text-sm text-amber-700 dark:text-amber-400">
            <strong>Hinweis:</strong>
            Die Punkte beruhen auf umgerechneten Kurzbahnzeiten. Der Umrechnungsfaktor ist an
            international startenden Athletinnen und Athleten geeicht und fällt für den Nachwuchs
            tendenziell zu optimistisch aus. Die Auswertung ist ein Anhaltspunkt für die Förderung,
            kein Leistungsnachweis.
        </div>

        @forelse($this->report() as $gruppe => $zeilen)
            <h2 class="mt-6 mb-2 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                {{ $gruppe }}
                <span class="font-normal">
                    ({{ $zeilen->count() }} Leistungen ·
                    Schwelle {{ $this->config()->thresholdPercentFor($gruppe) }} % der Norm)
                </span>
            </h2>

            <div
                class="mb-4 bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-x-auto">
                <table class="w-full text-sm border-collapse">
                    <thead>
                    <tr class="border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900/40 text-left">
                        <th class="px-4 py-2 font-medium text-zinc-600 dark:text-zinc-400">Bewerb</th>
                        <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400">Klasse</th>
                        <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400">Zeit</th>
                        <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400">geschätzt LCM</th>
                        <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400">
                            Norm ({{ $this->config()->normLabel() }})
                        </th>
                        <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400 text-right">Punkte</th>
                        <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400 text-right">Schwelle</th>
                        <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400 text-right">Abstand</th>
                        <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400">Wettkampf</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700/50">
                    @php($vorherigerAthlet = null)

                    @foreach($zeilen as $zeile)
                        @php($erreicht = $zeile->reachesThreshold())
                        @php($ersteZeile = $vorherigerAthlet !== $zeile->athlete->id)
                        @php($vorherigerAthlet = $zeile->athlete->id)

                        {{-- Athlet, Jahrgang und Verein bekommen eine eigene Zeile über ihren
                             Bewerben: In der Tabellenzeile beanspruchten sie drei Spalten, die
                             bei jedem weiteren Bewerb leer blieben.

                             Die Sportklasse steht dagegen in JEDER Bewerbszeile — sie hängt am
                             Bewerb, nicht am Athleten: S4 im Freistil, SB3 in Brust, SM4 in
                             Lagen. In der Kopfzeile wäre sie schlicht falsch. --}}
                        @if($ersteZeile)
                            <tr wire:key="kopf-{{ $zeile->athlete->id }}"
                                class="bg-zinc-50 dark:bg-zinc-900/40 border-t-2 border-zinc-200 dark:border-zinc-700">
                                <td colspan="9" class="px-4 py-1.5">
                                    <span class="font-semibold text-zinc-900 dark:text-zinc-100">
                                        {{ $zeile->athlete->full_name }}
                                    </span>
                                    <span class="ml-2 text-xs text-zinc-500 dark:text-zinc-400">
                                        Jg. {{ $zeile->birthYear }}
                                        · {{ $zeile->athlete->club?->display_name }}
                                    </span>
                                </td>
                            </tr>
                        @endif

                        @php($abstandFarbe = $erreicht ? 'text-green-600 dark:text-green-400' : 'text-zinc-500')

                        <tr wire:key="talent-{{ $zeile->athlete->id }}-{{ $zeile->eventLabel }}-{{ $zeile->sportClass }}">
                            <td class="px-4 py-1.5 whitespace-nowrap text-zinc-900 dark:text-zinc-100">
                                {{ $zeile->eventLabel }}
                            </td>
                            <td class="px-3 py-1.5 font-mono text-xs text-zinc-900 dark:text-zinc-100">
                                {{ $zeile->sportClass }}
                            </td>
                            <td class="px-3 py-1.5 font-mono text-xs text-zinc-900 dark:text-zinc-100">
                                {{ TimeParser::display($zeile->swimTime) }}
                                <span class="text-zinc-500">{{ $zeile->course }}</span>
                            </td>
                            <td class="px-3 py-1.5 font-mono text-xs text-amber-600 dark:text-amber-400">
                                @if($zeile->estimatedLcmTime !== null)
                                    {{ TimeParser::display($zeile->estimatedLcmTime) }}
                                @endif
                            </td>
                            <td class="px-3 py-1.5 font-mono text-xs text-zinc-600 dark:text-zinc-400">
                                @if($zeile->normTime !== null)
                                    {{ TimeParser::display($zeile->normTime) }}
                                @endif
                            </td>
                            <td class="px-3 py-1.5 text-right font-mono text-zinc-900 dark:text-zinc-100">
                                {{ $zeile->points }}
                            </td>
                            <td class="px-3 py-1.5 text-right font-mono text-xs text-zinc-500">
                                {{ $zeile->thresholdPoints }}
                            </td>
                            <td class="px-3 py-1.5 text-right whitespace-nowrap font-mono text-xs">
                                <span class="{{ $abstandFarbe }}">{{ $zeile->formattedGap() }}</span>
                                <span class="block text-zinc-500">{{ $zeile->percentOfNorm() }} %</span>
                            </td>
                            <td class="px-3 py-1.5 text-xs text-zinc-600 dark:text-zinc-400">
                                {{ $zeile->meetName }}
                                @if($zeile->meetDate)
                                    <span class="block">{{ Carbon::parse($zeile->meetDate)->format('d.m.Y') }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @empty
            <div
                class="p-6 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl text-sm text-zinc-500 dark:text-zinc-400">
                Keine Leistungen im Zeitraum, für die in der Referenznorm ein Bewerb ausgeschrieben
                ist.
                @if($this->config()->normType === WpsTalentReportConfiguration::NORM_MET)
                    Nicht jede Meisterschaft führt MET-Zeiten — mit der MQS als Norm gibt es
                    womöglich Treffer.
                @endif
            </div>
        @endforelse

        @if($this->withoutBirthDate()->isNotEmpty())
            <div
                class="mt-6 p-4 bg-zinc-50 dark:bg-zinc-900/40 border border-zinc-200 dark:border-zinc-700 rounded-xl">
                <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">
                    Ohne Geburtsdatum — keiner Altersgruppe zuzuordnen
                    ({{ $this->withoutBirthDate()->count() }})
                </p>
                <ul class="text-xs text-zinc-600 dark:text-zinc-400 space-y-1 list-disc list-inside">
                    @foreach($this->withoutBirthDate() as $eintrag)
                        <li>
                            {{ $eintrag->athlete->full_name }} — {{ $eintrag->eventLabel }}
                            {{ $eintrag->sportClass }}, {{ $eintrag->points }} Punkte
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endif
</div>
