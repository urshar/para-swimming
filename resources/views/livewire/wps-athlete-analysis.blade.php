<div>
    @php
        use App\Models\AthletePerformanceNote;
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

        @php($starttext = $allStarts ? 'Nur Saisonbestleistung' : 'Alle Starts zeigen')
        @php($startvariante = $allStarts ? 'filled' : 'ghost')

        <flux:button wire:click="toggleAllStarts" variant="{{ $startvariante }}" size="sm">
            {{ $starttext }}
        </flux:button>

        @php($grafiktext = $showCharts ? 'Grafik ausblenden' : 'Grafik einblenden')
        @php($grafikvariante = $showCharts ? 'filled' : 'ghost')

        <flux:button wire:click="toggleCharts" variant="{{ $grafikvariante }}" size="sm">
            {{ $grafiktext }}
        </flux:button>

        <flux:button wire:click="resetPeriod" variant="ghost" size="sm">Gesamte Historie</flux:button>

        <flux:button href="{{ $this->pdfUrl() }}" variant="filled" size="sm"
                     icon="document-arrow-down">PDF
        </flux:button>

        @if($this->canViewNotes())
            {{-- Notizen nur auf ausdrücklichen Wunsch ins PDF: Ein PDF wird weitergegeben,
                 und eine Krankheitsnotiz landete sonst womöglich außerhalb des vorgesehenen
                 Kreises (§7.5). --}}
            <flux:button href="{{ $this->pdfUrl(true) }}" variant="ghost" size="sm"
                         icon="document-arrow-down">PDF mit Notizen
            </flux:button>
        @endif
    </div>

    @if($statusMessage)
        <div
            class="mb-4 p-3 bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800 rounded-xl text-sm text-blue-700 dark:text-blue-400">
            {{ $statusMessage }}
        </div>
    @endif

    @php($profil = $this->profile())
    @php($notizen = $this->notesByResult())
    @php($grafiken = $this->charts())

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

        @if(isset($grafiken[$bewerb]) && $grafiken[$bewerb]->isDrawable())
            <div
                class="mb-3 p-3 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl">
                <x-wps-chart :series="$grafiken[$bewerb]"/>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    Senkrechte Linien kennzeichnen Klassenwechsel und Notizen. Ein hervorgehobener
                    Punkt steht für einen Klassenwechsel — die Kurve macht dort einen Sprung, der
                    keine Leistungsentwicklung ist.
                </p>
            </div>
        @endif

        <div
            class="mb-4 bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-x-auto">
            <table class="w-full text-sm border-collapse">
                <thead>
                <tr class="border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900/40 text-left">
                    <th class="px-4 py-2 font-medium text-zinc-600 dark:text-zinc-400">
                        @if($allStarts) Datum @else Saison @endif
                    </th>
                    <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400">Klasse</th>
                    <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400">Zeit</th>
                    <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400">geschätzt LCM</th>
                    <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400 text-right">Punkte</th>
                    <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400 text-right">
                        @if($allStarts) Δ Vorstart @else Δ Vorsaison @endif
                    </th>
                    <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400">Wettkampf</th>
                    <th class="px-3 py-2"></th>
                </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700/50">
                @foreach($zeilen as $zeile)
                    @php($deltaFarbe = $zeile->improved() ? 'text-green-600 dark:text-green-400' : 'text-zinc-500')

                    @php($zeilenBezeichner = $allStarts && $zeile->meetDate
                        ? Carbon::parse($zeile->meetDate)->format('d.m.Y')
                        : (string) $zeile->year)

                    <tr wire:key="zeile-{{ $bewerb }}-{{ $zeile->resultId ?? $zeile->year }}">
                        <td class="px-4 py-1.5 font-mono text-zinc-900 dark:text-zinc-100">
                            {{ $zeilenBezeichner }}
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
                        <td class="px-3 py-1.5 text-right whitespace-nowrap">
                            @if($this->canViewNotes() && $zeile->resultId !== null)
                                <flux:button wire:click="startNote({{ $zeile->resultId }})"
                                             variant="ghost" size="sm" icon="pencil-square"
                                             title="Notiz zu diesem Start"/>
                            @endif
                        </td>
                    </tr>

                    {{-- Notizen unmittelbar unter ihrem Start: Eine Zahlenreihe ist ohne die
                         Ursache nicht deutbar, und in einer eigenen Liste weiter unten müsste
                         man beim Lesen hin und her springen. --}}
                    @foreach($notizen[$zeile->resultId] ?? [] as $notiz)
                        <tr wire:key="notiz-{{ $notiz->id }}" class="bg-zinc-50 dark:bg-zinc-900/40">
                            <td colspan="8" class="px-8 py-1.5 text-xs text-zinc-600 dark:text-zinc-400">
                                <flux:badge color="{{ $notiz->categoryColour() }}" size="sm">
                                    {{ $notiz->categoryLabel() }}
                                </flux:badge>
                                {{ $notiz->note }}
                                <span class="text-zinc-400">
                                    — {{ $notiz->author?->name ?? 'unbekannt' }}
                                </span>
                                @if($this->canDeleteNote($notiz))
                                    <flux:button wire:click="deleteNote({{ $notiz->id }})"
                                                 wire:confirm="Diese Notiz wirklich löschen?"
                                                 variant="ghost" size="sm" icon="trash"/>
                                @endif
                            </td>
                        </tr>
                    @endforeach
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

    @if($this->canViewNotes())
        {{-- ── Notizen ─────────────────────────────────────────────────────── --}}
        <div class="mt-8">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">
                    Notizen ohne Startbezug
                </h2>
                <flux:button wire:click="startNote(null)" variant="filled" size="sm" icon="plus">
                    Notiz hinzufügen
                </flux:button>
            </div>

            <p class="mb-3 text-xs text-zinc-500 dark:text-zinc-400">
                Notizen sind nicht verbandsweit sichtbar — nur für die Verbandsverwaltung und den
                Verein des Athleten. Sie erscheinen standardmäßig nicht im PDF.
            </p>

            @forelse($this->generalNotes() as $notiz)
                <div wire:key="allgemein-{{ $notiz->id }}"
                     class="mb-2 p-3 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl text-sm">
                    <flux:badge color="{{ $notiz->categoryColour() }}" size="sm">
                        {{ $notiz->categoryLabel() }}
                    </flux:badge>
                    <span class="ml-2 font-mono text-xs text-zinc-500">
                        {{ $notiz->noted_on->format('d.m.Y') }}
                    </span>
                    <span class="block mt-1 text-zinc-700 dark:text-zinc-300">{{ $notiz->note }}</span>
                    <span class="block mt-1 text-xs text-zinc-400">
                        {{ $notiz->author?->name ?? 'unbekannt' }}
                        @if($this->canDeleteNote($notiz))
                            <flux:button wire:click="deleteNote({{ $notiz->id }})"
                                         wire:confirm="Diese Notiz wirklich löschen?"
                                         variant="ghost" size="sm" icon="trash"/>
                        @endif
                    </span>
                </div>
            @empty
                <p class="mb-2 text-sm text-zinc-500 dark:text-zinc-400">
                    Keine Notizen ohne Startbezug.
                </p>
            @endforelse
        </div>

        {{-- ── Formular ────────────────────────────────────────────────────── --}}
        @if($noteFormOpen)
            <div
                class="mt-4 p-4 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl">
                <h3 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300 mb-3">
                    @if($noteResultId === null)
                        Neue Notiz
                    @else
                        Neue Notiz zu einem Start
                    @endif
                </h3>

                <div class="flex flex-wrap items-start gap-3">
                    <flux:field class="w-52">
                        <flux:label>Ursache</flux:label>
                        <flux:select x-model="$wire.noteCategory">
                            @foreach(AthletePerformanceNote::categoryLabels() as $wert => $beschriftung)
                                <option value="{{ $wert }}" @selected($noteCategory === $wert)>
                                    {{ $beschriftung }}
                                </option>
                            @endforeach
                        </flux:select>
                        <flux:error name="noteCategory"/>
                    </flux:field>

                    <flux:field class="w-40">
                        <flux:label>Datum</flux:label>
                        <flux:input x-model="$wire.noteDate" type="date"/>
                        <flux:error name="noteDate"/>
                    </flux:field>

                    <flux:field class="flex-1 min-w-64">
                        <flux:label>Notiz</flux:label>
                        <flux:textarea x-model="$wire.noteText" rows="2"
                                       placeholder="z.B. Nach sechs Wochen Trainingspause wegen Schulterverletzung"/>
                        <flux:error name="noteText"/>
                    </flux:field>
                </div>

                <div class="mt-3 flex gap-2">
                    <flux:button wire:click="saveNote" variant="primary" size="sm">Speichern</flux:button>
                    <flux:button wire:click="cancelNote" variant="ghost" size="sm">Abbrechen</flux:button>
                </div>
            </div>
        @endif
    @endif
</div>
