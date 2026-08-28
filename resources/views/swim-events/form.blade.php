@extends('layouts.app')

@section('title', isset($event) ? 'Disziplin bearbeiten' : 'Disziplin hinzufügen')

@section('content')
    <div class="max-w-2xl">
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                {{ isset($event) ? 'Disziplin bearbeiten' : 'Disziplin hinzufügen' }}
            </h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $meet->name }}</p>

            <div class="mt-4">
                <flux:button href="{{ route('meets.show', $meet) }}" variant="filled" icon="arrow-left" size="sm">
                    Zurück
                </flux:button>
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
                        <flux:label>Session <span class="text-red-500 dark:text-red-400">*</span></flux:label>
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
                        <flux:label>Runde <span class="text-red-500 dark:text-red-400">*</span></flux:label>
                        <flux:select variant="listbox" name="round" required>
                            @foreach(['TIM' => 'Timed Finals', 'FIN' => 'Finale', 'SEM' => 'Halbfinale', 'PRE' => 'Vorlauf', 'TIMETRIAL' => 'Zeitlauf'] as $val => $label)
                                <flux:select.option
                                    value="{{ $val }}" :selected="old('round', $event->round ?? 'TIM') === $val">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </flux:field>
                </div>

                <div class="grid grid-cols-3 gap-4">
                    <flux:field>
                        <flux:label>Schwimmstil <span class="text-red-500 dark:text-red-400">*</span></flux:label>
                        <flux:select variant="listbox" name="stroke_type_id" required>
                            @foreach($strokeTypes->groupBy('category') as $category => $strokes)
                                <flux:select.group label="{{ ucfirst($category) }}">
                                    @foreach($strokes as $stroke)
                                        <flux:select.option
                                            value="{{ $stroke->id }}" :selected="old('stroke_type_id', $event->stroke_type_id ?? '') == $stroke->id">
                                            {{ $stroke->name_de }}
                                        </flux:select.option>
                                    @endforeach
                                </flux:select.group>
                            @endforeach
                        </flux:select>
                        <flux:error name="stroke_type_id"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Distanz (m) <span class="text-red-500 dark:text-red-400">*</span></flux:label>
                        <flux:input name="distance" type="number" min="1"
                                    value="{{ old('distance', $event->distance ?? '') }}" required/>
                        <flux:error name="distance"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Schwimmer/Staffel <span class="text-red-500 dark:text-red-400">*</span></flux:label>
                        <flux:input name="relay_count" type="number" min="1"
                                    value="{{ old('relay_count', $event->relay_count ?? 1) }}" required/>
                        <flux:description>1 = Einzel</flux:description>
                    </flux:field>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Geschlecht <span class="text-red-500 dark:text-red-400">*</span></flux:label>
                        <flux:select variant="listbox" name="gender" required>
                            <flux:select.option value="A" :selected="old('gender', $event->gender ?? 'A') === 'A'">Offen (alle)
                            </flux:select.option>
                            <flux:select.option value="M" :selected="old('gender', $event->gender ?? '') === 'M'">Herren</flux:select.option>
                            <flux:select.option value="F" :selected="old('gender', $event->gender ?? '') === 'F'">Damen</flux:select.option>
                            <flux:select.option value="X" :selected="old('gender', $event->gender ?? '') === 'X'">Mixed (Staffel)
                            </flux:select.option>
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
                    <flux:select variant="listbox" name="timing" placeholder="Vom Wettkampf übernehmen" clearable>
                        @foreach(['AUTOMATIC' => 'Automatisch', 'SEMIAUTOMATIC' => 'Halbautomatisch', 'MANUAL3' => 'Manuell 3', 'MANUAL1' => 'Manuell 1'] as $val => $label)
                            <flux:select.option
                                value="{{ $val }}" :selected="old('timing', $event->timing ?? '') === $val">{{ $label }}</flux:select.option>
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
