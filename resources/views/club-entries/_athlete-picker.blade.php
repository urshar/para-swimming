{{--
    Athleten-Picker Partial
    Wird von create-relay.blade.php und edit-relay.blade.php eingebunden.
    Erwartet Alpine-Kontext: relayEntryForm (relay-entry-form.js)
--}}

{{-- Startaufstellung (ausgewählte Athleten, sortierbar) --}}
<div x-show="selectedAthletes.length > 0" class="mb-3 space-y-1">
    <p class="text-xs font-semibold text-zinc-400 uppercase tracking-wide mb-1">Startaufstellung</p>
    <template x-for="(athlete, index) in selectedAthletes" :key="athlete.id">
        <div class="flex items-center gap-2 p-2.5 rounded-lg
                    bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-800 text-sm">
            <span class="w-5 h-5 rounded-full bg-blue-600 text-white text-xs font-bold
                         flex items-center justify-center shrink-0"
                  x-text="index + 1"></span>
            <div class="flex-1 min-w-0">
                <span class="font-medium text-zinc-900 dark:text-zinc-100 truncate block"
                      x-text="athlete.name"></span>
                <span class="text-xs text-zinc-400"
                      x-text="athlete.classes || '–'"></span>
            </div>
            {{-- Position verschieben --}}
            <div class="flex flex-col gap-0.5">
                <button type="button" @click="moveUp(index)"
                        :disabled="index === 0"
                        class="p-0.5 rounded hover:bg-blue-100 dark:hover:bg-blue-900/40
                               disabled:opacity-30 disabled:cursor-not-allowed">
                    <svg class="w-3.5 h-3.5 text-zinc-500" fill="none" viewBox="0 0 24 24"
                         stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/>
                    </svg>
                </button>
                <button type="button" @click="moveDown(index)"
                        :disabled="index >= selectedAthletes.length - 1"
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
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
            {{-- Hidden Input für Submission --}}
            <input type="hidden" name="athlete_ids[]" :value="athlete.id">
        </div>
    </template>
</div>

{{-- Spinner --}}
<div x-show="loadingAthletes" class="flex items-center gap-2 text-sm text-zinc-400 py-2">
    <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
    </svg>
    Athleten werden geladen…
</div>

{{-- Verfügbare Athleten --}}
<div x-show="!loadingAthletes && availableAthletes.length > 0">
    <p class="text-xs font-semibold text-zinc-400 uppercase tracking-wide mb-1">Verfügbare Athleten</p>
    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg divide-y divide-zinc-100
                dark:divide-zinc-700/50 max-h-64 overflow-y-auto">
        <template x-for="athlete in availableAthletes" :key="athlete.id">
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
</div>

<p x-show="!loadingAthletes && availableAthletes.length === 0 && (selectedEventId || fixedEventId)"
   class="text-sm text-amber-600 dark:text-amber-400 mt-1">
    Keine Athleten mit passendem Geschlecht gefunden.
</p>
