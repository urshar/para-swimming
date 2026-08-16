<div>
    @php
        use App\Support\TimeParser;
        use App\Support\WpsRankingFilter;
        use Illuminate\Support\Carbon;
    @endphp

    {{-- ── Zeitraum und Bahnlänge ──────────────────────────────────────────── --}}
    <div class="mb-4 flex flex-wrap items-end gap-3">
        <flux:field class="w-28">
            <flux:label>Von</flux:label>
            <flux:select x-on:change="$wire.setInput('fromYear', $event.target.value)">
                <option value="">Anfang</option>
                @foreach($this->years() as $jahr)
                    <option value="{{ $jahr }}" @selected($fromYear === (string) $jahr)>{{ $jahr }}</option>
                @endforeach
            </flux:select>
        </flux:field>

        <flux:field class="w-28">
            <flux:label>Bis</flux:label>
            <flux:select x-on:change="$wire.setInput('toYear', $event.target.value)">
                <option value="">Ende</option>
                @foreach($this->years() as $jahr)
                    <option value="{{ $jahr }}" @selected($toYear === (string) $jahr)>{{ $jahr }}</option>
                @endforeach
            </flux:select>
        </flux:field>

        <flux:field class="w-36">
            <flux:label>Bahnlänge</flux:label>
            <flux:select x-on:change="$wire.setInput('course', $event.target.value)">
                <option value="MIXED" @selected($course === WpsRankingFilter::COURSE_MIXED)>beide</option>
                <option value="SCM" @selected($course === WpsRankingFilter::COURSE_SCM)>Kurzbahn</option>
                <option value="LCM" @selected($course === WpsRankingFilter::COURSE_LCM)>Langbahn</option>
            </flux:select>
        </flux:field>

        <flux:button wire:click="resetPeriod" variant="ghost" size="sm">Gesamte Historie</flux:button>

        <flux:button href="{{ $this->pdfUrl() }}" variant="filled" size="sm"
                     icon="document-arrow-down">PDF
        </flux:button>
    </div>

    @php($profil = $this->profile())

    {{-- ── Kennzahlen ──────────────────────────────────────────────────────── --}}
    @if(! $profil->isEmpty())
        <div class="mb-4 grid grid-cols-2 sm:grid-cols-4 gap-3">
            @php($kennzahlen = [
                'Beste Punktzahl' => $profil->bestPoints(),
                'Bewerbe' => $profil->eventCount(),
                'Gewertete Leistungen' => $profil->entryCount(),
                'Zeitraum' => $profil->firstYear === $profil->lastYear
                    ? $profil->firstYear
                    : $profil->firstYear.'–'.$profil->lastYear,
            ])

            @foreach($kennzahlen as $label => $wert)
                <div class="p-3 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl">
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $label }}</div>
                    <div class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">{{ $wert }}</div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Sportklassen werden im Datenmodell nicht historisiert; ein Wechsel ist nur über die
         Ergebnisse erkennbar und muss deshalb ausgewiesen werden (§7.2). --}}
    @if($profil->hasClassChange())
        <div
            class="mb-4 p-4 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800 rounded-xl text-sm text-amber-700 dark:text-amber-400">
            <strong>Sportklasse gewechselt.</strong>
            @foreach($profil->changedCategories() as $kategorie => $klassen)
                {{ $kategorie }}: {{ implode(' → ', $klassen) }}.
            @endforeach
            Punkte aus verschiedenen Klassen sind nur eingeschränkt vergleichbar; die Differenz zur
            Vorsaison entfällt an der Stelle des Wechsels.
        </div>
    @endif

    @if($course === WpsRankingFilter::COURSE_MIXED)
        <p class="mb-4 text-xs text-zinc-500 dark:text-zinc-400">
            Lang- und Kurzbahn werden gemeinsam gezeigt. Kurzbahnpunkte beruhen auf einer
            umgerechneten Langbahnzeit und sind eine Schätzung.
        </p>
    @endif

    {{-- ── Entwicklung je Bewerb ───────────────────────────────────────────── --}}
    @forelse($profil->byEvent as $bewerb => $zeilen)
        <h2 class="mt-6 mb-2 text-sm font-semibold text-zinc-700 dark:text-zinc-300">
            {{ $bewerb }}
            <span class="font-normal text-zinc-500 dark:text-zinc-400">
                · beste Punktzahl {{ $zeilen->max(fn ($z) => $z->points) }}
            </span>
        </h2>

        <div
            class="mb-4 bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-x-auto">
            <table class="w-full text-sm border-collapse">
                <thead>
                <tr class="border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900/40 text-left">
                    <th class="px-4 py-2 font-medium text-zinc-600 dark:text-zinc-400">Saison</th>
                    <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400">Klasse</th>
                    <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400">Zeit</th>
                    <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400">geschätzt LCM</th>
                    <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400 text-right">Punkte</th>
                    <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400 text-right">
                        Δ Vorsaison
                    </th>
                    <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400">Wettkampf</th>
                </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700/50">
                @foreach($zeilen as $zeile)
                    @php($deltaFarbe = $zeile->improved() ? 'text-green-600 dark:text-green-400' : 'text-zinc-500')

                    <tr wire:key="saison-{{ $bewerb }}-{{ $zeile->year }}-{{ $zeile->sportClass }}">
                        <td class="px-4 py-1.5 font-mono text-zinc-900 dark:text-zinc-100">{{ $zeile->year }}</td>
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
                        <td class="px-3 py-1.5 text-right font-mono text-zinc-900 dark:text-zinc-100">
                            {{ $zeile->points }}
                        </td>
                        <td class="px-3 py-1.5 text-right whitespace-nowrap font-mono text-xs">
                            @if($zeile->classChanged)
                                <span class="text-amber-600 dark:text-amber-400">Klassenwechsel</span>
                            @elseif($zeile->hasComparison())
                                <span class="{{ $deltaFarbe }}">{{ $zeile->formattedPointsDelta() }}</span>
                                <span class="block text-zinc-500">{{ $zeile->formattedTimeDelta() }}</span>
                            @else
                                <span class="text-zinc-400">–</span>
                            @endif
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
            Für diesen Athleten liegen im gewählten Zeitraum keine gewerteten Leistungen vor.
        </div>
    @endforelse
</div>
