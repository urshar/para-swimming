@extends('layouts.app')

@section('title', isset($meet) ? 'Wettkampf bearbeiten' : 'Neuer Wettkampf')

@section('content')
    <div class="max-w-2xl">

        {{-- Header --}}
        <div class="flex items-center gap-3 mb-6">
            <flux:button href="{{ route('meets.index') }}" variant="ghost" icon="arrow-left" size="sm"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                {{ isset($meet) ? 'Wettkampf bearbeiten' : 'Neuer Wettkampf' }}
            </h1>
        </div>

        <form method="POST" action="{{ isset($meet) ? route('meets.update', $meet) : route('meets.store') }}">
            @csrf
            @if(isset($meet))
                @method('PUT')
            @endif

            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-5">

                <flux:field>
                    <flux:label>Name *</flux:label>
                    <flux:input name="name" value="{{ old('name', $meet->name ?? '') }}" required/>
                    <flux:error name="name"/>
                </flux:field>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Stadt *</flux:label>
                        <flux:input name="city" value="{{ old('city', $meet->city ?? '') }}" required/>
                        <flux:error name="city"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Nation *</flux:label>
                        <flux:select name="nation_id" required>
                            <option value="">Bitte wählen…</option>
                            @foreach($nations as $nation)
                                <option value="{{ $nation->id }}"
                                    @selected(old('nation_id', $meet->nation_id ?? '') == $nation->id)>
                                    {{ $nation->code }} – {{ $nation->name_de }}
                                </option>
                            @endforeach
                        </flux:select>
                        <flux:error name="nation_id"/>
                    </flux:field>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Startdatum *</flux:label>
                        <flux:input name="start_date" type="date"
                                    value="{{ old('start_date', isset($meet) ? $meet->start_date->format('Y-m-d') : '') }}"
                                    required/>
                        <flux:error name="start_date"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Enddatum</flux:label>
                        <flux:input name="end_date" type="date"
                                    value="{{ old('end_date', isset($meet) && $meet->end_date ? $meet->end_date->format('Y-m-d') : '') }}"/>
                        <flux:error name="end_date"/>
                    </flux:field>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Bahnlänge *</flux:label>
                        <flux:select name="course" required>
                            @foreach(['LCM' => 'LCM (50m)', 'SCM' => 'SCM (25m)', 'SCY' => 'SCY (Yards)', 'OPEN' => 'Freiwasser'] as $val => $label)
                                <option value="{{ $val }}" @selected(old('course', $meet->course ?? 'LCM') === $val)>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </flux:select>
                        <flux:error name="course"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Zeitnahme</flux:label>
                        <flux:select name="timing">
                            <option value="">Nicht angegeben</option>
                            @foreach(['AUTOMATIC' => 'Automatisch', 'SEMIAUTOMATIC' => 'Halbautomatisch', 'MANUAL3' => 'Manuell 3', 'MANUAL2' => 'Manuell 2', 'MANUAL1' => 'Manuell 1'] as $val => $label)
                                <option value="{{ $val }}" @selected(old('timing', $meet->timing ?? '') === $val)>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </flux:select>
                        <flux:error name="timing"/>
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>Veranstalter</flux:label>
                    <flux:input name="organizer" value="{{ old('organizer', $meet->organizer ?? '') }}"/>
                    <flux:error name="organizer"/>
                </flux:field>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Meldetyp</flux:label>
                        <flux:select name="entry_type">
                            <option value="">Nicht angegeben</option>
                            <option value="OPEN" @selected(old('entry_type', $meet->entry_type ?? '') === 'OPEN')>
                                Offen
                            </option>
                            <option
                                value="INVITATION" @selected(old('entry_type', $meet->entry_type ?? '') === 'INVITATION')>
                                Nur Eingeladene
                            </option>
                        </flux:select>
                        <flux:error name="entry_type"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Höhe über Meeresspiegel</flux:label>
                        <flux:input name="altitude" type="number" min="0" max="9000"
                                    value="{{ old('altitude', $meet->altitude ?? 0) }}"/>
                        <flux:error name="altitude"/>
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>Meldeschluss</flux:label>
                    <flux:input name="entries_deadline" type="date"
                                value="{{ old('entries_deadline', isset($meet) && $meet->entries_deadline ? $meet->entries_deadline->format('Y-m-d') : '') }}"/>
                    <flux:description>Datum bis zu dem Vereine Meldungen einreichen können.</flux:description>
                    <flux:error name="entries_deadline"/>
                </flux:field>

                <flux:field>
                    <flux:label>Vereinsmeldungen freigegeben</flux:label>
                    <div class="flex items-center gap-3 mt-1">
                        <input type="checkbox" name="is_open" value="1" id="is_open"
                               class="w-4 h-4 rounded border-zinc-300 dark:border-zinc-600
                                      text-blue-600 bg-white dark:bg-zinc-800
                                      focus:ring-blue-500 focus:ring-2"
                            {{ old('is_open', $meet->is_open ?? false) ? 'checked' : '' }}>
                        <label for="is_open" class="text-sm text-zinc-700 dark:text-zinc-300 cursor-pointer">
                            Österreichische Vereine können sich für diesen Wettkampf anmelden
                        </label>
                    </div>
                </flux:field>

                @if(auth()->user()?->is_admin)
                    <flux:field>
                        <flux:label>Auf der öffentlichen Seite sichtbar</flux:label>
                        <div class="flex items-center gap-3 mt-1">
                            <input type="checkbox" name="is_published" value="1" id="is_published"
                                   class="w-4 h-4 rounded border-zinc-300 dark:border-zinc-600
                                          text-blue-600 bg-white dark:bg-zinc-800
                                          focus:ring-blue-500 focus:ring-2"
                                {{ old('is_published', $meet->is_published ?? false) ? 'checked' : '' }}>
                            <label for="is_published" class="text-sm text-zinc-700 dark:text-zinc-300 cursor-pointer">
                                Erscheint in der öffentlichen Veranstaltungsliste
                            </label>
                        </div>
                        <flux:description>
                            Ohne diese Freigabe ist der Wettkampf für Besucher der öffentlichen Seite unsichtbar —
                            auch dann, wenn bereits Dokumente dazu freigegeben sind (Spec public-frontend §4.2).
                        </flux:description>
                    </flux:field>

                    <flux:field>
                        <flux:label>Livetiming-Link</flux:label>
                        <flux:input name="livetiming_url" type="url"
                                    value="{{ old('livetiming_url', $meet->livetiming_url ?? '') }}"
                                    placeholder="https://…"/>
                        <flux:description>Wird auf der öffentlichen Veranstaltungsseite als externer Link angezeigt.</flux:description>
                        <flux:error name="livetiming_url"/>
                    </flux:field>

                    <flux:field>
                        <flux:label>WPS-anerkannter Wettkampf</flux:label>
                        <div class="flex items-center gap-3 mt-1">
                            <input type="checkbox" name="wps_approved" value="1" id="wps_approved"
                                   class="w-4 h-4 rounded border-zinc-300 dark:border-zinc-600
                                          text-blue-600 bg-white dark:bg-zinc-800
                                          focus:ring-blue-500 focus:ring-2"
                                {{ old('wps_approved', $meet->wps_approved ?? false) ? 'checked' : '' }}>
                            <label for="wps_approved" class="text-sm text-zinc-700 dark:text-zinc-300 cursor-pointer">
                                Von World Para Swimming sanktioniert
                            </label>
                        </div>
                        <flux:description>
                            Nur Zeiten aus sanktionierten Wettkämpfen gelten als Qualifikationsnachweis
                            für internationale Meisterschaften. Ohne diese Kennzeichnung erscheint ein
                            Ergebnis nicht in der Qualifikantenliste — in der Förderansicht sehr wohl,
                            dort mit entsprechendem Vermerk.
                        </flux:description>
                    </flux:field>

                    <flux:field>
                        <flux:label>Fundstelle der Anerkennung</flux:label>
                        <flux:input name="wps_approved_note"
                                    value="{{ old('wps_approved_note', $meet->wps_approved_note ?? '') }}"
                                    placeholder="z.B. WPS Sanctioned Competitions 2026, Nr. 14"/>
                        <flux:description>Optional — wo die Anerkennung nachzulesen ist.</flux:description>
                        <flux:error name="wps_approved_note"/>
                    </flux:field>

                    <flux:field>
                        <flux:label>ÖBSV Cup</flux:label>
                        <flux:select name="cup_id">
                            <option value="">Kein Cup</option>
                            @foreach($cups as $cup)
                                <option value="{{ $cup->id }}"
                                    @selected(old('cup_id', $meet->cup_id ?? '') == $cup->id)>
                                    {{ $cup->name }}
                                </option>
                            @endforeach
                        </flux:select>
                        <flux:description>
                            Ein Wettkampf kann keinem oder genau einem Cup angehören.
                        </flux:description>
                        <flux:error name="cup_id"/>
                    </flux:field>

                    <flux:field>
                        <flux:label>Richtzeitenliste (ÖSTM & ÖM)</flux:label>
                        <flux:select name="qualifying_time_list_id">
                            <option value="">Keine Richtzeitenliste</option>
                            @foreach($qualifyingTimeLists as $qtl)
                                <option value="{{ $qtl->id }}"
                                    @selected(old('qualifying_time_list_id', $meet->qualifying_time_list_id ?? '') == $qtl->id)>
                                    {{ $qtl->year }}
                                </option>
                            @endforeach
                        </flux:select>
                        <flux:description>
                            Markiert diesen Wettkampf als die ÖSTM & ÖM-Veranstaltung des jeweiligen Jahres —
                            Grundlage für die automatische Richtzeiten-Berechnung (Kurs/Datum) und später die
                            Qualifikationsermittlung.
                        </flux:description>
                        <flux:error name="qualifying_time_list_id"/>
                    </flux:field>
                @endif

            </div>

            @if(auth()->user()?->is_admin)
                @php
                    // old() liefert die Werte als Zeichenketten zurück — für den strikten
                    // Vergleich mit den IDs müssen sie auf Integer normalisiert werden.
                    $selectedIds = array_map('intval', old('point_systems', $selectedPointSystems));

                    // Die Konfiguration wird als ein zusammenhängender JSON-Wert übergeben.
                    // Einzelne {{ }}-Ausdrücke mitten im JavaScript-Attribut lassen sich von
                    // der IDE nicht mehr als Ausdruck lesen.
                    $wpsAlpineConfig = [
                        'wpsSelected' => in_array($wpsSystemId, $selectedIds, true),
                        'course' => old('course', $meet->course ?? 'LCM'),
                    ];
                @endphp
                {{-- Punkteberechnung: welche Punktesysteme werden für diesen Wettkampf
                     berechnet? World Aquatics läuft über die Basiszeiten, WPS über die
                     importierten Point Scores. --}}
                <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 mt-6"
                     x-data="meetPointSystems(@json($wpsAlpineConfig))">
                    <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-4">Punkteberechnung</h2>

                    <div class="space-y-3">
                        @foreach($pointSystems as $system)
                            <label class="flex items-start gap-3 cursor-pointer">
                                {{-- Bewusst KEIN x-model: sonst würden zwei Mechanismen über den
                                     Zustand der Checkbox bestimmen — @checked serverseitig und
                                     Alpine beim Initialisieren. Weichen sie ab, gewinnt Alpine
                                     und überschreibt eine gespeicherte Auswahl stillschweigend.
                                     Stattdessen entscheidet allein der Server; Alpine liest den
                                     Zustand nur mit, um die Versionsauswahl ein- und
                                     auszublenden.

                                     Aus demselben Grund kein x-init: Den Ausgangszustand kennt
                                     die Komponente bereits aus wpsAlpineConfig.wpsSelected, das
                                     aus derselben PHP-Quelle stammt wie das @checked darunter.
                                     Ihn zusätzlich aus dem DOM zu lesen wäre eine zweite Quelle
                                     für denselben Zustand. --}}
                                <input type="checkbox" name="point_systems[]" value="{{ $system->id }}"
                                       @if((int) $system->id === $wpsSystemId)
                                           @change="wps = $event.target.checked"
                                       @endif
                                       @checked(in_array((int) $system->id, $selectedIds, true))
                                       class="mt-1 rounded border-zinc-300 dark:border-zinc-600 dark:bg-zinc-700">
                                <span>
                                    <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                        {{ $system->name }}
                                    </span>
                                    @if($system->description)
                                        <span class="block text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ $system->description }}
                                        </span>
                                    @endif
                                </span>
                            </label>
                        @endforeach
                    </div>

                    <div x-show="wps" x-cloak class="mt-4 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                        <flux:field>
                            <flux:label>WPS-Version</flux:label>
                            <select name="wps_point_version_id"
                                    class="w-full rounded-lg border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800 text-sm">
                                <option value="">automatisch nach Wettkampfdatum</option>
                                @foreach($wpsVersions as $wpsVersion)
                                    <option value="{{ $wpsVersion->id }}"
                                            @selected((int) old('wps_point_version_id', $selectedWpsVersionId) === $wpsVersion->id)>
                                        {{ $wpsVersion->label }}
                                    </option>
                                @endforeach
                            </select>
                            <flux:description>
                                Leer lassen, damit die zum Wettkampfdatum gültige Version verwendet wird.
                            </flux:description>
                            <flux:error name="wps_point_version_id"/>
                        </flux:field>

                        {{-- Für Kurzbahn liegen keine offiziellen WPS-Parameter vor. --}}
                        <div x-show="showEstimatedWarning" x-cloak
                             class="mt-4 p-4 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800 rounded-xl text-sm text-amber-700 dark:text-amber-400">
                            Die Berechnung der WPS-Punkte erfolgt auf Basis abgeleiteter Parameter.
                            Diese Werte sind nicht offiziell von World Para Swimming veröffentlicht
                            und werden als "geschätzt" gekennzeichnet.
                        </div>
                    </div>
                </div>
            @endif

            <div class="flex gap-3 mt-6">
                <flux:button type="submit" variant="primary">
                    {{ isset($meet) ? 'Speichern' : 'Wettkampf anlegen' }}
                </flux:button>
                <flux:button href="{{ isset($meet) ? route('meets.show', $meet) : route('meets.index') }}"
                             variant="ghost">
                    Abbrechen
                </flux:button>
            </div>
        </form>

    </div>
@endsection
