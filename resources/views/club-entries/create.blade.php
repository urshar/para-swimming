@extends('layouts.app')

@section('title', 'Neue Meldung – ' . $meet->name)

@section('content')
    <div class="max-w-2xl">

        {{-- Header --}}
        <div class="flex items-center gap-3 mb-6">
            <flux:button href="{{ route('club-entries.index', $meet) }}" variant="ghost" icon="arrow-left" size="sm"/>
            <div>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Neue Meldung</h1>
                <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                    {{ $meet->name }} · {{ $club->display_name }}
                </p>
            </div>
        </div>

        @php
            $clubParams   = auth()->user()->is_admin && request('club_id') ? ['club_id' => request()->integer('club_id')] : [];
            $eligibleUrl  = route('club-entries.eligible-athletes', array_merge(['meet' => $meet], $clubParams));
            $bestTimesUrl = route('club-entries.best-times', array_merge(['meet' => $meet], $clubParams));
            $oldCourse    = old('entry_course', $meet->course);
        @endphp

        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6"
             x-data="singleEntryForm({
                 eligibleUrl:       '{{ $eligibleUrl }}',
                 bestTimesUrl:      '{{ $bestTimesUrl }}',
                 meetCourse:        '{{ $meet->course }}',
                 selectedEventId:   '{{ old('swim_event_id', '') }}',
                 selectedAthleteId: '{{ old('athlete_id', '') }}',
                 entryTime:         '{{ old('entry_time', '') }}',
                 entryCourse:       '{{ $oldCourse }}',
             })">

            <form method="POST" action="{{ route('club-entries.store', $meet) }}" @submit="onSubmit()">
                @csrf
                @if(auth()->user()->is_admin && request('club_id'))
                    <input type="hidden" name="club_id" value="{{ request()->integer('club_id') }}">
                @endif

                {{-- Event-Auswahl --}}
                <flux:field class="mb-5">
                    <flux:label>Event *</flux:label>
                    <flux:select
                        name="swim_event_id"
                        x-model="selectedEventId"
                        @change="onEventChange()"
                        required>
                        <option value="">Bitte wählen…</option>
                        @foreach($events as $event)
                            <option value="{{ $event->id }}"
                                @selected(old('swim_event_id') == $event->id)>
                                {{ $event->event_number ? 'Nr. '.$event->event_number.' – ' : '' }}{{ $event->display_name }}
                                ({{ $event->gender === 'M' ? 'Männer' : ($event->gender === 'F' ? 'Frauen' : 'Offen') }})
                            </option>
                        @endforeach
                    </flux:select>
                    <flux:error name="swim_event_id"/>
                </flux:field>

                {{-- Athlet-Auswahl (wird per AJAX befüllt) --}}
                <flux:field class="mb-5">
                    <flux:label>Athlet *</flux:label>
                    <div x-show="loadingAthletes" class="flex items-center gap-2 text-sm text-zinc-400 py-2">
                        <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                        Athleten werden geladen…
                    </div>
                    <flux:select
                        name="athlete_id"
                        x-model="selectedAthleteId"
                        @change="onAthleteChange()"
                        x-show="!loadingAthletes"
                        x-bind:disabled="!selectedEventId"
                        required>
                        <option value="">Athlet wählen…</option>
                        <template x-for="athlete in eligibleAthletes" x-bind:key="athlete.id">
                            <option x-bind:value="athlete.id"
                                    x-text="athlete.name + (athlete.classes ? ' (' + athlete.classes + ')' : '')">
                            </option>
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
                        <flux:select name="entry_course" x-model="entryCourse">
                            <option value="LCM">LCM (50m)</option>
                            <option value="SCM">SCM (25m)</option>
                            <option value="SCY">SCY (Yards)</option>
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
