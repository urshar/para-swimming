{{--
    Athleten-Picker Partial
    Wird von create-relay.blade.php und edit-relay.blade.php eingebunden.
    Erwartet Alpine-Kontext: relayEntryForm (relay-entry-form.js)

    Zweispaltig: links die verfügbaren Athleten zum Anklicken, rechts die
    Startaufstellung (Reihenfolge = Startposition). Untereinander (eine Spalte)
    war bei vielen Athleten unübersichtlich — Auswahl und Ergebnis sind jetzt auf
    einen Blick nebeneinander sichtbar. Bereits in einer anderen Staffelmeldung
    desselben Events gemeldete Athleten liefert das Backend
    (ClubEntryService::eligibleRelayAthletes()) gar nicht erst mit aus.

    PhpStorm-Hinweis (FALSCH-POSITIVE, ignorieren): Weil dies ein @include-Partial ist,
    sieht PhpStorm das x-data (relayEntryForm) auf dem Elternelement der einbindenden
    Formulare nicht und meldet in jeder Alpine-Bindung "Element is not exported" /
    "Unresolved variable athlete" / "Missing import statement". Das sind KEINE echten
    Fehler — der Scope existiert zur Laufzeit. Echte Fehler wären rote Syntaxfehler
    (z. B. das früher hier verwendete `x-for="(athlete, index) in …"`, das PhpStorm als
    JS-for-in zerbrach; deshalb `x-for="athlete in …"` + `positionOf(athlete)`). Siehe
    CLAUDE.md, Abschnitt "PhpStorm-Fallstricke" → Alpine in @include-Partials.
--}}

<div class="grid grid-cols-2 gap-4">

    {{-- Verfügbare Athleten --}}
    <div>
        <p class="text-xs font-semibold text-zinc-400 uppercase tracking-wide mb-1">Verfügbare Athleten</p>

        {{-- Filter (Geschlecht + Sportklassen): grenzt bei großen Vereinen die Auswahl ein.
             Klassen sind mehrfach wählbar (z. B. S14 UND S21 gleichzeitig) — ein Athlet wird
             gezeigt, wenn er eine der gewählten Klassen hat. Rein client-seitig auf die bereits
             geladene Liste (die nur aktive Athleten enthält). --}}
        <div x-show="!loadingAthletes && availableAthletes.length > 0" class="space-y-2 mb-2">
            <flux:select variant="listbox" x-model="filterGender" placeholder="Alle Geschlechter"
                         clearable size="sm" class="max-w-56">
                <template x-for="g in availableGenders()" :key="g">
                    <flux:select.option x-bind:value="g" x-text="genderLabel(g)"></flux:select.option>
                </template>
            </flux:select>
            <div x-show="availableClasses().length > 0" class="flex flex-wrap items-center gap-1.5">
                <span class="text-xs text-zinc-400">Sportklassen:</span>
                <template x-for="c in availableClasses()" :key="c">
                    <button type="button" @click="toggleClass(c)"
                            :class="isClassSelected(c) ? 'bg-blue-600 text-white border-blue-600' : 'text-zinc-600 dark:text-zinc-300 border-zinc-200 dark:border-zinc-700 hover:border-blue-400'"
                            class="text-xs rounded-full border px-2 py-0.5 transition-colors"
                            x-text="'S' + c"></button>
                </template>
                <button type="button" x-show="filterClasses.length > 0" @click="filterClasses = []"
                        class="text-xs text-zinc-400 hover:text-red-500 underline ms-1">zurücksetzen
                </button>
            </div>
        </div>

        <div x-show="loadingAthletes" class="flex items-center gap-2 text-sm text-zinc-400 py-2">
            <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
            </svg>
            Athleten werden geladen…
        </div>

        <div x-show="!loadingAthletes && filteredAvailableAthletes().length > 0"
             class="border border-zinc-200 dark:border-zinc-700 rounded-lg divide-y divide-zinc-100
                    dark:divide-zinc-700/50 max-h-80 overflow-y-auto">
            <template x-for="athlete in filteredAvailableAthletes()" :key="athlete.id">
                <button type="button"
                        @click="toggleAthlete(athlete)"
                        :disabled="!isSelected(athlete) && selectedAthletes.length >= relayCount"
                        class="w-full flex items-center gap-3 px-3 py-2.5 text-left text-sm
                               transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-700/50
                               disabled:opacity-40 disabled:cursor-not-allowed"
                        :class="isSelected(athlete) ? 'bg-blue-50 dark:bg-blue-950/20' : ''">
                    <span class="w-4 h-4 rounded border flex items-center justify-center shrink-0 transition-colors"
                          :class="isSelected(athlete) ? 'bg-blue-600 border-blue-600' : 'border-zinc-300 dark:border-zinc-600'">
                        <svg x-show="isSelected(athlete)" class="w-3 h-3 text-white" fill="none"
                             viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                        </svg>
                    </span>
                    <div class="flex-1 min-w-0">
                        <span class="font-medium text-zinc-900 dark:text-zinc-100 truncate block"
                              x-text="athlete.name"></span>
                        <span class="text-xs text-zinc-400"
                              x-text="(athlete.classes || '–') + (athlete.birth_year ? ' · *' + athlete.birth_year : '')"></span>
                    </div>
                </button>
            </template>
        </div>

        <p x-show="!loadingAthletes && availableAthletes.length === 0 && (selectedEventId || fixedEventId)"
           class="text-sm text-amber-600 dark:text-amber-400 mt-1">
            Keine Athleten mit passendem Geschlecht gefunden.
        </p>
        <p x-show="!loadingAthletes && availableAthletes.length > 0 && filteredAvailableAthletes().length === 0"
           class="text-sm text-amber-600 dark:text-amber-400 mt-1">
            Keine Athleten mit diesem Filter.
        </p>
    </div>

    {{-- Startaufstellung (ausgewählte Athleten, sortierbar) --}}
    <div>
        <p class="text-xs font-semibold text-zinc-400 uppercase tracking-wide mb-1">Startaufstellung</p>

        <div x-show="selectedAthletes.length > 0" class="space-y-1 max-h-136 overflow-y-auto">
            <template x-for="athlete in selectedAthletes" :key="athlete.id">
                <div class="flex items-center gap-2 p-2.5 rounded-lg
                            bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-800 text-sm">
                    <span class="w-5 h-5 rounded-full bg-blue-600 text-white text-xs font-bold
                                 flex items-center justify-center shrink-0"
                          x-text="positionOf(athlete) + 1"></span>
                    <div class="flex-1 min-w-0">
                        <span class="font-medium text-zinc-900 dark:text-zinc-100 truncate block"
                              x-text="athlete.name"></span>
                        <span class="text-xs text-zinc-400"
                              x-text="athlete.classes || '–'"></span>
                        {{-- Gewählte Einzel-Bestzeit dieses Schwimmers (JBZ/ABZ) für den aktuellen Kurs;
                             Umschalter bestimmt, welche Zeit in die gemischte Summe eingeht. --}}
                        <div class="flex items-center gap-2 mt-0.5" x-show="legFor(athlete.id)">
                            <span class="text-xs font-mono text-blue-700 dark:text-blue-300"
                                  x-text="legTimeLabel(athlete)"></span>
                            <span
                                class="inline-flex rounded-md overflow-hidden border border-blue-200 dark:border-blue-800 text-[10px] leading-none">
                                <button type="button" @click="setLegMode(athlete, 'year')"
                                        :class="isLegMode(athlete, 'year') ? 'bg-blue-600 text-white' : 'text-blue-600 dark:text-blue-300'"
                                        class="px-1.5 py-0.5">JBZ</button>
                                <button type="button" @click="setLegMode(athlete, 'absolute')"
                                        :class="isLegMode(athlete, 'absolute') ? 'bg-blue-600 text-white' : 'text-blue-600 dark:text-blue-300'"
                                        class="px-1.5 py-0.5">ABZ</button>
                            </span>
                        </div>
                    </div>
                    {{-- Position verschieben --}}
                    <div class="flex flex-col gap-0.5">
                        <button type="button" @click="moveUp(positionOf(athlete))"
                                :disabled="positionOf(athlete) === 0"
                                class="p-0.5 rounded hover:bg-blue-100 dark:hover:bg-blue-900/40
                                       disabled:opacity-30 disabled:cursor-not-allowed">
                            <svg class="w-3.5 h-3.5 text-zinc-500" fill="none" viewBox="0 0 24 24"
                                 stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/>
                            </svg>
                        </button>
                        <button type="button" @click="moveDown(positionOf(athlete))"
                                :disabled="positionOf(athlete) >= selectedAthletes.length - 1"
                                class="p-0.5 rounded hover:bg-blue-100 dark:hover:bg-blue-900/40
                                       disabled:opacity-30 disabled:cursor-not-allowed">
                            <svg class="w-3.5 h-3.5 text-zinc-500" fill="none" viewBox="0 0 24 24"
                                 stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                    </div>
                    {{-- Entfernen --}}
                    <button type="button" @click="toggleAthlete(athlete)"
                            class="p-1 rounded hover:bg-red-50 dark:hover:bg-red-950/30 text-zinc-400 hover:text-red-500">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                             stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                    {{-- Hidden Input für Submission --}}
                    <input type="hidden" name="athlete_ids[]" :value="athlete.id">
                </div>
            </template>
        </div>

        <p x-show="selectedAthletes.length === 0"
           class="text-sm text-zinc-400 dark:text-zinc-500 italic py-2 px-2.5 text-center
                  border border-dashed border-zinc-200 dark:border-zinc-700 rounded-lg">
            Noch keine Athleten ausgewählt.
        </p>
    </div>

</div>
