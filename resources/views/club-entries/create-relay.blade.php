@extends('layouts.app')

@section('title', 'Neue Staffelmeldung – ' . $meet->name)

@section('content')
    @php $clubParams = auth()->user()->is_admin && request('club_id') ? ['club_id' => request()->integer('club_id')] : []; @endphp
    <div class="max-w-3xl">

        {{-- Header --}}
        <div class="mb-6">
            <div class="flex items-center gap-2">
                <flux:button href="{{ route('club-entries.relay.index', array_merge(['meet' => $meet], $clubParams)) }}"
                             variant="primary" icon="arrow-left" size="sm" title="Zurück" aria-label="Zurück"/>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Neue Staffelmeldung</h1>
            </div>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                {{ $meet->name }} · {{ $club->display_name }}
            </p>
        </div>

        @php
            // Siehe club-entries/create.blade.php: Konfiguration als EIN zusammenhängender
            // JSON-Wert statt einzelner {{ }}-Ausdrücke im JS-Objektliteral.
            $relayEntryFormConfig = [
                'relayAthletesUrl' => route('club-entries.relay.relay-athletes', array_merge(['meet' => $meet], $clubParams)),
                'relayBestTimeUrl' => route('club-entries.relay.relay-best-time', array_merge(['meet' => $meet], $clubParams)),
                'meetCourse' => $meet->course,
                'events' => $events->pluck('relay_count', 'id'),
                'selectedEventId' => old('swim_event_id', ''),
                'entryTime' => old('entry_time', ''),
                'entryCourse' => old('entry_course', $meet->course),
            ];
        @endphp

        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6"
             x-data='relayEntryForm(@json($relayEntryFormConfig))'>

            <form method="POST" action="{{ route('club-entries.relay.store', $meet) }}"
                  @submit="onSubmit()">
                @csrf
                @if(auth()->user()->is_admin && request('club_id'))
                    <input type="hidden" name="club_id" value="{{ request()->integer('club_id') }}">
                @endif

                @if($errors->any())
                    <div class="mb-5 p-3 bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-800
                                rounded-xl text-sm text-red-700 dark:text-red-400 space-y-1">
                        @foreach($errors->all() as $error)
                            <p class="flex items-start gap-2">
                                <svg class="w-4 h-4 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24"
                                     stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                          d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                                </svg>
                                {{ $error }}
                            </p>
                        @endforeach
                    </div>
                @endif

                {{-- Event-Auswahl --}}
                <flux:field class="mb-5">
                    <flux:label>Staffel-Event<span class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
                    <flux:select
                        variant="listbox"
                        name="swim_event_id"
                        x-model="selectedEventId"
                        @change="onEventChange()"
                        required>
                        @foreach($events as $event)
                            <flux:select.option value="{{ $event->id }}"
                                :selected="old('swim_event_id') == $event->id">
                                {{ $event->event_number ? 'Nr. '.$event->event_number.' – ' : '' }}
                                {{ $event->relay_count }}×{{ $event->distance }}m
                                {{ $event->strokeType?->name_de }}
                                ({{ match($event->gender) {
                                    'M' => 'Männer',
                                    'F' => 'Frauen',
                                    'X', 'MX' => 'Mixed',
                                    default => 'Offen',
                                } }})
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="swim_event_id"/>
                </flux:field>

                {{-- Athleten-Picker --}}
                <div class="mb-5">
                    <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                        Athleten
                        <span class="text-zinc-400 font-normal">
                            (<span x-text="selectedAthletes.length"></span>/<span x-text="relayCount"></span>)
                        </span>
                        <span class="text-zinc-400 font-normal text-xs">– optional</span>
                    </p>
                    <flux:description class="mb-3">
                        Athleten können auch später ergänzt werden. Die Reihenfolge bestimmt die Position (1 = erster
                        Starter).
                    </flux:description>

                    <div x-show="selectedEventId">
                        @include('club-entries._athlete-picker')
                    </div>
                    <p x-show="!selectedEventId"
                       class="text-sm text-zinc-400 dark:text-zinc-500 italic py-2">
                        Bitte zuerst ein Event wählen.
                    </p>
                </div>

                {{-- Meldezeit-Vorschlag: Summe der Einzel-Bestzeiten der ausgewählten Athleten (Jahres- +
                     absolute Summe je Kurs). Klick auf eine Summe übernimmt sie als Meldezeit + Kurs
                     (applyRelayTime); bei unvollständiger Aufstellung Teilsumme + "n von N Zeiten fehlen".
                     Inline (kein Partial): sonst kann die IDE die Alpine-Ausdrücke nicht auflösen. --}}
                <div x-show="(selectedEventId || fixedEventId) && selectedAthletes.length > 0"
                     class="mb-5 p-3 rounded-lg bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-700 text-sm">
                    <div x-show="loadingRelayTimes" class="text-zinc-400 text-xs">Wird geladen…</div>
                    <div x-show="!loadingRelayTimes">
                        <div class="grid grid-cols-2 gap-6">
                            {{-- Jahresbestzeit-Summe --}}
                            <div>
                                <p class="text-xs font-medium text-blue-600 dark:text-blue-400 uppercase tracking-wide">
                                    Vorschlag Jahresbestzeit
                                </p>
                                <p class="text-xs text-zinc-400 mb-1">(Summe, Vorjahr bis Wettkampfbeginn)</p>
                                <div class="flex gap-6 items-start">
                                    <div>
                                        <span class="text-xs text-zinc-400">LCM</span>
                                        <p class="font-mono font-semibold"
                                           :class="relayBestTimes.LCM && relayBestTimes.LCM.year.formatted !== 'NT' ? 'text-blue-600 dark:text-blue-400 cursor-pointer hover:underline' : 'text-zinc-900 dark:text-zinc-100'"
                                           @click="relayBestTimes.LCM && applyRelayTime('LCM', relayBestTimes.LCM.year.formatted)"
                                           x-text="relayBestTimes.LCM ? relayBestTimes.LCM.year.formatted : 'NT'"></p>
                                        <p class="text-xs text-amber-600 dark:text-amber-400"
                                           x-show="relayBestTimes.LCM && relayBestTimes.LCM.year.missing > 0"
                                           x-text="relayBestTimes.LCM ? (relayBestTimes.LCM.year.missing + ' von ' + relayBestTimes.LCM.year.total + ' Zeiten fehlen') : ''"></p>
                                    </div>
                                    <div>
                                        <span class="text-xs text-zinc-400">SCM</span>
                                        <p class="font-mono font-semibold"
                                           :class="relayBestTimes.SCM && relayBestTimes.SCM.year.formatted !== 'NT' ? 'text-blue-600 dark:text-blue-400 cursor-pointer hover:underline' : 'text-zinc-900 dark:text-zinc-100'"
                                           @click="relayBestTimes.SCM && applyRelayTime('SCM', relayBestTimes.SCM.year.formatted)"
                                           x-text="relayBestTimes.SCM ? relayBestTimes.SCM.year.formatted : 'NT'"></p>
                                        <p class="text-xs text-amber-600 dark:text-amber-400"
                                           x-show="relayBestTimes.SCM && relayBestTimes.SCM.year.missing > 0"
                                           x-text="relayBestTimes.SCM ? (relayBestTimes.SCM.year.missing + ' von ' + relayBestTimes.SCM.year.total + ' Zeiten fehlen') : ''"></p>
                                    </div>
                                </div>
                            </div>
                            {{-- Absolute Summe --}}
                            <div>
                                <p class="text-xs font-medium text-blue-600 dark:text-blue-400 uppercase tracking-wide">
                                    Vorschlag absolute Bestzeit
                                </p>
                                <p class="text-xs text-zinc-400 mb-1">(Summe, alle Wettkämpfe)</p>
                                <div class="flex gap-6 items-start">
                                    <div>
                                        <span class="text-xs text-zinc-400">LCM</span>
                                        <p class="font-mono font-semibold"
                                           :class="relayBestTimes.LCM && relayBestTimes.LCM.absolute.formatted !== 'NT' ? 'text-blue-600 dark:text-blue-400 cursor-pointer hover:underline' : 'text-zinc-900 dark:text-zinc-100'"
                                           @click="relayBestTimes.LCM && applyRelayTime('LCM', relayBestTimes.LCM.absolute.formatted)"
                                           x-text="relayBestTimes.LCM ? relayBestTimes.LCM.absolute.formatted : 'NT'"></p>
                                        <p class="text-xs text-amber-600 dark:text-amber-400"
                                           x-show="relayBestTimes.LCM && relayBestTimes.LCM.absolute.missing > 0"
                                           x-text="relayBestTimes.LCM ? (relayBestTimes.LCM.absolute.missing + ' von ' + relayBestTimes.LCM.absolute.total + ' Zeiten fehlen') : ''"></p>
                                    </div>
                                    <div>
                                        <span class="text-xs text-zinc-400">SCM</span>
                                        <p class="font-mono font-semibold"
                                           :class="relayBestTimes.SCM && relayBestTimes.SCM.absolute.formatted !== 'NT' ? 'text-blue-600 dark:text-blue-400 cursor-pointer hover:underline' : 'text-zinc-900 dark:text-zinc-100'"
                                           @click="relayBestTimes.SCM && applyRelayTime('SCM', relayBestTimes.SCM.absolute.formatted)"
                                           x-text="relayBestTimes.SCM ? relayBestTimes.SCM.absolute.formatted : 'NT'"></p>
                                        <p class="text-xs text-amber-600 dark:text-amber-400"
                                           x-show="relayBestTimes.SCM && relayBestTimes.SCM.absolute.missing > 0"
                                           x-text="relayBestTimes.SCM ? (relayBestTimes.SCM.absolute.missing + ' von ' + relayBestTimes.SCM.absolute.total + ' Zeiten fehlen') : ''"></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        {{-- Gemischte Summe: pro Schwimmer in der Aufstellung JBZ/ABZ wählbar,
                             hier die Summe für den aktuell gewählten Kurs. Klick übernimmt sie. --}}
                        <div class="mt-3 pt-3 border-t border-blue-200 dark:border-blue-800 flex items-baseline gap-3 flex-wrap">
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-medium text-blue-600 dark:text-blue-400 uppercase tracking-wide">
                                    Gemischte Summe (Kurs <span x-text="entryCourse"></span>)
                                </p>
                                <p class="text-xs text-zinc-400">Je Schwimmer in der Aufstellung wählbar (JBZ/ABZ)</p>
                            </div>
                            <p class="font-mono font-semibold text-lg"
                               :class="mixedSumFormatted() !== 'NT' ? 'text-blue-600 dark:text-blue-400 cursor-pointer hover:underline' : 'text-zinc-900 dark:text-zinc-100'"
                               @click="applyMixedSum()"
                               x-text="mixedSumFormatted()"></p>
                            <p class="text-xs text-amber-600 dark:text-amber-400"
                               x-show="mixedMissingLabel()" x-text="mixedMissingLabel()"></p>
                        </div>
                        <p class="text-xs text-zinc-400 mt-2">
                            Klick auf eine Summe übernimmt sie als Meldezeit. Bei Lagenstaffeln bestimmt die
                            Startreihenfolge den Stil je Position.
                        </p>
                    </div>
                </div>

                {{-- Meldezeit + Kurs --}}
                <div class="grid grid-cols-2 gap-4 mb-5 w-full items-start">
                    <flux:field>
                        <flux:label>Meldezeit<x-hint content="MM:SS.hh — z.B. 04:30.25"/></flux:label>
                        <flux:input
                            name="entry_time"
                            type="text"
                            x-model="entryTime"
                            placeholder="00:00.00"
                            autocomplete="off"
                            x-ref="entryTimeInput"
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
                        <flux:label>Kurs</flux:label>
                        <flux:select variant="listbox" name="entry_course" x-model="entryCourse">
                            <flux:select.option value="LCM">LCM (50m)</flux:select.option>
                            <flux:select.option value="SCM">SCM (25m)</flux:select.option>
                        </flux:select>
                        <flux:error name="entry_course"/>
                    </flux:field>
                </div>

                {{-- Hinweis relay_class --}}
                <div class="mb-5 p-3 rounded-lg bg-zinc-50 dark:bg-zinc-900/40 border border-zinc-200
                            dark:border-zinc-700 text-xs text-zinc-500 dark:text-zinc-400">
                    Die Staffelklasse (S20, S34, S49 …) wird automatisch aus den Sportklassen der
                    ausgewählten Athleten berechnet.
                </div>

                {{-- Buttons --}}
                <div class="flex gap-3 pt-2">
                    <flux:button type="submit" variant="primary" x-bind:disabled="submitting">
                        <span x-show="!submitting">Staffelmeldung speichern</span>
                        <span x-show="submitting">Wird gespeichert…</span>
                    </flux:button>
                    <flux:button href="{{ route('club-entries.relay.index', $meet) }}" variant="ghost">
                        Abbrechen
                    </flux:button>
                </div>

            </form>
        </div>
    </div>
@endsection
