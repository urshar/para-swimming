@extends('layouts.app')

@section('title', isset($meet) ? 'Wettkampf bearbeiten' : 'Neuer Wettkampf')

@section('content')
    {{-- Vorbelegung Nation: AUT als häufigster Fall. --}}
    @php $autId = $nations->firstWhere('code', 'AUT')?->id; @endphp
    <div class="max-w-3xl">

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

            {{-- Nicht-Admins sehen nur die Grunddaten (keine Vereinsverwaltungs-/WPS-Felder) — dafür
                 lohnen sich Tabs nicht, ein einzelner Reiter wäre nur Ballast. --}}
            @if(auth()->user()?->is_admin)
                <flux:tab.group>
                    <flux:tabs class="mb-4">
                        <flux:tab name="grunddaten">Grunddaten</flux:tab>
                        <flux:tab name="oeffentlichkeit">Öffentlichkeit &amp; WPS</flux:tab>
                        <flux:tab name="punkte">Punkteberechnung</flux:tab>
                    </flux:tabs>

                    <flux:tab.panel name="grunddaten">
                        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-5">
                            @include('meets._grunddaten-fields')
                        </div>
                    </flux:tab.panel>

                    <flux:tab.panel name="oeffentlichkeit">
                        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-5">
                            <flux:field>
                                <flux:label>Auf der öffentlichen Seite sichtbar</flux:label>
                                <flux:switch name="is_published" value="1" :checked="old('is_published', $meet->is_published ?? false)"
                                             label="Erscheint in der öffentlichen Veranstaltungsliste"/>
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
                                <flux:switch name="wps_approved" value="1" :checked="old('wps_approved', $meet->wps_approved ?? false)"
                                             label="Von World Para Swimming sanktioniert"/>
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
                                <flux:select variant="listbox" name="cup_id" placeholder="Kein Cup" clearable>
                                    @foreach($cups as $cup)
                                        <flux:select.option value="{{ $cup->id }}"
                                            :selected="old('cup_id', $meet->cup_id ?? '') == $cup->id">
                                            {{ $cup->name }}
                                        </flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:description>
                                    Ein Wettkampf kann keinem oder genau einem Cup angehören.
                                </flux:description>
                                <flux:error name="cup_id"/>
                            </flux:field>

                            <flux:field>
                                <flux:label>Richtzeitenliste (ÖSTM & ÖM)</flux:label>
                                <flux:select variant="listbox" name="qualifying_time_list_id" placeholder="Keine Richtzeitenliste" clearable>
                                    @foreach($qualifyingTimeLists as $qtl)
                                        <flux:select.option value="{{ $qtl->id }}"
                                            :selected="old('qualifying_time_list_id', $meet->qualifying_time_list_id ?? '') == $qtl->id">
                                            {{ $qtl->year }}
                                        </flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:description>
                                    Markiert diesen Wettkampf als die ÖSTM & ÖM-Veranstaltung des jeweiligen Jahres —
                                    Grundlage für die automatische Richtzeiten-Berechnung (Kurs/Datum) und später die
                                    Qualifikationsermittlung.
                                </flux:description>
                                <flux:error name="qualifying_time_list_id"/>
                            </flux:field>
                        </div>
                    </flux:tab.panel>

                    <flux:tab.panel name="punkte">
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
                        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6"
                             x-data='meetPointSystems(@json($wpsAlpineConfig))'>
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
                                    <flux:select variant="listbox" name="wps_point_version_id" placeholder="automatisch nach Wettkampfdatum" clearable>
                                        @foreach($wpsVersions as $wpsVersion)
                                            <flux:select.option value="{{ $wpsVersion->id }}"
                                                :selected="(int) old('wps_point_version_id', $selectedWpsVersionId) === $wpsVersion->id">
                                                {{ $wpsVersion->label }}
                                            </flux:select.option>
                                        @endforeach
                                    </flux:select>
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
                    </flux:tab.panel>
                </flux:tab.group>
            @else
                <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-5">
                    @include('meets._grunddaten-fields')
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
