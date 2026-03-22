@extends('layouts.app')

@section('title', isset($event) ? 'Disziplin bearbeiten' : 'Disziplin hinzufügen')

@section('content')
    <div class="max-w-2xl">
        <div class="flex items-center gap-3 mb-6">
            <flux:button href="{{ route('meets.show', $meet) }}" variant="ghost" icon="arrow-left" size="sm"/>
            <div>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                    {{ isset($event) ? 'Disziplin bearbeiten' : 'Disziplin hinzufügen' }}
                </h1>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $meet->name }}</p>
            </div>
        </div>

        <form method="POST"
              action="{{ isset($event) ? route('events.update', $event) : route('meets.events.store', $meet) }}">
            @csrf
            @if(isset($event))
                @method('PUT')
            @endif

            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4">

                <div class="grid grid-cols-3 gap-4">
                    <flux:field>
                        <flux:label>Session *</flux:label>
                        <flux:input name="session_number" type="number" min="1"
                                    value="{{ old('session_number', $event->session_number ?? 1) }}" required/>
                        <flux:error name="session_number"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Event-Nr.</flux:label>
                        <flux:input name="event_number" type="number" min="1"
                                    value="{{ old('event_number', $event->event_number ?? '') }}"/>
                        <flux:error name="event_number"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Runde *</flux:label>
                        <flux:select name="round" required>
                            @foreach(['TIM' => 'Timed Finals', 'FIN' => 'Finale', 'SEM' => 'Halbfinale', 'PRE' => 'Vorlauf', 'TIMETRIAL' => 'Zeitlauf'] as $val => $label)
                                <option
                                    value="{{ $val }}" @selected(old('round', $event->round ?? 'TIM') === $val)>{{ $label }}</option>
                            @endforeach
                        </flux:select>
                    </flux:field>
                </div>

                <div class="grid grid-cols-3 gap-4">
                    <flux:field>
                        <flux:label>Schwimmstil *</flux:label>
                        <flux:select name="stroke_type_id" required>
                            <option value="">Wählen…</option>
                            @foreach($strokeTypes->groupBy('category') as $category => $strokes)
                                <optgroup label="{{ ucfirst($category) }}">
                                    @foreach($strokes as $stroke)
                                        <option
                                            value="{{ $stroke->id }}" @selected(old('stroke_type_id', $event->stroke_type_id ?? '') == $stroke->id)>
                                            {{ $stroke->name_de }}
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </flux:select>
                        <flux:error name="stroke_type_id"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Distanz (m) *</flux:label>
                        <flux:input name="distance" type="number" min="1"
                                    value="{{ old('distance', $event->distance ?? '') }}" required/>
                        <flux:error name="distance"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Schwimmer/Staffel *</flux:label>
                        <flux:input name="relay_count" type="number" min="1"
                                    value="{{ old('relay_count', $event->relay_count ?? 1) }}" required/>
                        <flux:description>1 = Einzel</flux:description>
                    </flux:field>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Geschlecht *</flux:label>
                        <flux:select name="gender" required>
                            <option value="A" @selected(old('gender', $event->gender ?? 'A') === 'A')>Offen (alle)
                            </option>
                            <option value="M" @selected(old('gender', $event->gender ?? '') === 'M')>Herren</option>
                            <option value="F" @selected(old('gender', $event->gender ?? '') === 'F')>Damen</option>
                            <option value="X" @selected(old('gender', $event->gender ?? '') === 'X')>Mixed (Staffel)
                            </option>
                        </flux:select>
                    </flux:field>
                    <flux:field>
                        <flux:label>Sport-Klassen</flux:label>
                        <flux:input name="sport_classes" value="{{ old('sport_classes', $event->sport_classes ?? '') }}"
                                    placeholder="z.B. S1 S2 S3"/>
                        <flux:description>Leerzeichen-getrennt</flux:description>
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>Zeitnahme</flux:label>
                    <flux:select name="timing">
                        <option value="">Vom Wettkampf übernehmen</option>
                        @foreach(['AUTOMATIC' => 'Automatisch', 'SEMIAUTOMATIC' => 'Halbautomatisch', 'MANUAL3' => 'Manuell 3', 'MANUAL1' => 'Manuell 1'] as $val => $label)
                            <option
                                value="{{ $val }}" @selected(old('timing', $event->timing ?? '') === $val)>{{ $label }}</option>
                        @endforeach
                    </flux:select>
                </flux:field>

            </div>

            <div class="flex gap-3 mt-6">
                <flux:button type="submit" variant="primary">
                    {{ isset($event) ? 'Speichern' : 'Disziplin anlegen' }}
                </flux:button>
                <flux:button href="{{ route('meets.show', $meet) }}" variant="ghost">Abbrechen</flux:button>
            </div>
        </form>
    </div>
@endsection
