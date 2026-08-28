@extends('layouts.app')

@section('title', 'Neue Meldung – ' . $meet->name)

@section('content')
    @php $clubParams = auth()->user()->is_admin && request('club_id') ? ['club_id' => request()->integer('club_id')] : []; @endphp
    <div class="max-w-2xl">

        {{-- Header --}}
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Neue Meldung</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                {{ $meet->name }} · {{ $club->display_name }}
            </p>

            <div class="mt-4">
                <flux:button href="{{ route('club-entries.index', array_merge(['meet' => $meet], $clubParams)) }}"
                             variant="filled" icon="arrow-left" size="sm">
                    Zurück
                </flux:button>
            </div>
        </div>

        @php
            // Konfiguration als EIN zusammenhängender JSON-Wert übergeben (siehe
            // meets/form.blade.php $wpsAlpineConfig): einzelne {{ }}-Ausdrücke, über mehrere
            // Zeilen verteilt mitten in einem JS-Objektliteral im x-data-Attribut, lassen sich
            // von PhpStorms JS-Parser nicht mehr als zusammenhängender Ausdruck lesen ("Expression
            // statement is not assignment or call", kaskadiert bis in die Typprüfung gegen
            // SingleEntryFormConfig). @json() macht daraus einen einzigen Blade-Ausdruck.
            $singleEntryFormConfig = [
                'eligibleUrl' => route('club-entries.eligible-athletes', array_merge(['meet' => $meet], $clubParams)),
                'bestTimesUrl' => route('club-entries.best-times', array_merge(['meet' => $meet], $clubParams)),
                'meetCourse' => $meet->course,
                'selectedEventId' => old('swim_event_id', ''),
                'selectedAthleteId' => old('athlete_id', ''),
                'entryTime' => old('entry_time', ''),
                'entryCourse' => old('entry_course', $meet->course),
            ];
        @endphp

        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6"
             x-data='singleEntryForm(@json($singleEntryFormConfig))'>

            <form method="POST" action="{{ route('club-entries.store', $meet) }}" @submit="onSubmit()">
                @csrf
                @if(auth()->user()->is_admin && request('club_id'))
                    <input type="hidden" name="club_id" value="{{ request()->integer('club_id') }}">
                @endif

                {{-- Event-Auswahl --}}
                <flux:field class="mb-5">
                    <flux:label>Event <span class="text-red-500 dark:text-red-400">*</span></flux:label>
                    <flux:select
                        variant="listbox"
                        name="swim_event_id"
                        x-model="selectedEventId"
                        @change="onEventChange()"
                        required>
                        @foreach($events as $event)
                            <flux:select.option value="{{ $event->id }}"
                                :selected="old('swim_event_id') == $event->id">
                                {{ $event->event_number ? 'Nr. '.$event->event_number.' – ' : '' }}{{ $event->display_name }}
                                ({{ $event->gender === 'M' ? 'Männer' : ($event->gender === 'F' ? 'Frauen' : 'Offen') }})
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="swim_event_id"/>
                </flux:field>

                {{-- Athlet-Auswahl (wird per AJAX befüllt) --}}
                <flux:field class="mb-5">
                    <flux:label>Athlet <span class="text-red-500 dark:text-red-400">*</span></flux:label>
                    <div x-show="loadingAthletes" class="flex items-center gap-2 text-sm text-zinc-400 py-2">
                        <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                        Athleten werden geladen…
                    </div>
                    <flux:select
                        variant="listbox"
                        name="athlete_id"
                        x-model="selectedAthleteId"
                        @change="onAthleteChange()"
                        x-show="!loadingAthletes"
                        x-bind:disabled="!selectedEventId"
                        required>
                        <template x-for="athlete in eligibleAthletes" x-bind:key="athlete.id">
                            <flux:select.option x-bind:value="athlete.id"
                                    x-text="athlete.name + (athlete.classes ? ' (' + athlete.classes + ')' : '')">
                            </flux:select.option>
                        </template>
                    </flux:select>
                    <p x-show="!loadingAthletes && selectedEventId && eligibleAthletes.length === 0"
                       class="text-xs text-amber-600 dark:text-amber-400 mt-1">
                        Keine geeigneten Athleten gefunden (Sportklasse oder Geschlecht passen nicht).
                    </p>
                    <flux:error name="athlete_id"/>
                </flux:field>

                {{-- Bestzeiten-Anzeige --}}
                <div x-show="selectedAthleteId && selectedEventId"
                     class="mb-5 p-3 rounded-lg bg-zinc-50 dark:bg-zinc-900/40 border border-zinc-200 dark:border-zinc-700 text-sm">
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-2 uppercase tracking-wide">
                        Jahresbestzeit (Vorjahr bis Meetbeginn)
                    </p>
                    <div x-show="loadingTimes" class="text-zinc-400 text-xs">Wird geladen…</div>
                    <div x-show="!loadingTimes" class="flex gap-6 items-end">
                        <div>
                            <span class="text-xs text-zinc-400">LCM</span>
                            <p class="font-mono font-semibold text-zinc-900 dark:text-zinc-100"
                               x-text="bestTimes.LCM ? bestTimes.LCM.formatted : 'NT'"></p>
                        </div>
                        <div>
                            <span class="text-xs text-zinc-400">SCM</span>
                            <p class="font-mono font-semibold text-zinc-900 dark:text-zinc-100"
                               x-text="bestTimes.SCM ? bestTimes.SCM.formatted : 'NT'"></p>
                        </div>
                        <div class="ml-auto">
                            <button type="button"
                                    x-show="bestTimes[meetCourse] && bestTimes[meetCourse].formatted !== 'NT'"
                                    @click="applyBestTime()"
                                    class="text-xs text-blue-600 dark:text-blue-400 hover:underline">
                                Bestzeit übernehmen (<span x-text="meetCourse"></span>)
                            </button>
                        </div>
                    </div>
                </div>

                {{-- Meldezeit + Kurs --}}
                <div class="grid grid-cols-2 gap-4 mb-5 items-start">
                    <flux:field>
                        <flux:label>Meldezeit</flux:label>
                        <flux:input
                            name="entry_time"
                            type="text"
                            x-model="entryTime"
                            placeholder="00:00.00"
                            autocomplete="off"
                            x-init="
                                const mask = IMask($el.querySelector('input') ?? $el, {
                                    mask: '00:00.00',
                                    lazy: false,
                                    placeholderChar: '0'
                                });
                                mask.on('accept', () => { entryTime = mask.value; });
                                $watch('entryTime', v => { if (mask.value !== v) mask.value = v; });
                            "
                        />
                        <flux:description class="mt-1">MM:SS.hh — z.B. 01:23.45</flux:description>
                        <flux:error name="entry_time"/>
                    </flux:field>

                    <flux:field>
                        <flux:label>Kurs</flux:label>
                        <flux:select variant="listbox" name="entry_course" x-model="entryCourse">
                            <flux:select.option value="LCM">LCM (50m)</flux:select.option>
                            <flux:select.option value="SCM">SCM (25m)</flux:select.option>
                            <flux:select.option value="SCY">SCY (Yards)</flux:select.option>
                        </flux:select>
                        <flux:error name="entry_course"/>
                    </flux:field>
                </div>

                {{-- Buttons --}}
                <div class="flex gap-3 pt-2">
                    <flux:button type="submit" variant="primary" x-bind:disabled="submitting">
                        <span x-show="!submitting">Meldung speichern</span>
                        <span x-show="submitting">Wird gespeichert…</span>
                    </flux:button>
                    <flux:button href="{{ route('club-entries.index', $meet) }}" variant="ghost">
                        Abbrechen
                    </flux:button>
                </div>

            </form>
        </div>
    </div>
@endsection
