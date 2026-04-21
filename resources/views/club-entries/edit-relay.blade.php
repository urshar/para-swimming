@php use App\Support\TimeParser; @endphp

@extends('layouts.app')

@section('title', 'Staffelmeldung bearbeiten – ' . $meet->name)

@section('content')
    <div class="max-w-2xl">

        {{-- Header --}}
        <div class="flex items-center gap-3 mb-6">
            <flux:button href="{{ route('club-entries.relay.index', $meet) }}" variant="ghost" icon="arrow-left"
                         size="sm"/>
            <div>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Staffelmeldung bearbeiten</h1>
                <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                    {{ $meet->name }} · {{ $club->display_name }}
                </p>
            </div>
        </div>

        @php
            $event           = $relayEntry->swimEvent;
            $relayCount      = $event->relay_count ?? 4;
            $currentAthletes = $relayEntry->members->map(fn ($m) => [
                'id'         => $m->athlete?->id,
                'name'       => $m->athlete ? $m->athlete->last_name.', '.$m->athlete->first_name : '–',
                'birth_year' => $m->athlete?->birth_date?->format('Y'),
                'classes'    => $m->athlete?->sportClasses->pluck('sport_class')->join(', ') ?? '',
            ])->filter(fn ($a) => $a['id'])->values()->toJson();

            $currentTime = $relayEntry->entry_time
                ? TimeParser::display($relayEntry->entry_time)
                : ($relayEntry->entry_time_code ?? '');
        @endphp

        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6"
             x-data="relayEntryForm({
                 relayAthletesUrl: '{{ route('club-entries.relay.relay-athletes', array_merge(['meet' => $meet], auth()->user()->is_admin && request('club_id') ? ['club_id' => request()->integer('club_id')] : [])) }}',
                 meetCourse:       '{{ $meet->course }}',
                 relayCount:       {{ $relayCount }},
                 fixedEventId:     {{ $event->id }},
                 relayEntryId:     {{ $relayEntry->id }},
                 selectedAthletes: {{ $currentAthletes }},
                 entryTime:        '{{ old('entry_time', $currentTime) }}',
                 entryCourse:      '{{ old('entry_course', $relayEntry->entry_course ?? $meet->course) }}',
             })">

            <form method="POST"
                  action="{{ route('club-entries.relay.update', [$meet, $relayEntry]) }}"
                  @submit="onSubmit()">
                @csrf
                @method('PUT')
                @if(auth()->user()->is_admin && request('club_id'))
                    <input type="hidden" name="club_id" value="{{ request()->integer('club_id') }}">
                @endif

                @if($errors->any())
                    <div class="mb-5 p-3 bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-800
                                rounded-xl text-sm text-red-700 dark:text-red-400">
                        @foreach($errors->all() as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    </div>
                @endif

                {{-- Event-Info (nicht editierbar) --}}
                <div
                    class="mb-5 p-4 rounded-lg bg-zinc-50 dark:bg-zinc-900/40 border border-zinc-200 dark:border-zinc-700">
                    <p class="text-xs font-semibold text-zinc-400 uppercase tracking-wide mb-1">Event</p>
                    <div class="flex items-center gap-3 flex-wrap">
                        <span class="font-medium text-zinc-900 dark:text-zinc-100 text-sm">
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
                        </span>
                        @if($relayEntry->relay_class)
                            <flux:badge color="blue" class="font-mono">{{ $relayEntry->relay_class }}</flux:badge>
                        @else
                            <flux:badge color="amber">Klasse wird berechnet</flux:badge>
                        @endif
                    </div>
                    <p class="text-xs text-zinc-400 mt-1">
                        Die Staffelklasse wird nach dem Speichern neu berechnet.
                    </p>
                </div>

                {{-- Athleten-Picker --}}
                <div class="mb-5">
                    <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                        Athleten
                        <span class="text-zinc-400 font-normal">
                            (<span x-text="selectedAthletes.length"></span>/{{ $relayCount }})
                        </span>
                    </p>

                    @include('club-entries._athlete-picker')

                    <flux:error name="athlete_ids"/>
                </div>

                {{-- Meldezeit + Kurs --}}
                <div class="grid grid-cols-2 gap-4 mb-6 w-full items-start">
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

                {{-- Buttons --}}
                <div class="flex gap-3 pt-2">
                    <flux:button type="submit" variant="primary" x-bind:disabled="submitting">
                        <span x-show="!submitting">Speichern</span>
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
