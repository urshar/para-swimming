<div>
    @php
        use App\Models\Result;
        use App\Support\TimeParser;
        use App\Support\WpsRankingFilter;
        use Illuminate\Support\Carbon;
    @endphp

    {{-- x-model + $watch statt x-on:change/x-model="$wire.property" direkt am flux:select:
         Custom Element <ui-select> feuert sein internes "change"-Event mit bubbles:false,
         auch Livewires eigenes $wire-Binding kommt darüber nicht zuverlässig an (siehe
         resources/js/wps-livewire-filters.js). x-model übernimmt dabei auch die Vorbelegung —
         :selected() auf den Optionen wird dadurch überflüssig. --}}
    <div x-data="wpsLivewireFilters(@js(['type' => $type, 'year' => $year, 'meetId' => $meetId, 'course' => $course, 'ageGroupId' => $ageGroupId, 'strokeTypeId' => $strokeTypeId, 'gender' => $gender, 'sportClass' => $sportClass, 'clubId' => $clubId, 'calculationType' => $calculationType, 'kaderMode' => $kaderMode]), 'setFilter')">
        {{-- ── Ranglistenart ───────────────────────────────────────────────── --}}
        <div class="mb-4 flex flex-wrap items-end gap-3">
            <flux:field class="w-56">
                <flux:label>Ranglistenart</flux:label>
                <flux:select variant="listbox" x-model="type">
                    <flux:select.option value="season">Saison</flux:select.option>
                    <flux:select.option value="meet">Veranstaltung</flux:select.option>
                </flux:select>
            </flux:field>

            {{-- Das Jahr gilt für beide Ranglistenarten: Bei der Veranstaltungsrangliste grenzt
                 es die Auswahlliste ein, die sonst mit jeder Saison länger wird. --}}
            <flux:field class="w-32">
                <flux:label>Jahr</flux:label>
                <flux:select variant="listbox" x-model="year">
                    @foreach($this->availableYears() as $jahr)
                        <flux:select.option value="{{ $jahr }}">{{ $jahr }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            @if($type === WpsRankingFilter::TYPE_MEET)
                <flux:field class="w-80">
                    <flux:label>Veranstaltung</flux:label>
                    <flux:select variant="listbox" x-model="meetId" placeholder="Bitte wählen" clearable>
                        @foreach($this->meets() as $meet)
                            <flux:select.option value="{{ $meet->id }}">
                                {{ $meet->name }} ({{ $meet->start_date?->format('d.m.Y') }}, {{ $meet->course }})
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                </flux:field>
            @endif

            <flux:field class="w-36">
                <flux:label>Bahnlänge</flux:label>
                <flux:select variant="listbox" x-model="course">
                    <flux:select.option value="SCM">Kurzbahn</flux:select.option>
                    <flux:select.option value="LCM">Langbahn</flux:select.option>
                    <flux:select.option value="MIXED">beide</flux:select.option>
                </flux:select>
            </flux:field>

            @if($this->ageGroups()->isNotEmpty())
                <flux:field class="w-44">
                    <flux:label>Altersgruppe</flux:label>
                    <flux:select variant="listbox" x-model="ageGroupId" placeholder="Alle" clearable>
                        @foreach($this->ageGroups() as $ageGroup)
                            <flux:select.option value="{{ $ageGroup->id }}">{{ $ageGroup->name_de }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </flux:field>
            @endif

            <flux:button wire:click="resetFilters" variant="filled" icon="x-mark" class="ml-auto text-red-500!">
                Zurücksetzen
            </flux:button>

            {{-- Der PDF-Link trägt den Filterstand mit; das PDF zeigt sonst etwas anderes als der
                 Bildschirm, von dem aus es erzeugt wurde. --}}
            <flux:button href="{{ $this->pdfUrl() }}" variant="filled" icon="document-arrow-down"
                         class="text-purple-500!">
                PDF
            </flux:button>
        </div>

        {{-- ── Weitere Filter ──────────────────────────────────────────────── --}}
        <div class="mb-4 flex flex-wrap items-end gap-3">
            <flux:field class="w-44">
                <flux:label>Bewerb</flux:label>
                <flux:select variant="listbox" x-model="strokeTypeId" placeholder="Alle" clearable>
                    @foreach($this->strokeTypes() as $strokeType)
                        <flux:select.option value="{{ $strokeType->id }}">{{ $strokeType->name_de }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field class="w-28">
                <flux:label>Strecke</flux:label>
                <flux:input x-model="$wire.distance" x-on:change="$wire.setFilter('distance', $event.target.value)"
                            type="number" placeholder="alle"/>
            </flux:field>

            <flux:field class="w-32">
                <flux:label>Geschlecht</flux:label>
                <flux:select variant="listbox" x-model="gender" placeholder="Alle" clearable>
                    <flux:select.option value="M">männlich</flux:select.option>
                    <flux:select.option value="F">weiblich</flux:select.option>
                </flux:select>
            </flux:field>

            <flux:field class="w-56">
                <flux:label>Sportklasse</flux:label>
                <flux:select variant="listbox" x-model="sportClass" placeholder="Alle Klassen" clearable>
                    @foreach($this->sportClassOptions() as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field class="w-56">
                <flux:label>Verein</flux:label>
                <flux:select variant="listbox" x-model="clubId" placeholder="Alle" clearable>
                    @foreach($this->clubs() as $club)
                        <flux:select.option value="{{ $club->id }}">{{ $club->display_name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field class="w-36">
                <flux:label>Mind. Punkte</flux:label>
                <flux:input x-model="$wire.minPoints" x-on:change="$wire.setFilter('minPoints', $event.target.value)"
                            type="number" placeholder="—"/>
            </flux:field>

            <flux:field class="w-44">
                <flux:label>Punktart</flux:label>
                <flux:select variant="listbox" x-model="calculationType" placeholder="alle" clearable>
                    <flux:select.option value="official">nur offizielle</flux:select.option>
                    <flux:select.option value="estimated">nur geschätzte</flux:select.option>
                </flux:select>
            </flux:field>

            <flux:button wire:click="toggleExhibition"
                         variant="{{ $includeExhibition ? 'filled' : 'ghost' }}" size="sm">
                {{ $includeExhibition ? 'EXH einbezogen' : 'EXH einbeziehen' }}
            </flux:button>
        </div>

        {{-- ── Kaderfilter ─────────────────────────────────────────────────── --}}
        @if($this->kaderTypes()->isNotEmpty())
            <div
                class="mb-4 p-3 bg-zinc-50 dark:bg-zinc-900/40 border border-zinc-200 dark:border-zinc-700 rounded-xl">
                <div class="flex flex-wrap items-center gap-4">
                    {{-- Die Bezeichnungen benennen das Ergebnis, nicht den Zustand des Filters:
                         "wirkt nicht" sagt nichts darüber, was in der Liste steht. --}}
                    <flux:field class="w-64">
                        <flux:label>Kaderarten</flux:label>
                        <flux:select variant="listbox" x-model="kaderMode">
                            <flux:select.option value="all">Alle Athleten zeigen</flux:select.option>
                            <flux:select.option value="only">Ausgewählte zeigen</flux:select.option>
                            <flux:select.option value="except">Ausgewählte nicht zeigen</flux:select.option>
                        </flux:select>
                    </flux:field>

                    <div class="flex flex-wrap items-center gap-3 pt-5">
                        @foreach($this->kaderTypes() as $kaderType)
                            <flux:checkbox wire:click="toggleKader({{ $kaderType->id }})"
                                           :checked="$this->isKaderSelected($kaderType->id)"
                                           label="{{ $kaderType->name_de }}"/>
                        @endforeach

                        {{-- Ohne diesen Eintrag ließe sich "nur Kaderathleten" nicht ausdrücken,
                             und beim Ausblenden verschwänden Athleten ohne Zuordnung entweder
                             immer oder nie — beides wäre eine stille Festlegung. --}}
                        <flux:checkbox wire:click="toggleKader(0)"
                                       :checked="$this->isKaderSelected(0)"
                                       label="ohne Kaderzuordnung"/>
                    </div>
                </div>

                <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                    @if($this->filter()->hasKaderFilter())
                        @if($kaderMode === WpsRankingFilter::KADER_ONLY)
                            Es werden ausschließlich Athleten der angehakten Kaderarten gezeigt.
                        @else
                            Athleten der angehakten Kaderarten sind ausgeblendet.
                        @endif
                    @else
                        Solange keine Kaderart angehakt ist, werden alle Athleten gezeigt.
                    @endif
                    Kaderzugehörigkeit zum Stichtag
                    {{ Carbon::parse($this->kaderReferenceDate())->format('d.m.Y') }}.
                </p>
            </div>
        @endif
    </div>

    {{-- ── Kopfbereich ─────────────────────────────────────────────────────── --}}
    <p class="mb-2 text-xs text-zinc-500 dark:text-zinc-400">
        {{ implode(' · ', $this->filter()->describe()) }}
        @if($this->usedVersions() !== [])
            · @if(count($this->usedVersions()) > 1) Punkteversionen @else Punkteversion @endif
            {{ implode(', ', $this->usedVersions()) }}
        @endif
    </p>

    @if(count($this->usedVersions()) > 1)
        <div
            class="mb-4 p-3 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800 rounded-xl text-sm text-amber-700 dark:text-amber-400">
            Diese Rangliste enthält Ergebnisse aus mehreren WPS-Punkteversionen. Die Punkte
            stammen jeweils aus der am Ergebnis gespeicherten Version und sind untereinander nur
            eingeschränkt vergleichbar.
        </div>
    @endif

    @if($this->filter()->isMixedCourse())
        <div
            class="mb-4 p-3 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800 rounded-xl text-sm text-amber-700 dark:text-amber-400">
            Lang- und Kurzbahnergebnisse werden gemeinsam gezeigt. Kurzbahnpunkte beruhen auf einer
            umgerechneten Langbahnzeit und sind eine Schätzung — sie sind mit offiziellen
            Langbahnpunkten nicht gleichwertig.
        </div>
    @endif

    {{-- ── Tabelle ─────────────────────────────────────────────────────────── --}}
    @if($this->page()->total() > 0)
        <p class="mb-2 text-xs text-zinc-500 dark:text-zinc-400">
            {{ $this->page()->firstItem() }}–{{ $this->page()->lastItem() }} von
            {{ $this->page()->total() }} Leistungen
        </p>
    @endif

    <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-x-auto">
        <table class="w-full text-sm border-collapse">
            <thead>
            <tr class="border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900/40 text-left">
                <th class="px-4 py-2 font-medium text-zinc-600 dark:text-zinc-400">Rang</th>
                <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400">Athlet</th>
                <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400">Alter</th>
                <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400">Verein</th>
                <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400">Bewerb</th>
                <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400">Klasse</th>
                <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400">Zeit</th>
                <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400">geschätzt LCM</th>
                <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400 text-right whitespace-nowrap">
                    Punkte<span class="text-amber-600 dark:text-amber-400"> ~</span>
                </th>
                <th class="px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400">Wettkampf</th>
            </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700/50">
            @forelse($this->page() as $eintrag)
                <tr wire:key="entry-{{ $eintrag->result->id }}">
                    <td class="px-4 py-1.5 font-mono text-zinc-900 dark:text-zinc-100">{{ $eintrag->rank }}</td>
                    <td class="px-3 py-1.5 text-zinc-900 dark:text-zinc-100">
                        {{ $eintrag->athlete->full_name }}
                    </td>
                    <td class="px-3 py-1.5 font-mono text-xs text-zinc-600 dark:text-zinc-400">
                        {{ $eintrag->age ?? '–' }}
                    </td>
                    <td class="px-3 py-1.5 text-xs text-zinc-600 dark:text-zinc-400">
                        {{ $eintrag->athlete->club?->display_name }}
                    </td>
                    <td class="px-3 py-1.5 whitespace-nowrap text-zinc-900 dark:text-zinc-100">
                        {{ $eintrag->eventLabel }}
                    </td>
                    <td class="px-3 py-1.5 font-mono text-xs text-zinc-900 dark:text-zinc-100">
                        {{ $eintrag->sportClass }}
                    </td>
                    <td class="px-3 py-1.5 font-mono text-xs text-zinc-900 dark:text-zinc-100">
                        {{ TimeParser::display($eintrag->swimTime) }}
                        <span class="text-zinc-500">{{ $eintrag->course }}</span>
                    </td>
                    <td class="px-3 py-1.5 font-mono text-xs text-amber-600 dark:text-amber-400">
                        @if($eintrag->estimatedLcmTime !== null)
                            {{ TimeParser::display($eintrag->estimatedLcmTime) }}
                        @endif
                    </td>
                    {{-- Kurzzeichen statt Beschriftung: Ein "geschätzt" neben jeder Zahl
                         verbreitert die Spalte so weit, dass sie umbricht — und da fast alle
                         österreichischen Ergebnisse Kurzbahn sind, stünde es ohnehin in nahezu
                         jeder Zeile. Die Erklärung steht einmal unter der Tabelle. --}}
                    <td class="px-3 py-1.5 text-right whitespace-nowrap font-mono text-zinc-900 dark:text-zinc-100">
                        @if($eintrag->isEstimated())<span
                            class="text-amber-600 dark:text-amber-400 me-1"
                            title="geschätzt — aus einer umgerechneten Kurzbahnzeit">~</span>@endif{{ $eintrag->points }}
                    </td>
                    <td class="px-3 py-1.5 text-xs text-zinc-600 dark:text-zinc-400">
                        {{ $eintrag->meetName }}
                        @if($eintrag->meetDate)
                            <span class="block">{{ Carbon::parse($eintrag->meetDate)->format('d.m.Y') }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" class="px-4 py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">
                        Keine gewerteten Leistungen für diese Auswahl.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{-- Legende einmal unter der Tabelle statt an jeder Zeile. --}}
    <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
        <span class="text-amber-600 dark:text-amber-400 font-mono">~</span>
        Geschätzte Punkte — aus einer auf Langbahn umgerechneten Kurzbahnzeit ermittelt und
        damit kein offizieller Wert.
    </p>

    @if($this->page()->hasPages())
        <div class="mt-4">{{ $this->page()->links() }}</div>
    @endif

    {{-- Fehlende Zuordnungen bleiben sichtbar, statt still zu verschwinden (§5). --}}
    @if($this->withoutBirthDate()->isNotEmpty())
        <div
            class="mt-6 p-4 bg-zinc-50 dark:bg-zinc-900/40 border border-zinc-200 dark:border-zinc-700 rounded-xl">
            <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">
                Ohne Geburtsdatum — aus der Altersrangliste ausgeschlossen
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
</div>
