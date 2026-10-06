@extends('layouts.app')

@section('title', isset($event) ? 'Disziplin bearbeiten' : 'Disziplin hinzufügen')

@section('content')
    @php
        // Erlaubte Standard-Streckenlängen. Bereits gespeicherte Werte außerhalb dieser
        // Liste (z.B. abweichende Freiwasser-Distanzen) bleiben als zusätzliche Option
        // erhalten, statt beim Öffnen des Formulars stillschweigend verworfen zu werden.
        $distanceOptions = [25, 50, 75, 100, 150, 200, 400, 800, 1500];
        $currentDistance = old('distance', $event->distance ?? '');
        if ($currentDistance !== '' && ! in_array((int) $currentDistance, $distanceOptions, true)) {
            $distanceOptions[] = (int) $currentDistance;
            sort($distanceOptions);
        }

        // Wertungsgruppen fürs Alpine-x-data (scoringGroupsEditor, resources/js/scoring-groups-editor.js).
        $groupRows = old('scoring_groups', isset($event)
            ? $event->scoringGroups->map(fn ($g) => [
                'name' => $g->name,
                'gender' => $g->gender,
                'sport_classes' => $g->sport_classes ?? '',
                'age_min' => $g->age_min ?? '',
                'age_max' => $g->age_max ?? '',
                'title' => $g->title ?? '',
                'lenex_agegroup_id' => $g->lenex_agegroup_id ?? '',
            ])->values()->all()
            : []);
        $groupConfig = ['groups' => array_values($groupRows)];
        $inputClass = 'w-full rounded-lg border border-zinc-200 dark:border-zinc-600 bg-white dark:bg-zinc-900 px-2.5 py-1.5 text-sm text-zinc-900 dark:text-zinc-100';
    @endphp
    <div class="max-w-4xl">
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                {{ isset($event) ? 'Disziplin bearbeiten' : 'Disziplin hinzufügen' }}
            </h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $meet->name }}</p>

            @if(session('success'))
                <div class="mt-4 p-3 bg-green-50 dark:bg-green-950/20 border border-green-200 dark:border-green-800
                            rounded-xl text-sm text-green-700 dark:text-green-400" role="status">
                    {{ session('success') }}
                </div>
            @endif

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
                        <flux:label>Session<span class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
                        <flux:input name="session_number" type="number" min="1"
                                    value="{{ old('session_number', $event->session_number ?? 1) }}" required/>
                        <flux:error name="session_number"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Event-Nr.</flux:label>
                        <flux:input name="event_number" type="number" min="1"
                                    value="{{ old('event_number', $event->event_number ?? $nextEventNumber ?? '') }}"/>
                        <flux:error name="event_number"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Runde<span class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
                        <flux:select variant="listbox" name="round" required>
                            @foreach(['TIM' => 'Timed Finals', 'FIN' => 'Finale', 'SEM' => 'Halbfinale', 'PRE' => 'Vorlauf', 'TIMETRIAL' => 'Zeitlauf'] as $val => $label)
                                <flux:select.option
                                    value="{{ $val }}" :selected="old('round', $event->round ?? 'TIM') === $val">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </flux:field>
                </div>

                <div class="grid grid-cols-4 gap-4">
                    <flux:field class="col-span-2">
                        <flux:label>Schwimmstil<span class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
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
                        <flux:label>Distanz (m)<span class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
                        <flux:select variant="listbox" name="distance" required>
                            @foreach($distanceOptions as $distanceOption)
                                <flux:select.option value="{{ $distanceOption }}"
                                    :selected="(string) $currentDistance === (string) $distanceOption">
                                    {{ $distanceOption }} m
                                </flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="distance"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Schwimmer/Staffel<span class="text-red-500 dark:text-red-400 ms-1">*</span><x-hint content="1 = Einzel"/></flux:label>
                        <flux:input name="relay_count" type="number" min="1"
                                    value="{{ old('relay_count', $event->relay_count ?? 1) }}" required/>
                    </flux:field>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Geschlecht<span class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
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
                        <flux:label>Sport-Klassen<x-hint content="Leerzeichen-getrennt. Mit Wertungsgruppen wird das Feld beim Speichern aus deren Klassen gesetzt."/></flux:label>
                        <flux:input name="sport_classes" value="{{ old('sport_classes', $event->sport_classes ?? '') }}"
                                    placeholder="z.B. S1 S2 S3"/>
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

            {{-- Wertungsgruppen (LENEX AGEGROUPs): welche Ergebnisse gemeinsam gewertet werden. Native Felder statt
                 Flux, weil die Zeilen per Alpine-x-for entstehen. --}}
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 mt-6"
                 x-data='scoringGroupsEditor(@json($groupConfig))'>
                <div class="flex items-center justify-between mb-1">
                    <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">Wertungsgruppen</h2>
                    <flux:button type="button" size="sm" variant="filled" icon="plus" class="text-blue-500!" @click="addGroup()">
                        Gruppe hinzufügen
                    </flux:button>
                </div>
                <p class="text-xs text-zinc-500 dark:text-zinc-400 mb-4">
                    Ergebnisse werden in jeder Gruppe gewertet, zu der Geschlecht, Klasse und Jahrgangsalter passen
                    (bei Staffeln Wertung und Staffelklasse). Klassen als Nummern, z.&nbsp;B. "1,2,3,4,5,6,7,8"; leer = alle.
                    Ohne Gruppen wird je Geschlecht und Sportklasse gewertet.
                </p>
                <template x-if="groups.length === 0">
                    <p class="text-sm text-zinc-400 dark:text-zinc-500 italic">Keine Wertungsgruppen angelegt.</p>
                </template>
                <div class="space-y-2">
                    <template x-for="group in groups">
                        <div class="grid grid-cols-2 md:grid-cols-12 gap-2 items-end pb-3 md:pb-0 border-b md:border-0 border-zinc-200 dark:border-zinc-700">
                            <input type="hidden" :name="'scoring_groups[' + groups.indexOf(group) + '][lenex_agegroup_id]'" x-model="group.lenex_agegroup_id">
                            <label class="col-span-2 md:col-span-3 text-xs text-zinc-500">Name
                                <input type="text" maxlength="100" required class="{{ $inputClass }}" placeholder="ÖSTM: S01 - S08"
                                       :name="'scoring_groups[' + groups.indexOf(group) + '][name]'" x-model="group.name">
                            </label>
                            <label class="md:col-span-2 text-xs text-zinc-500">Geschlecht
                                <select class="{{ $inputClass }}" :name="'scoring_groups[' + groups.indexOf(group) + '][gender]'" x-model="group.gender">
                                    <option value="A">Alle</option>
                                    <option value="M">Herren</option>
                                    <option value="F">Damen</option>
                                    <option value="X">Mixed</option>
                                </select>
                            </label>
                            <label class="md:col-span-2 text-xs text-zinc-500">Klassen
                                <input type="text" maxlength="100" class="{{ $inputClass }}" placeholder="1,2,3"
                                       :name="'scoring_groups[' + groups.indexOf(group) + '][sport_classes]'" x-model="group.sport_classes">
                            </label>
                            <label class="md:col-span-1 text-xs text-zinc-500">Alter ab
                                <input type="number" min="0" max="99" class="{{ $inputClass }}"
                                       :name="'scoring_groups[' + groups.indexOf(group) + '][age_min]'" x-model="group.age_min">
                            </label>
                            <label class="md:col-span-1 text-xs text-zinc-500">bis
                                <input type="number" min="0" max="99" class="{{ $inputClass }}"
                                       :name="'scoring_groups[' + groups.indexOf(group) + '][age_max]'" x-model="group.age_max">
                            </label>
                            <label class="md:col-span-2 text-xs text-zinc-500">Titel
                                <select class="{{ $inputClass }}" :name="'scoring_groups[' + groups.indexOf(group) + '][title]'" x-model="group.title">
                                    <option value="">ohne</option>
                                    <option value="OSTM">ÖSTM</option>
                                    <option value="OM">ÖM</option>
                                </select>
                            </label>
                            <div class="md:col-span-1 text-right">
                                <flux:button type="button" size="sm" variant="ghost" icon="trash" class="text-red-500!"
                                             title="Gruppe entfernen" aria-label="Gruppe entfernen"
                                             @click="removeGroup(groups.indexOf(group))"/>
                            </div>
                        </div>
                    </template>
                </div>
                <flux:error name="scoring_groups"/>
            </div>

            <div class="flex gap-3 mt-6">
                <flux:button type="submit" variant="primary">
                    {{ isset($event) ? 'Speichern' : 'Disziplin anlegen' }}
                </flux:button>
                <flux:button href="{{ route('meets.show', $meet) }}" variant="ghost">Abbrechen</flux:button>
            </div>
        </form>

        {{-- Eigenes Formular (nicht im Bewerbsformular verschachtelt): gespeicherte Gruppen auf andere Bewerbe übernehmen. --}}
        @if(isset($event) && $event->scoringGroups->isNotEmpty())
            <form method="POST" action="{{ route('events.scoring-groups.copy', $event) }}" class="mt-4"
                  x-data="{ submit() { if (confirm('Die gespeicherten Wertungsgruppen auf alle anderen Bewerbe dieser Veranstaltung mit gleicher Klassenkategorie übernehmen? Deren Gruppen werden ersetzt.')) this.$el.submit() } }"
                  @submit.prevent="submit()">
                @csrf
                <flux:button type="submit" size="sm" variant="filled" icon="document-duplicate" class="text-blue-500!">
                    Wertungsgruppen auf andere Bewerbe übernehmen
                </flux:button>
            </form>
        @endif
    </div>
@endsection
