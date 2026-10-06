@php use App\Support\TimeParser; @endphp

@extends('layouts.app')

@section('title', $relayResult ? 'Staffelergebnis bearbeiten' : 'Staffelergebnis anlegen – ' . $meet->name)

@section('content')
    @php
        // Startwerte fürs Alpine-x-data (relayResultForm, resources/js/relay-result-form.js). Einzelne Variablen
        // statt Ausdrücken mit Kommas in @json() (CLAUDE.md).
        $savedMembers = $relayResult
            ? $relayResult->members->mapWithKeys(fn ($m) => [$m->position - 1 => (string) ($m->athlete_id ?? '')])->all()
            : [];
        $memberValues = [];
        for ($i = 0; $i < $maxPositions; $i++) {
            $memberValues[] = (string) old('members.' . $i, $savedMembers[$i] ?? '');
        }
        $formConfig = [
            'eventId' => (string) old('swim_event_id', $relayResult->swim_event_id ?? $presetEventId),
            'clubId' => (string) old('club_id', $relayResult->club_id ?? ''),
            'members' => $memberValues,
            'relayCounts' => $relayEvents->mapWithKeys(fn ($e) => [(string) $e->id => (int) $e->relay_count]),
            'entryMembers' => $entryMembers,
            'isEdit' => $relayResult !== null,
        ];
        $swimTimeValue = old('swim_time', $relayResult && $relayResult->swim_time ? TimeParser::display($relayResult->swim_time) : '');
        $genderValue = (string) old('gender', $relayResult->gender ?? '');
        $statusValue = (string) old('status', $relayResult->status ?? '');
        $statusOptions = [
            'DSQ' => 'DSQ – Disqualifiziert',
            'DNS' => 'DNS – Nicht angetreten',
            'DNF' => 'DNF – Nicht beendet',
            'EXH' => 'EXH – Außer Konkurrenz',
            'SICK' => 'SICK – Krank',
            'WDR' => 'WDR – Zurückgezogen',
        ];
    @endphp
    <div class="max-w-4xl">

        {{-- Header --}}
        <div class="mb-6">
            <div class="flex items-center gap-2">
                <flux:button href="{{ $cancelUrl }}" variant="primary" icon="arrow-left" size="sm"
                             title="Zurück" aria-label="Zurück"/>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                    {{ $relayResult ? 'Staffelergebnis bearbeiten' : 'Staffelergebnis anlegen' }}
                </h1>
            </div>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">{{ $meet->name }}</p>
        </div>

        @if(session('success'))
            <div class="mb-4 p-3 bg-green-50 dark:bg-green-950/20 border border-green-200 dark:border-green-800
                        rounded-xl text-sm text-green-700 dark:text-green-400" role="status">
                {{ session('success') }}
            </div>
        @endif

        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 p-6">
            <form method="POST"
                  action="{{ $relayResult ? route('relay-results.update', $relayResult) : route('meets.relay-results.store', $meet) }}"
                  x-data='relayResultForm(@json($formConfig))' class="space-y-4">
                @csrf
                @if($relayResult)
                    @method('PUT')
                @endif

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Staffelbewerb<span class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
                        <flux:select variant="listbox" name="swim_event_id" x-model="eventId" required>
                            @foreach($relayEvents as $event)
                                <flux:select.option value="{{ $event->id }}">
                                    {{ $event->event_number ? 'Nr. ' . $event->event_number . ' – ' : '' }}{{ $event->display_name }}
                                </flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="swim_event_id"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Verein<span class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
                        <flux:select variant="listbox" searchable name="club_id" x-model="clubId" required>
                            @foreach($clubs as $club)
                                <flux:select.option value="{{ $club->id }}">{{ $club->display_name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:description class="mt-1!">Mitglieder werden aus der Staffelmeldung vorbelegt, falls vorhanden.</flux:description>
                        <flux:error name="club_id"/>
                    </flux:field>
                </div>

                <div class="grid grid-cols-3 gap-4">
                    <flux:field>
                        <flux:label>Mannschaft Nr.</flux:label>
                        <flux:input name="relay_number" type="number" min="1"
                                    value="{{ old('relay_number', $relayResult->relay_number ?? '') }}" placeholder="1"/>
                        <flux:error name="relay_number"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Eigener Name</flux:label>
                        <flux:input name="name" maxlength="100" value="{{ old('name', $relayResult->name ?? '') }}"/>
                        <flux:error name="name"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Wertung</flux:label>
                        <flux:select variant="listbox" name="gender" placeholder="Automatisch" clearable>
                            <flux:select.option value="M" :selected="$genderValue === 'M'">Herren</flux:select.option>
                            <flux:select.option value="F" :selected="$genderValue === 'F'">Damen</flux:select.option>
                            <flux:select.option value="X" :selected="$genderValue === 'X'">Mixed</flux:select.option>
                        </flux:select>
                        <flux:description class="mt-1!">Leer = aus den Mitgliedern (nur Damen = Damen, 2 + 2 = Mixed, sonst Herren).</flux:description>
                        <flux:error name="gender"/>
                    </flux:field>
                </div>

                {{-- Mitglieder: so viele Positionen, wie der Bewerb Schwimmer hat --}}
                <div>
                    <div class="text-sm font-medium text-zinc-800 dark:text-white mb-2">Schwimmer</div>
                    <div class="grid grid-cols-2 gap-3">
                        @for($i = 0; $i < $maxPositions; $i++)
                            <div x-show="{{ $i }} < positionCount()">
                                <flux:field>
                                    <flux:label>Position {{ $i + 1 }}</flux:label>
                                    <flux:select variant="listbox" searchable clearable name="members[{{ $i }}]"
                                                 x-model="members[{{ $i }}]" placeholder="Athlet wählen …">
                                        @foreach($athletes as $athlete)
                                            <flux:select.option value="{{ $athlete->id }}">
                                                {{ $athlete->display_name }}
                                                {{ $athlete->sport_classes_display ? '– ' . $athlete->sport_classes_display : '' }}
                                                ({{ $athlete->club?->short_name ?? $athlete->club?->name }})
                                            </flux:select.option>
                                        @endforeach
                                    </flux:select>
                                </flux:field>
                            </div>
                        @endfor
                    </div>
                    <flux:error name="members"/>
                </div>

                <div class="grid grid-cols-3 gap-4">
                    <flux:field>
                        <flux:label>Zeit<x-hint content="MM:SS.hh — leer lassen ohne Zeit (z.B. bei DNS)"/></flux:label>
                        <div x-data='maskedTimeField(@json($swimTimeValue))'>
                            <flux:input name="swim_time" type="text" x-model="value" placeholder="00:00.00" autocomplete="off"/>
                        </div>
                        <flux:error name="swim_time"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Status</flux:label>
                        <flux:select variant="listbox" name="status" placeholder="Gültig" clearable>
                            @foreach($statusOptions as $code => $label)
                                <flux:select.option value="{{ $code }}" :selected="$statusValue === $code">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="status"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Staffelklasse</flux:label>
                        <flux:input name="relay_class" maxlength="10" placeholder="z.B. S14"
                                    value="{{ old('relay_class', $relayResult->relay_class ?? '') }}"/>
                        <flux:description class="mt-1!">Leer = aus den Sportklassen der Schwimmer.</flux:description>
                        <flux:error name="relay_class"/>
                    </flux:field>
                </div>

                <div class="grid grid-cols-3 gap-4">
                    {{-- Platz wird je Wertungsgruppe berechnet und gespeichert — nur Anzeige. --}}
                    <div>
                        <div class="text-sm font-medium text-zinc-800 dark:text-white mb-2">Platz</div>
                        @forelse($placements as $placement)
                            <div class="text-sm text-zinc-700 dark:text-zinc-300">
                                <span class="font-semibold">{{ $placement['place'] ? $placement['place'] . '.' : '–' }}</span>
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $placement['label'] }}</span>
                            </div>
                        @empty
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">Wird beim Speichern aus der Wertung berechnet.</div>
                        @endforelse
                    </div>
                    <flux:field>
                        <flux:label>Punkte</flux:label>
                        <flux:input name="points" type="number" min="0" value="{{ old('points', $relayResult->points ?? '') }}"/>
                        <flux:error name="points"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Kommentar / DSQ-Grund</flux:label>
                        <flux:input name="comment" maxlength="255" value="{{ old('comment', $relayResult->comment ?? '') }}"/>
                        <flux:error name="comment"/>
                    </flux:field>
                </div>

                <div class="flex gap-3 pt-4">
                    <flux:button type="submit" variant="primary">
                        {{ $relayResult ? 'Speichern' : 'Staffelergebnis anlegen' }}
                    </flux:button>
                    @unless($relayResult)
                        {{-- Speichert und öffnet das Formular erneut mit demselben Bewerb. --}}
                        <flux:button type="submit" name="save_next" value="1" variant="filled">Speichern und nächstes</flux:button>
                    @endunless
                    <flux:button href="{{ $cancelUrl }}" variant="ghost">Abbrechen</flux:button>
                </div>
            </form>
        </div>
    </div>
@endsection
