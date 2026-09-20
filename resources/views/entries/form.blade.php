@extends('layouts.app')

@section('title', 'Meldung anlegen – ' . $meet->name)

@section('content')
    @php
        // Für die automatische Club-Vorbelegung beim Athleten-Wechsel (bleibt änderbar) —
        // Athlet -> aktueller Verein, aus der bereits geladenen $athletes-Collection.
        $athleteClubMap = $athletes->pluck('club.id', 'id');

        // Einzelne Variablen statt old(...) direkt in @json(): @json() teilt sein Argument
        // naiv an jedem Komma auf (Blade\Compilers\Concerns\CompilesJson::compileJson()) —
        // old("key", "default") hat selbst ein Komma und würde mitten im Ausdruck zerschnitten.
        $oldAthleteId = old('athlete_id', '');
        $oldClubId = old('club_id', '');
        $oldEntryTime = old('entry_time', '');
        $oldEventId = old('swim_event_id', '');
        $oldEntryCourse = old('entry_course', '');

        // Config als EINE Variable an @json übergeben (siehe Komma-Caveat oben).
        $entryBestTimesConfig = [
            'bestTimesUrl' => route('meets.entries.best-times', $meet),
            'athleteClubMap' => $athleteClubMap,
            'athleteId' => $oldAthleteId,
            'clubId' => $oldClubId,
            'eventId' => $oldEventId,
            'entryTime' => $oldEntryTime,
            'entryCourse' => $oldEntryCourse,
        ];
    @endphp
    <div class="max-w-2xl">

        {{-- Header --}}
        <div class="mb-6">
            <div class="flex items-center gap-2">
                <flux:button href="{{ route('meets.show', $meet) }}" variant="primary" icon="arrow-left" size="sm"
                             title="Zurück" aria-label="Zurück"/>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Meldung anlegen</h1>
            </div>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">{{ $meet->name }}</p>
        </div>

        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 p-6">
            <form method="POST" action="{{ route('meets.entries.store', $meet) }}" class="space-y-4"
                  x-data='entryBestTimes(@json($entryBestTimesConfig))'>
                @csrf

                <flux:field>
                    <flux:label>Disziplin<span class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
                    <flux:select variant="listbox" searchable name="swim_event_id" x-model="selectedEventId" required>
                        @foreach($swimEvents->groupBy('session_number') as $session => $events)
                            <flux:select.group label="Session {{ $session }}">
                                @foreach($events as $event)
                                    <flux:select.option value="{{ $event->id }}" :selected="old('swim_event_id') == $event->id">
                                        {{ $event->display_name }}
                                        {{ $event->gender !== 'A' ? '(' . $event->gender . ')' : '' }}
                                        {{ $event->sport_classes ? '– ' . $event->sport_classes : '' }}
                                    </flux:select.option>
                                @endforeach
                            </flux:select.group>
                        @endforeach
                    </flux:select>
                    <flux:error name="swim_event_id"/>
                </flux:field>

                <flux:field>
                    <flux:label>Athlet<span class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
                    <flux:select variant="listbox" searchable name="athlete_id" x-model="athleteId" required>
                        @foreach($athletes as $athlete)
                            <flux:select.option value="{{ $athlete->id }}" :selected="old('athlete_id') == $athlete->id">
                                {{ $athlete->display_name }}
                                {{ $athlete->sport_classes_display ? '– ' . $athlete->sport_classes_display : '' }}
                                ({{ $athlete->nation?->code }})
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="athlete_id"/>
                </flux:field>

                <flux:field>
                    <flux:label>Meldender Club<span class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
                    <flux:select variant="listbox" searchable name="club_id" x-model="clubId" required>
                        @foreach($clubs as $club)
                            <flux:select.option value="{{ $club->id }}" :selected="old('club_id') == $club->id">
                                {{ $club->name }} ({{ $club->nation?->code }})
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:description class="mt-1!">Wird beim Athleten-Wechsel automatisch vorbelegt — bleibt änderbar.</flux:description>
                    <flux:error name="club_id"/>
                </flux:field>

                {{-- Bestzeiten-Anzeige (Jahres- + absolute Bestzeit). Klick auf eine Zeit übernimmt sie
                     als Meldezeit + Bahnlänge (applyTime); NT-Zeiten sind nicht klickbar. --}}
                <div x-show="athleteId && selectedEventId"
                     class="p-3 rounded-lg bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-700 text-sm">
                    <div x-show="loadingTimes" class="text-zinc-400 text-xs">Wird geladen…</div>
                    <div x-show="!loadingTimes">
                        <div class="grid grid-cols-2 gap-6">
                            {{-- Jahresbestzeit --}}
                            <div>
                                <p class="text-xs font-medium text-blue-600 dark:text-blue-400 uppercase tracking-wide">
                                    Jahresbestzeit
                                </p>
                                <p class="text-xs text-zinc-400 mb-1">(Vorjahr bis Wettkampfbeginn)</p>
                                <div class="flex gap-6 items-start">
                                    <div>
                                        <span class="text-xs text-zinc-400">LCM</span>
                                        <p class="font-mono font-semibold"
                                           :class="bestTimes.LCM && bestTimes.LCM.year.formatted !== 'NT' ? 'text-blue-600 dark:text-blue-400 cursor-pointer hover:underline' : 'text-zinc-900 dark:text-zinc-100'"
                                           @click="bestTimes.LCM && applyTime('LCM', bestTimes.LCM.year.formatted)"
                                           x-text="bestTimes.LCM ? bestTimes.LCM.year.formatted : 'NT'"></p>
                                        <p class="text-xs text-zinc-400" x-show="bestTimes.LCM && bestTimes.LCM.year.date"
                                           x-text="bestTimes.LCM ? bestTimes.LCM.year.date : ''"></p>
                                    </div>
                                    <div>
                                        <span class="text-xs text-zinc-400">SCM</span>
                                        <p class="font-mono font-semibold"
                                           :class="bestTimes.SCM && bestTimes.SCM.year.formatted !== 'NT' ? 'text-blue-600 dark:text-blue-400 cursor-pointer hover:underline' : 'text-zinc-900 dark:text-zinc-100'"
                                           @click="bestTimes.SCM && applyTime('SCM', bestTimes.SCM.year.formatted)"
                                           x-text="bestTimes.SCM ? bestTimes.SCM.year.formatted : 'NT'"></p>
                                        <p class="text-xs text-zinc-400" x-show="bestTimes.SCM && bestTimes.SCM.year.date"
                                           x-text="bestTimes.SCM ? bestTimes.SCM.year.date : ''"></p>
                                    </div>
                                </div>
                            </div>
                            {{-- Absolute Bestzeit --}}
                            <div>
                                <p class="text-xs font-medium text-blue-600 dark:text-blue-400 uppercase tracking-wide">
                                    Absolute Bestzeit
                                </p>
                                <p class="text-xs text-zinc-400 mb-1">(alle Wettkämpfe)</p>
                                <div class="flex gap-6 items-start">
                                    <div>
                                        <span class="text-xs text-zinc-400">LCM</span>
                                        <p class="font-mono font-semibold"
                                           :class="bestTimes.LCM && bestTimes.LCM.absolute.formatted !== 'NT' ? 'text-blue-600 dark:text-blue-400 cursor-pointer hover:underline' : 'text-zinc-900 dark:text-zinc-100'"
                                           @click="bestTimes.LCM && applyTime('LCM', bestTimes.LCM.absolute.formatted)"
                                           x-text="bestTimes.LCM ? bestTimes.LCM.absolute.formatted : 'NT'"></p>
                                        <p class="text-xs text-zinc-400" x-show="bestTimes.LCM && bestTimes.LCM.absolute.date"
                                           x-text="bestTimes.LCM ? bestTimes.LCM.absolute.date : ''"></p>
                                    </div>
                                    <div>
                                        <span class="text-xs text-zinc-400">SCM</span>
                                        <p class="font-mono font-semibold"
                                           :class="bestTimes.SCM && bestTimes.SCM.absolute.formatted !== 'NT' ? 'text-blue-600 dark:text-blue-400 cursor-pointer hover:underline' : 'text-zinc-900 dark:text-zinc-100'"
                                           @click="bestTimes.SCM && applyTime('SCM', bestTimes.SCM.absolute.formatted)"
                                           x-text="bestTimes.SCM ? bestTimes.SCM.absolute.formatted : 'NT'"></p>
                                        <p class="text-xs text-zinc-400" x-show="bestTimes.SCM && bestTimes.SCM.absolute.date"
                                           x-text="bestTimes.SCM ? bestTimes.SCM.absolute.date : ''"></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <p class="text-xs text-zinc-400 mt-2">Klick auf eine Zeit übernimmt sie als Meldezeit.</p>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Meldezeit<x-hint content="MM:SS.hh — leer lassen für NT"/></flux:label>
                        <flux:input name="entry_time" type="text" x-model="entryTime"
                                    placeholder="00:00.00" autocomplete="off"
                                    x-init="
                                        const mask = IMask($el.querySelector('input') ?? $el, {
                                            mask: '00:00.00',
                                            lazy: false,
                                            placeholderChar: '0'
                                        });
                                        mask.on('accept', () => { entryTime = mask.value; });
                                        $watch('entryTime', v => { if (mask.value !== v) mask.value = v; });
                                    "/>
                        <flux:error name="entry_time"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Bahnlänge der Meldezeit</flux:label>
                        <flux:select variant="listbox" name="entry_course" x-model="entryCourse" placeholder="–" clearable>
                            <flux:select.option value="LCM" :selected="old('entry_course') === 'LCM'">LCM (50m)</flux:select.option>
                            <flux:select.option value="SCM" :selected="old('entry_course') === 'SCM'">SCM (25m)</flux:select.option>
                        </flux:select>
                        <flux:error name="entry_course"/>
                    </flux:field>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Sport-Klasse</flux:label>
                        <flux:input name="sport_class" value="{{ old('sport_class') }}" placeholder="z.B. S4"
                                    maxlength="15"/>
                        <flux:description class="mt-1!">Nur wenn abweichend vom Athleten</flux:description>
                        <flux:error name="sport_class"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Status</flux:label>
                        <flux:select variant="listbox" name="status" placeholder="Normal" clearable>
                            <flux:select.option value="EXH" :selected="old('status') === 'EXH'">EXH – Außer Konkurrenz</flux:select.option>
                            <flux:select.option value="WDR" :selected="old('status') === 'WDR'">WDR – Zurückgezogen</flux:select.option>
                            <flux:select.option value="SICK" :selected="old('status') === 'SICK'">SICK – Krank</flux:select.option>
                        </flux:select>
                        <flux:error name="status"/>
                    </flux:field>
                </div>

                <div class="flex gap-3 pt-2">
                    <flux:button type="submit" variant="primary">Meldung speichern</flux:button>
                    <flux:button href="{{ route('meets.show', $meet) }}" variant="ghost">Abbrechen</flux:button>
                </div>
            </form>
        </div>
    </div>
@endsection
