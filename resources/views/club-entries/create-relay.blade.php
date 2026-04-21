@extends('layouts.app')

@section('title', 'Neue Staffelmeldung – ' . $meet->name)

@section('content')
    <div class="max-w-2xl">

        {{-- Header --}}
        <div class="flex items-center gap-3 mb-6">
            <flux:button href="{{ route('club-entries.relay.index', $meet) }}" variant="ghost" icon="arrow-left"
                         size="sm"/>
            <div>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Neue Staffelmeldung</h1>
                <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                    {{ $meet->name }} · {{ $club->display_name }}
                </p>
            </div>
        </div>

        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6"
             x-data="relayEntryForm({
                 relayAthletesUrl: '{{ route('club-entries.relay.relay-athletes', array_merge(['meet' => $meet], auth()->user()->is_admin && request('club_id') ? ['club_id' => request()->integer('club_id')] : [])) }}',
                 meetCourse:       '{{ $meet->course }}',
                 selectedEventId:  '{{ old('swim_event_id', '') }}',
                 entryTime:        '{{ old('entry_time', '') }}',
                 entryCourse:      '{{ old('entry_course', $meet->course) }}',
             })">

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
                    <flux:label>Staffel-Event *</flux:label>
                    <flux:select
                        name="swim_event_id"
                        x-ref="eventSelect"
                        x-model="selectedEventId"
                        @change="onEventChange()"
                        required>
                        <option value="">Bitte wählen…</option>
                        @foreach($events as $event)
                            <option value="{{ $event->id }}"
                                    data-relay-count="{{ $event->relay_count }}"
                                @selected(old('swim_event_id') == $event->id)>
                                {{ $event->event_number ? 'Nr. '.$event->event_number.' – ' : '' }}
                                {{ $event->relay_count }}×{{ $event->distance }}m
                                {{ $event->strokeType?->name_de }}
                                @php
                                    $genderLabel = match($event->gender) {
                                        'M'       => 'Männer',
                                        'F'       => 'Frauen',
                                        'X','MX'  => 'Mixed',
                                        default   => 'Offen',
                                    };
                                @endphp
                                ({{ $genderLabel }})
                            </option>
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

                {{-- Meldezeit + Kurs --}}
                <div class="grid grid-cols-2 gap-4 mb-5 w-full items-start">
                    <flux:field>
                        <flux:label>Meldezeit</flux:label>
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
                        <flux:description class="mt-1">MM:SS.hh — z.B. 04:30.25</flux:description>
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
