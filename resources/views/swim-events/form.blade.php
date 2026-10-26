@php use App\Models\Club; use App\Models\ScoringGroup; @endphp

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
                'regional_associations' => $g->regional_associations ?? '',
                'regional_mode' => $g->regional_mode ?? '',
                'levels' => $g->levels ?? '',
                'title' => $g->title ?? '',
                'lenex_agegroup_id' => $g->lenex_agegroup_id ?? '',
            ])->values()->all()
            : []);
        // Landesverbände und Level als Listen für die Flux-Checkbox-Gruppen; Modus beim ersten Verband "nur diese".
        $groupRows = array_map(fn (array $row): array => [
            ...$row,
            'regional_associations' => ScoringGroup::parseCodes($row['regional_associations'] ?? ''),
            'regional_mode' => ($row['regional_mode'] ?? '') ?: ScoringGroup::REGION_ONLY,
            'levels' => ScoringGroup::parseLevels($row['levels'] ?? ''),
            'title' => ($row['title'] ?? '') ?: 'NONE',
        ], array_values($groupRows));
        $groupConfig = ['groups' => $groupRows];
        // Level gibt es nur bei Einzelbewerben (eine Staffel hat kein Level).
        $isRelay = (int) old('relay_count', $event->relay_count ?? 1) > 1;
        // Rahmenbewerb: Checkbox sendet "0" = nicht gewertet.
        $unscored = (string) old('is_scored', isset($event) && ! $event->is_scored ? '0' : '1') === '0';
        $resultCount = isset($event) ? $event->results()->count() + $event->relayResults()->count() : 0;
        $inputClass = 'w-full rounded-lg border border-zinc-200 dark:border-zinc-600 bg-white dark:bg-zinc-900 px-2.5 py-1.5 text-sm text-zinc-900 dark:text-zinc-100';
        // Knopf der Flux-Selects in den Wertungsgruppen wie $inputClass (Höhe, Farben, Rundung); "!" nötig, weil
        // flux:select.button eigene Klassen (h-10, dark:bg-white/10 ...) mitbringt.
        $selectButtonClass = 'h-[34px]! rounded-lg! px-2.5! shadow-none! bg-white! dark:bg-zinc-900! border-zinc-200! dark:border-zinc-600! text-zinc-900 dark:text-zinc-100';
    @endphp
    <div class="max-w-4xl">
        <div class="mb-6">
            <div class="flex items-center gap-2">
                <flux:button href="{{ route('meets.show', $meet) }}" variant="primary" icon="arrow-left" size="sm"
                             title="Zurück" aria-label="Zurück"/>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                    {{ isset($event) ? 'Disziplin bearbeiten' : 'Disziplin hinzufügen' }}
                </h1>
            </div>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">{{ $meet->name }}</p>

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

                {{-- Rahmenbewerb: Hidden-Feld liefert "1", wenn das Häkchen fehlt (unchecked Checkboxen werden nicht gesendet). --}}
                <div>
                    <input type="hidden" name="is_scored" value="1">
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" name="is_scored" value="0" @checked($unscored)
                               class="mt-1 rounded border-zinc-300 dark:border-zinc-600 dark:bg-zinc-700">
                        <span>
                            <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Rahmenbewerb – nicht gewertet</span>
                            <span class="block text-xs text-zinc-500 dark:text-zinc-400">
                                Z. B. Schnupperbewerb für nicht klassifizierte Schwimmer. Der Bewerb bleibt Teil der
                                Veranstaltung und des LENEX-Exports; Ergebnisse und Meldungen werden nicht importiert.
                            </span>
                        </span>
                    </label>
                    @if($resultCount > 0 && ! $unscored)
                        <p class="text-xs text-amber-700 dark:text-amber-400 mt-2">
                            Achtung: Dieser Bewerb hat {{ $resultCount }} {{ $resultCount === 1 ? 'Ergebnis' : 'Ergebnisse' }}.
                            Wird er als nicht gewertet markiert, werden diese beim Speichern gelöscht.
                        </p>
                    @endif
                    @error('confirm_delete_results')
                        <label class="flex items-start gap-3 mt-3 p-3 rounded-lg border border-red-300 dark:border-red-800 bg-red-50 dark:bg-red-950/20 cursor-pointer">
                            <input type="checkbox" name="confirm_delete_results" value="1"
                                   class="mt-1 rounded border-zinc-300 dark:border-zinc-600 dark:bg-zinc-700">
                            <span class="text-sm text-red-700 dark:text-red-400">{{ $message }}</span>
                        </label>
                    @enderror
                </div>

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
                    Landesverband: Verein im Ergebnis, z.&nbsp;B. LM "nur WBSV", Gäste "alle außer WBSV"; Level: Einstufung
                    des Athleten zum Wettkampf. Ohne Auswahl keine Einschränkung.
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
                            {{-- Flux-Selects binden nur an Alpine, abgeschickt wird über die versteckten Felder. --}}
                            <div class="md:col-span-2 text-xs text-zinc-500">
                                <span aria-hidden="true">Geschlecht</span>
                                <input type="hidden" :name="'scoring_groups[' + groups.indexOf(group) + '][gender]'" :value="group.gender">
                                <flux:select variant="listbox" x-model="group.gender" aria-label="Geschlecht">
                                    <x-slot name="trigger"><flux:select.button class="{{ $selectButtonClass }}"/></x-slot>
                                    <flux:select.option value="A">Alle</flux:select.option>
                                    <flux:select.option value="M">Herren</flux:select.option>
                                    <flux:select.option value="F">Damen</flux:select.option>
                                    <flux:select.option value="X">Mixed</flux:select.option>
                                </flux:select>
                            </div>
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
                            {{-- "ohne" als Sentinel NONE: eine leere Flux-Option kommt nicht zuverlässig an (CLAUDE.md). --}}
                            <div class="md:col-span-2 text-xs text-zinc-500">
                                <span aria-hidden="true">Titel</span>
                                <input type="hidden" :name="'scoring_groups[' + groups.indexOf(group) + '][title]'" :value="group.title === 'NONE' ? '' : group.title">
                                <flux:select variant="listbox" x-model="group.title" aria-label="Titel">
                                    <x-slot name="trigger"><flux:select.button class="{{ $selectButtonClass }}"/></x-slot>
                                    <flux:select.option value="NONE">ohne</flux:select.option>
                                    <flux:select.option value="OSTM">ÖSTM</flux:select.option>
                                    <flux:select.option value="OM">ÖM</flux:select.option>
                                </flux:select>
                            </div>
                            <div class="md:col-span-1 text-right">
                                <flux:button type="button" size="sm" variant="ghost" icon="trash" class="text-red-500!"
                                             title="Gruppe entfernen" aria-label="Gruppe entfernen"
                                             @click="removeGroup(groups.indexOf(group))"/>
                            </div>
                            {{-- Einschränkung nach Landesverband des Ergebnisvereins und Athleten-Level (z. B. LM: nur WBSV,
                                 Gäste: alle außer WBSV). Die Flux-Felder binden nur an Alpine; abgeschickt wird über die
                                 versteckten Felder (Listen kommagetrennt, Modus nur mit Verbänden). --}}
                            <div class="col-span-2 md:col-span-12 flex flex-wrap items-center gap-x-6 gap-y-2 md:pb-2">
                                <input type="hidden" :name="'scoring_groups[' + groups.indexOf(group) + '][regional_associations]'" :value="group.regional_associations.join(',')">
                                <input type="hidden" :name="'scoring_groups[' + groups.indexOf(group) + '][regional_mode]'" :value="group.regional_associations.length > 0 ? group.regional_mode : ''">
                                <input type="hidden" :name="'scoring_groups[' + groups.indexOf(group) + '][levels]'" :value="group.levels.join(',')">
                                <div class="flex flex-wrap items-center gap-2">
                                    <flux:text size="sm">Landesverband</flux:text>
                                    <flux:checkbox.group variant="pills" x-model="group.regional_associations" aria-label="Landesverbände">
                                        @foreach(Club::REGIONAL_ASSOCIATION_STATES as $code => $state)
                                            <flux:checkbox class="rounded-md!" value="{{ $code }}" label="{{ $code }}" title="{{ $state }}"/>
                                        @endforeach
                                    </flux:checkbox.group>
                                </div>
                                @if(! $isRelay && $levelOptions !== [])
                                    <div class="flex flex-wrap items-center gap-2">
                                        <flux:text size="sm">Level</flux:text>
                                        <flux:checkbox.group variant="pills" x-model="group.levels" aria-label="Level">
                                            @foreach($levelOptions as $level)
                                                <flux:checkbox class="rounded-md!" value="{{ $level }}" label="{{ $level }}"/>
                                            @endforeach
                                        </flux:checkbox.group>
                                    </div>
                                @endif
                                {{-- Modus nach dem Level, damit er beim Umbrechen allein in die nächste Zeile rutscht. --}}
                                <div class="w-44" x-show="group.regional_associations.length > 0">
                                    <flux:select variant="listbox" x-model="group.regional_mode" aria-label="Modus Landesverband">
                                        <x-slot name="trigger"><flux:select.button class="{{ $selectButtonClass }}"/></x-slot>
                                        @foreach(ScoringGroup::REGION_MODES as $mode => $modeLabel)
                                            <flux:select.option value="{{ $mode }}">{{ $modeLabel }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                </div>
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
                {{-- Gehört zum eigenen Formular unten (form-Attribut), steht aber in der Knopfzeile. --}}
                @if(isset($event) && $event->scoringGroups->isNotEmpty())
                    <flux:button type="submit" variant="primary" color="emerald" icon="document-duplicate" form="scoring-groups-copy">
                        Wertungsgruppen auf andere Bewerbe übernehmen
                    </flux:button>
                @endif
                <flux:button href="{{ route('meets.show', $meet) }}" variant="ghost">Abbrechen</flux:button>
            </div>
        </form>

        {{-- Eigenes Formular (nicht im Bewerbsformular verschachtelt): gespeicherte Gruppen auf andere Bewerbe übernehmen;
             abgeschickt über den Knopf in der Knopfzeile des Bewerbsformulars. --}}
        @if(isset($event) && $event->scoringGroups->isNotEmpty())
            <form method="POST" action="{{ route('events.scoring-groups.copy', $event) }}" id="scoring-groups-copy"
                  x-data="{ submit() { if (confirm('Die gespeicherten Wertungsgruppen auf alle anderen Bewerbe dieser Veranstaltung mit gleicher Klassenkategorie übernehmen? Deren Gruppen werden ersetzt.')) this.$el.submit() } }"
                  @submit.prevent="submit()">
                @csrf
            </form>
        @endif
    </div>
@endsection
