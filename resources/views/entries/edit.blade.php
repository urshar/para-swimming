@extends('layouts.app')

@section('title', 'Meldung bearbeiten')

@section('content')
    <div class="max-w-2xl">

        {{-- Header --}}
        <div class="mb-6">
            <div class="flex items-center gap-2">
                <flux:button href="{{ url()->previous() }}" variant="primary" icon="arrow-left" size="sm"
                             title="Zurück" aria-label="Zurück"/>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Meldung bearbeiten</h1>
            </div>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">{{ $entry->meet?->name }}</p>
        </div>

        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 p-6">

            {{-- Read-only Info --}}
            <div class="grid grid-cols-2 gap-4 mb-6 pb-6 border-b border-zinc-200 dark:border-zinc-800">
                <div>
                    <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide mb-1">
                        Athlet
                    </div>
                    <div
                        class="text-sm font-medium text-zinc-900 dark:text-white">{{ $entry->athlete?->full_name }}</div>
                </div>
                <div>
                    <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide mb-1">
                        Disziplin
                    </div>
                    <div
                        class="text-sm font-medium text-zinc-900 dark:text-white">{{ $entry->swimEvent?->display_name }}</div>
                </div>
            </div>

            @php
                $entryTimeValue = old('entry_time', $entry->formatted_entry_time !== 'NT' ? $entry->formatted_entry_time : '');
                $entryCourseValue = old('entry_course', $entry->entry_course);

                // Athlet + Disziplin sind hier fix (Meldung bearbeiten) — das Bestzeiten-Panel lädt
                // sie beim Init und zeigt Jahres-/absolute Bestzeit; Klick übernimmt als Meldezeit.
                $entryBestTimesConfig = [
                    'bestTimesUrl' => route('meets.entries.best-times', $entry->meet),
                    'athleteId' => (string) $entry->athlete_id,
                    'eventId' => (string) $entry->swim_event_id,
                    'entryTime' => $entryTimeValue,
                    'entryCourse' => $entryCourseValue ?? '',
                ];
            @endphp
            <form method="POST" action="{{ route('entries.update', $entry) }}" class="space-y-4"
                  x-data='entryBestTimes(@json($entryBestTimesConfig))'>
                @csrf
                @method('PUT')

                <flux:field>
                    <flux:label>Meldender Club<span class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
                    <flux:select variant="listbox" searchable name="club_id" required>
                        @foreach($clubs as $club)
                            <flux:select.option value="{{ $club->id }}" :selected="old('club_id', $entry->club_id) == $club->id">
                                {{ $club->name }} ({{ $club->nation?->code }})
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="club_id"/>
                </flux:field>

                {{-- Bestzeiten-Anzeige (Jahres- + absolute Bestzeit) für Athlet + Disziplin dieser
                     Meldung. Klick auf eine Zeit übernimmt sie als Meldezeit + Bahnlänge. --}}
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
                        <flux:error name="entry_time"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Bahnlänge der Meldezeit</flux:label>
                        <flux:select variant="listbox" name="entry_course" x-model="entryCourse" placeholder="–" clearable>
                            <flux:select.option value="LCM" :selected="old('entry_course', $entry->entry_course) === 'LCM'">LCM
                                (50m)
                            </flux:select.option>
                            <flux:select.option value="SCM" :selected="old('entry_course', $entry->entry_course) === 'SCM'">SCM
                                (25m)
                            </flux:select.option>
                        </flux:select>
                        <flux:error name="entry_course"/>
                    </flux:field>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Sport-Klasse</flux:label>
                        <flux:input name="sport_class" value="{{ old('sport_class', $entry->sport_class) }}"
                                    maxlength="15"/>
                        <flux:error name="sport_class"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Status</flux:label>
                        <flux:select variant="listbox" name="status">
                            <flux:select.option value="" :selected="!old('status', $entry->status)">Normal</flux:select.option>
                            <flux:select.option value="EXH" :selected="old('status', $entry->status) === 'EXH'">EXH –
                                Außer Konkurrenz
                            </flux:select.option>
                            <flux:select.option value="WDR" :selected="old('status', $entry->status) === 'WDR'">WDR – Zurückgezogen
                            </flux:select.option>
                            <flux:select.option value="SICK" :selected="old('status', $entry->status) === 'SICK'">SICK – Krank
                            </flux:select.option>
                        </flux:select>
                        <flux:error name="status"/>
                    </flux:field>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Lauf</flux:label>
                        <flux:input name="heat" type="number" min="1" value="{{ old('heat', $entry->heat) }}"/>
                        <flux:error name="heat"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Bahn</flux:label>
                        <flux:input name="lane" type="number" min="0" value="{{ old('lane', $entry->lane) }}"/>
                        <flux:error name="lane"/>
                    </flux:field>
                </div>

                <div class="flex gap-3 pt-2">
                    <flux:button type="submit" variant="primary">Speichern</flux:button>
                    <flux:button href="{{ url()->previous() }}" variant="ghost">Abbrechen</flux:button>
                </div>
            </form>
        </div>
    </div>
@endsection
