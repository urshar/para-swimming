@extends('layouts.app')

@section('title', 'Meldung anlegen – ' . $meet->name)

@section('content')
    <div class="max-w-2xl">
        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 p-6">
            <form method="POST" action="{{ route('meets.entries.store', $meet) }}" class="space-y-4">
                @csrf

                <flux:field>
                    <flux:label>Disziplin</flux:label>
                    <flux:select name="swim_event_id" required>
                        <option value="">Disziplin wählen…</option>
                        @foreach($swimEvents->groupBy('session_number') as $session => $events)
                            <optgroup label="Session {{ $session }}">
                                @foreach($events as $event)
                                    <option value="{{ $event->id }}" @selected(old('swim_event_id') == $event->id)>
                                        {{ $event->display_name }}
                                        {{ $event->gender !== 'A' ? '(' . $event->gender . ')' : '' }}
                                        {{ $event->sport_classes ? '– ' . $event->sport_classes : '' }}
                                    </option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </flux:select>
                    <flux:error name="swim_event_id"/>
                </flux:field>

                <flux:field>
                    <flux:label>Athlet</flux:label>
                    <flux:select name="athlete_id" required>
                        <option value="">Athlet wählen…</option>
                        @foreach($athletes as $athlete)
                            <option value="{{ $athlete->id }}" @selected(old('athlete_id') == $athlete->id)>
                                {{ $athlete->display_name }}
                                {{ $athlete->sport_classes_display ? '– ' . $athlete->sport_classes_display : '' }}
                                ({{ $athlete->nation?->code }})
                            </option>
                        @endforeach
                    </flux:select>
                    <flux:error name="athlete_id"/>
                </flux:field>

                <flux:field>
                    <flux:label>Meldender Club</flux:label>
                    <flux:select name="club_id" required>
                        <option value="">Club wählen…</option>
                        @foreach($clubs as $club)
                            <option value="{{ $club->id }}" @selected(old('club_id') == $club->id)>
                                {{ $club->name }} ({{ $club->nation?->code }})
                            </option>
                        @endforeach
                    </flux:select>
                    <flux:error name="club_id"/>
                </flux:field>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Meldezeit (Hundertstelsekunden)</flux:label>
                        <flux:input name="entry_time" type="number" min="0" value="{{ old('entry_time') }}"
                                    placeholder="z.B. 6245 für 1:02.45"/>
                        <flux:description>Leer lassen für NT</flux:description>
                        <flux:error name="entry_time"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Bahnlänge der Meldezeit</flux:label>
                        <flux:select name="entry_course">
                            <option value="">–</option>
                            <option value="LCM" @selected(old('entry_course') === 'LCM')>LCM (50m)</option>
                            <option value="SCM" @selected(old('entry_course') === 'SCM')>SCM (25m)</option>
                        </flux:select>
                        <flux:error name="entry_course"/>
                    </flux:field>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Sport-Klasse</flux:label>
                        <flux:input name="sport_class" value="{{ old('sport_class') }}" placeholder="z.B. S4"
                                    maxlength="15"/>
                        <flux:description>Nur wenn abweichend vom Athleten</flux:description>
                        <flux:error name="sport_class"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Status</flux:label>
                        <flux:select name="status">
                            <option value="">Normal</option>
                            <option value="EXH" @selected(old('status') === 'EXH')>EXH – Ausstellungsstart</option>
                            <option value="WDR" @selected(old('status') === 'WDR')>WDR – Zurückgezogen</option>
                            <option value="SICK" @selected(old('status') === 'SICK')>SICK – Krank</option>
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
