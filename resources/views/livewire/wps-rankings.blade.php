<div>
    @php
        use App\Models\Result;
        use App\Support\TimeParser;
        use App\Support\WpsRankingFilter;
        use Illuminate\Support\Carbon;
    @endphp

    {{-- ── Ranglistenart ───────────────────────────────────────────────────── --}}
    <div class="mb-4 flex flex-wrap items-end gap-3">
        <flux:field class="w-56">
            <flux:label>Ranglistenart</flux:label>
            <flux:select x-on:change="$wire.setFilter('type', $event.target.value)">
                <option value="season" @selected($type === WpsRankingFilter::TYPE_SEASON)>Saison</option>
                <option value="meet" @selected($type === WpsRankingFilter::TYPE_MEET)>Veranstaltung</option>
            </flux:select>
        </flux:field>

        {{-- Das Jahr gilt für beide Ranglistenarten: Bei der Veranstaltungsrangliste grenzt
             es die Auswahlliste ein, die sonst mit jeder Saison länger wird. --}}
        <flux:field class="w-32">
            <flux:label>Jahr</flux:label>
            <flux:select x-on:change="$wire.setFilter('year', $event.target.value)">
                @foreach($this->availableYears() as $jahr)
                    <option value="{{ $jahr }}" @selected($year === (string) $jahr)>{{ $jahr }}</option>
                @endforeach
            </flux:select>
        </flux:field>

        @if($type === WpsRankingFilter::TYPE_MEET)
            <flux:field class="w-80">
                <flux:label>Veranstaltung</flux:label>
                <flux:select x-on:change="$wire.setFilter('meetId', $event.target.value)">
                    <option value="">Bitte wählen</option>
                    @foreach($this->meets() as $meet)
                        <option value="{{ $meet->id }}" @selected($meetId === (string) $meet->id)>
                            {{ $meet->name }} ({{ $meet->start_date?->format('d.m.Y') }}, {{ $meet->course }})
                        </option>
                    @endforeach
                </flux:select>
            </flux:field>
        @endif

        <flux:field class="w-36">
            <flux:label>Bahnlänge</flux:label>
            <flux:select x-on:change="$wire.setFilter('course', $event.target.value)">
                <option value="SCM" @selected($course === WpsRankingFilter::COURSE_SCM)>Kurzbahn</option>
                <option value="LCM" @selected($course === WpsRankingFilter::COURSE_LCM)>Langbahn</option>
                <option value="MIXED" @selected($course === WpsRankingFilter::COURSE_MIXED)>beide</option>
            </flux:select>
        </flux:field>

        <flux:button wire:click="toggleYouth" variant="{{ $maxAge === '' ? 'ghost' : 'filled' }}" size="sm">
            {{ $maxAge === '' ? 'Nur Jugend (U18)' : 'Altersgrenze aufheben' }}
        </flux:button>

        <flux:button wire:click="resetFilters" variant="ghost" size="sm">Zurücksetzen</flux:button>
    </div>

    {{-- ── Weitere Filter ──────────────────────────────────────────────────── --}}
    <div class="mb-4 flex flex-wrap items-end gap-3">
        <flux:field class="w-44">
            <flux:label>Bewerb</flux:label>
            <flux:select x-on:change="$wire.setFilter('strokeTypeId', $event.target.value)">
                <option value="">Alle</option>
                @foreach($this->strokeTypes() as $strokeType)
                    <option value="{{ $strokeType->id }}" @selected($strokeTypeId === (string) $strokeType->id)>
                        {{ $strokeType->name_de }}
                    </option>
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
            <flux:select x-on:change="$wire.setFilter('gender', $event.target.value)">
                <option value="">Alle</option>
                <option value="M" @selected($gender === 'M')>männlich</option>
                <option value="F" @selected($gender === 'F')>weiblich</option>
            </flux:select>
        </flux:field>

        <flux:field class="w-32">
            <flux:label>Sportklasse</flux:label>
            <flux:select x-on:change="$wire.setFilter('sportClass', $event.target.value)">
                <option value="">Alle</option>
                @foreach($this->availableSportClasses() as $klasse)
                    <option value="{{ $klasse }}" @selected($sportClass === $klasse)>{{ $klasse }}</option>
                @endforeach
            </flux:select>
        </flux:field>

        <flux:field class="w-56">
            <flux:label>Verein</flux:label>
            <flux:select x-on:change="$wire.setFilter('clubId', $event.target.value)">
                <option value="">Alle</option>
                @foreach($this->clubs() as $club)
                    <option value="{{ $club->id }}" @selected($clubId === (string) $club->id)>{{ $club->name }}</option>
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
            <flux:select x-on:change="$wire.setFilter('calculationType', $event.target.value)">
                <option value="">alle</option>
                <option value="official" @selected($calculationType === Result::WPS_TYPE_OFFICIAL)>nur offizielle</option>
                <option value="estimated" @selected($calculationType === Result::WPS_TYPE_ESTIMATED)>nur geschätzte</option>
            </flux:select>
        </flux:field>

        <flux:button wire:click="toggleExhibition"
                     variant="{{ $includeExhibition ? 'filled' : 'ghost' }}" size="sm">
            {{ $includeExhibition ? 'EXH einbezogen' : 'EXH einbeziehen' }}
        </flux:button>
    </div>

    {{-- ── Kaderfilter ─────────────────────────────────────────────────────── --}}
    @if($this->kaderTypes()->isNotEmpty())
        <div
            class="mb-4 p-3 bg-zinc-50 dark:bg-zinc-900/40 border border-zinc-200 dark:border-zinc-700 rounded-xl">
            <div class="flex flex-wrap items-center gap-4">
                <flux:field class="w-56">
                    <flux:label>Kaderfilter</flux:label>
                    <flux:select x-on:change="$wire.setFilter('kaderMode', $event.target.value)">
                        <option value="all" @selected($kaderMode === WpsRankingFilter::KADER_ALL)>
                            wirkt nicht
                        </option>
                        <option value="only" @selected($kaderMode === WpsRankingFilter::KADER_ONLY)>
                            nur ausgewählte zeigen
                        </option>
                        <option value="except" @selected($kaderMode === WpsRankingFilter::KADER_EXCEPT)>
                            ausgewählte ausblenden
                        </option>
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
        </div>
    @endif

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
                        {{ $eintrag->points }}@if($eintrag->isEstimated())<span
                            class="text-amber-600 dark:text-amber-400"
                            title="geschätzt — aus einer umgerechneten Kurzbahnzeit">~</span>@endif
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
