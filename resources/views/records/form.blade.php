@php
    use App\Models\Club;
    use App\Support\TimeParser;
@endphp

@extends('layouts.app')

@section('title', isset($record) ? 'Rekord bearbeiten' : 'Rekord eintragen')

@section('content')
    @php
        $rec = $record ?? null;

        // Rekord-Typen gruppiert. Regional nutzt dieselben "Kürzel Bundesland"-Bezeichnungen wie
        // der Filter auf records/index.blade.php (Club::REGIONAL_ASSOCIATION_STATES) statt des
        // vollen Vereinsnamens, der hier ohnehin abgeschnitten wurde.
        $recordTypeGroups = [
            'International' => [
                'WR' => 'WR – Weltrekord',
                'ER' => 'ER – Europarekord',
                'OR' => 'OR – Olympischer Rekord',
            ],
            'National (Österreich)' => [
                'AUT' => 'AUT – Österreich (gesamt)',
                'AUT.JR' => 'AUT.JR – Österreich Jugend',
            ],
            'Regional (Österreich)' => collect(Club::REGIONAL_ASSOCIATIONS)
                ->flatMap(function ($name, $code) {
                    $state = Club::REGIONAL_ASSOCIATION_STATES[$code] ?? $name;

                    return [
                        "AUT.$code" => "$code $state",
                        "AUT.$code.JR" => "$code $state (Jugend)",
                    ];
                })->toArray(),
        ];

        // Erlaubte Standard-Streckenlängen, analog swim-events/form.blade.php. Ein beim
        // Bearbeiten evtl. abweichender Bestandswert bleibt als Zusatzoption erhalten statt beim
        // Öffnen des Formulars stillschweigend verworfen zu werden.
        $distanceOptions = [25, 50, 75, 100, 150, 200, 400, 800, 1500];
        $currentDistance = old('distance', $rec->distance ?? '');
        if ($currentDistance !== '' && ! in_array((int) $currentDistance, $distanceOptions, true)) {
            $distanceOptions[] = (int) $currentDistance;
            sort($distanceOptions);
        }

        // Splitzeiten-Zeilen: genug für die längste erlaubte Distanz (1500 m in 50er-Schritten =
        // 29 Zwischenzeiten vor dem Ziel), nicht fix 10 — sonst fehlten bei 800 m/1500 m Rennen
        // Zeilen für die hinteren Splits. Welche davon sichtbar sind, richtet sich per x-show
        // live nach der gewählten Distanz (Klassifizierung-Tab), siehe distanceValue unten.
        $maxSplitRows = (int) (max($distanceOptions) / 50) - 1;

        // Vorbelegung Nation: AUT als häufigster Fall (nicht bei "Austragungsland" — das ist das
        // Land des Wettkampfs, nicht des Athleten/Vereins, und oft im Ausland).
        $autId = $nations->firstWhere('code', 'AUT')?->id;

        // Athlet → Verein-Zuordnung für die automatische Club-Vorbelegung beim Athletenwechsel.
        // Die umgekehrte Richtung (Verein zuerst gewählt → nur dessen Athleten im Dropdown) läuft
        // rein clientseitig über x-show je Option, mit denselben club_id-Werten.
        $athleteClubMap = $athletes->mapWithKeys(fn ($a) => [(string) $a->id => (string) ($a->club_id ?? '')]);

        $oldAthleteId = (string) old('athlete_id', $rec->athlete_id ?? '');
        $oldClubId = (string) old('club_id', $rec->club_id ?? '');
        $oldGender = (string) old('gender', $rec->gender ?? 'M');
        $oldSportClass = (string) old('sport_class', $rec->sport_class ?? '');

        // Inaktive Athleten sind standardmäßig ausgeblendet (Formular ist für den laufenden
        // Betrieb gedacht) — außer der Rekord ist bereits einem inaktiven Athleten zugeordnet,
        // sonst würde die eigene Auswahl beim Bearbeiten in der Liste unsichtbar, obwohl sie im
        // Feld weiterhin steht.
        $showInactiveDefault = $rec?->athlete && ! $rec->athlete->is_active;

        // Staffelteam-Anfangszustand für x-data — als eigene Variablen statt PHP-Ternäre direkt im
        // JS-Objektliteral (siehe CLAUDE.md: dieselbe @json()-Regel wie beim @json()-Komma-Fallstrick,
        // hier ohne Kommas aber mit verschachtelten {{ }}, die die IDE beim Klammern-Abgleich verwirren).
        $isRelayInitial = ($rec->relay_count ?? 1) > 1;
        $relayCountInitial = $rec->relay_count ?? 4;
    @endphp

    <div class="max-w-4xl">
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                {{ $rec ? 'Rekord bearbeiten' : 'Rekord manuell eintragen' }}
            </h1>
            <div class="mt-4">
                <flux:button href="{{ route('records.index') }}" variant="filled" icon="arrow-left" size="sm">
                    Zurück
                </flux:button>
            </div>
        </div>

        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 p-6">
            <form method="POST" action="{{ $rec ? route('records.update', $rec) : route('records.store') }}"
                  x-data='{
                      athleteId: @json($oldAthleteId),
                      clubId: @json($oldClubId),
                      athleteClubMap: @json($athleteClubMap),
                      showInactive: @json($showInactiveDefault),
                      genderFilter: @json($oldGender),
                      sportClassFilter: @json($oldSportClass),
                      ignoreClassification: false,
                      distanceValue: @json((string) $currentDistance),
                  }'
                  x-init="$watch('athleteId', id => { if (athleteClubMap[id]) { clubId = athleteClubMap[id]; } })">
                @csrf
                @if($rec)
                    @method('PUT')
                @endif

                <flux:tab.group>
                    <flux:tabs class="mb-4">
                        <flux:tab name="klassifizierung">Klassifizierung</flux:tab>
                        <flux:tab name="leistung">Leistung</flux:tab>
                        <flux:tab name="splitzeiten">Splitzeiten</flux:tab>
                    </flux:tabs>

                    <flux:tab.panel name="klassifizierung" class="space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <flux:field>
                                <flux:label>Rekord-Typ *</flux:label>
                                <flux:select variant="listbox" name="record_type" required>
                                    @foreach($recordTypeGroups as $groupLabel => $types)
                                        <flux:select.group label="{{ $groupLabel }}">
                                            @foreach($types as $val => $label)
                                                <flux:select.option value="{{ $val }}"
                                                                    :selected="old('record_type', $rec->record_type ?? 'AUT') === $val">
                                                    {{ $label }}
                                                </flux:select.option>
                                            @endforeach
                                        </flux:select.group>
                                    @endforeach
                                </flux:select>
                                <flux:error name="record_type"/>
                            </flux:field>
                            <flux:field>
                                <flux:label>Status *</flux:label>
                                <flux:select variant="listbox" name="record_status" required>
                                    @foreach(['APPROVED' => 'Bestätigt', 'PENDING' => 'Ausstehend', 'TARGETTIME' => 'Zielzeit'] as $val => $label)
                                        <flux:select.option value="{{ $val }}"
                                                            :selected="old('record_status', $rec->record_status ?? 'APPROVED') === $val">
                                            {{ $label }}
                                        </flux:select.option>
                                    @endforeach
                                </flux:select>
                            </flux:field>
                        </div>

                        <div class="grid grid-cols-3 gap-4">
                            <flux:field>
                                <flux:label>Sport-Klasse *</flux:label>
                                <flux:input name="sport_class" x-model="sportClassFilter"
                                            placeholder="S4, SB3, SM14 …" required/>
                                <flux:description class="mt-1!">Schränkt unten die Athletenauswahl auf diese Klasse
                                    ein.
                                </flux:description>
                                <flux:error name="sport_class"/>
                            </flux:field>
                            <flux:field>
                                <flux:label>Geschlecht *</flux:label>
                                <flux:select variant="listbox" name="gender" x-model="genderFilter" required>
                                    @foreach(['M' => 'Herren', 'F' => 'Damen', 'X' => 'Mixed'] as $val => $label)
                                        <flux:select.option value="{{ $val }}">{{ $label }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </flux:field>
                            <flux:field>
                                <flux:label>Bahn *</flux:label>
                                <flux:select variant="listbox" name="course" required>
                                    @foreach(['LCM' => 'LCM (50m)', 'SCM' => 'SCM (25m)', 'SCY' => 'SCY (Yards)'] as $val => $label)
                                        <flux:select.option value="{{ $val }}"
                                                            :selected="old('course', $rec->course ?? 'SCM') === $val">
                                            {{ $label }}
                                        </flux:select.option>
                                    @endforeach
                                </flux:select>
                            </flux:field>
                        </div>

                        <div class="grid grid-cols-4 gap-4">
                            <flux:field class="col-span-2">
                                <flux:label>Disziplin *</flux:label>
                                <flux:select variant="listbox" name="stroke_type_id" placeholder="Wählen…" required>
                                    @foreach($strokeTypes as $stroke)
                                        <flux:select.option value="{{ $stroke->id }}"
                                                            :selected="old('stroke_type_id', $rec->stroke_type_id ?? '') == $stroke->id">
                                            {{ $stroke->name_de }}
                                        </flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:error name="stroke_type_id"/>
                            </flux:field>
                            <flux:field>
                                <flux:label>Distanz (m) *</flux:label>
                                <flux:select variant="listbox" name="distance" x-model="distanceValue" required>
                                    @foreach($distanceOptions as $distanceOption)
                                        <flux:select.option value="{{ $distanceOption }}">
                                            {{ $distanceOption }} m
                                        </flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:error name="distance"/>
                            </flux:field>
                            <flux:field>
                                <flux:label>Staffel (Schwimmer)</flux:label>
                                <flux:input name="relay_count" type="number" min="1"
                                            value="{{ old('relay_count', $rec->relay_count ?? 1) }}"/>
                            </flux:field>
                        </div>
                    </flux:tab.panel>

                    <flux:tab.panel name="leistung" class="space-y-4">
                        {{--
                            Zweigeteilt: zuerst wer den Rekord hält (Athlet/Staffel/Verein/Nation),
                            danach was zum Rekord selbst gehört (Zeit, Ort, Datum …).
                        --}}
                        <div class="flex items-center justify-between flex-wrap gap-2">
                            <div class="text-xs font-semibold text-zinc-500 uppercase tracking-wide">Rekordinhaber</div>
                            <div class="flex items-center gap-4">
                                <flux:switch x-model="ignoreClassification"
                                             label="Klasse/Geschlecht ignorieren" size="sm"/>
                                <flux:switch x-model="showInactive" label="Auch inaktive Athleten anzeigen" size="sm"/>
                            </div>
                        </div>

                        {{--
                            Athlet ↔ Verein: Auswahl eines Athleten belegt automatisch dessen Verein
                            vor (x-init oben, athleteClubMap). Umgekehrt schränkt ein zuerst
                            gewählter Verein die Athletenliste per x-show auf dessen Athleten ein
                            (rein clientseitig, dieselbe club_id wie in athleteClubMap). Zusätzlich
                            werden nur Athleten mit passendem Geschlecht/aktueller Sportklasse
                            (Klassifizierung-Tab) angezeigt — Klassifizierungen ändern sich aber durch
                            Reviews, ein alter Rekord kann einem Athleten mit inzwischen anderer Klasse
                            gehören. Für genau diesen Fall lässt sich die Klassen-/Geschlechtsprüfung
                            über den Schalter "Klasse/Geschlecht ignorieren" abschalten (Standardfall
                            bleibt: aktuell gültige Klasse zählt). Inaktive Athleten sind separat
                            ein-/ausblendbar — Vereinsauswahl bleibt von beidem unberührt.
                        --}}
                        <div class="grid grid-cols-2 gap-4">
                            <flux:field>
                                <flux:label>Athlet<span
                                        class="ms-1 text-zinc-400 font-normal">(leer bei Staffeln)</span></flux:label>
                                <flux:select variant="listbox" searchable name="athlete_id" x-model="athleteId"
                                             placeholder="Kein Athlet / Staffel" clearable>
                                    @foreach($athletes as $athlete)
                                        @php
                                            // Rohe JS-Array-Literal-Elemente statt @json(): der ganze
                                            // x-show-Ausdruck ist doppelt angeführt, @json()s
                                            // eingebettete doppelte Anführungszeichen würden das
                                            // HTML-Attribut brechen (siehe CLAUDE.md).
                                            $athleteSportClasses = $athlete->sportClasses
                                                ->pluck('sport_class')
                                                ->map(fn ($sc) => "'".addslashes(strtoupper($sc))."'")
                                                ->implode(',');
                                        @endphp
                                        <flux:select.option value="{{ $athlete->id }}"
                                                            x-show="(showInactive || {{ $athlete->is_active ? 'true' : 'false' }})
                                                && (!clubId || clubId === '{{ (string) ($athlete->club_id ?? '') }}')
                                                && (ignoreClassification || !genderFilter || genderFilter === 'X' || genderFilter === '{{ $athlete->gender }}')
                                                && (ignoreClassification || !sportClassFilter || [{{ $athleteSportClasses }}].includes(sportClassFilter.trim().toUpperCase()))">
                                            {{ $athlete->last_name }} {{ $athlete->first_name }}
                                            @if($athlete->club)
                                                ({{ $athlete->club->short_name ?? $athlete->club->name }})
                                            @endif
                                            @if(! $athlete->is_active)
                                                — inaktiv
                                            @endif
                                        </flux:select.option>
                                    @endforeach
                                </flux:select>
                            </flux:field>
                            <flux:field>
                                <flux:label>Verein<span class="ms-1 text-zinc-400 font-normal">(zum Zeitpunkt des Rekords)</span>
                                </flux:label>
                                <flux:select variant="listbox" searchable name="club_id" x-model="clubId"
                                             placeholder="Kein Verein / unbekannt" clearable>
                                    @foreach($clubs as $club)
                                        <flux:select.option value="{{ $club->id }}">
                                            {{ $club->name }}
                                            @if($club->code)
                                                ({{ $club->code }})
                                            @endif
                                        </flux:select.option>
                                    @endforeach
                                </flux:select>
                            </flux:field>
                        </div>

                        <flux:field>
                            <flux:label>Nation</flux:label>
                            <flux:select variant="listbox" searchable name="nation_id" placeholder="Keine Nation"
                                         clearable>
                                @foreach($nations as $nation)
                                    <flux:select.option value="{{ $nation->id }}"
                                                        :selected="old('nation_id', $rec->nation_id ?? $autId) == $nation->id">
                                        {{ $nation->code }} – {{ $nation->name_de }}
                                    </flux:select.option>
                                @endforeach
                            </flux:select>
                        </flux:field>

                        {{-- Staffelteam (nur wenn relay_count > 1) --}}
                        <div
                            x-data='{
                                isRelay: @json($isRelayInitial),
                                count: @json($relayCountInitial),
                            }'
                            x-show="isRelay || $el.closest('form').querySelector('[name=relay_count]').value > 1"
                            class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                            <h3 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300 mb-3">Staffelteam</h3>
                            <p class="text-xs text-zinc-400 mb-3">Staffelmitglieder zum Zeitpunkt des Rekords. Leere
                                Zeilen
                                werden ignoriert.</p>
                            <div class="space-y-2">
                                <div
                                    class="grid grid-cols-[2rem_1fr_1fr_10rem] gap-2 text-xs font-medium text-zinc-500 dark:text-zinc-400 px-1 mb-1">
                                    <span>#</span>
                                    <span>Nachname</span>
                                    <span>Vorname</span>
                                    <span>Geburtsjahr</span>
                                </div>
                                @for($i = 0; $i < 4; $i++)
                                    @php
                                        $member = $rec?->relayTeam[$i] ?? null;
                                    @endphp
                                    <div class="grid grid-cols-[2rem_1fr_1fr_10rem] gap-2 items-center">
                                        <span class="text-xs text-zinc-400 font-mono text-center">{{ $i + 1 }}</span>
                                        <flux:input
                                            name="relay_members[{{ $i }}][last_name]"
                                            placeholder="Nachname"
                                            value="{{ old('relay_members.' . $i . '.last_name', $member?->last_name ?? '') }}"
                                        />
                                        <flux:input
                                            name="relay_members[{{ $i }}][first_name]"
                                            placeholder="Vorname"
                                            value="{{ old('relay_members.' . $i . '.first_name', $member?->first_name ?? '') }}"
                                        />
                                        <flux:date-picker
                                            type="input" locale="de-AT"
                                            size="sm"
                                            name="relay_members[{{ $i }}][birth_date]"
                                            value="{{ old('relay_members.' . $i . '.birth_date', $member?->birth_date?->format('Y-m-d') ?? '') }}"
                                            clearable
                                        />
                                    </div>
                                @endfor
                            </div>
                        </div>

                        <div
                            class="text-xs font-semibold text-zinc-500 uppercase tracking-wide pt-2 border-t border-zinc-100 dark:border-zinc-800">
                            Rekorddaten
                        </div>

                        {{-- Wettkampf breiter als Ort (kurze Ortsnamen). --}}
                        <div class="grid grid-cols-3 gap-4">
                            <flux:field class="col-span-2">
                                <flux:label>Wettkampf</flux:label>
                                <flux:input name="meet_name" value="{{ old('meet_name', $rec->meet_name ?? '') }}"/>
                            </flux:field>
                            <flux:field>
                                <flux:label>Ort</flux:label>
                                <flux:input name="meet_city" value="{{ old('meet_city', $rec->meet_city ?? '') }}"/>
                            </flux:field>
                        </div>

                        {{-- Zeitmaske via IMask.js. Format: MM:SS.cs — Breite bewusst nicht die
                             ganze Card-Breite (flux:input bringt intern ein festes w-full mit,
                             das eine Klasse direkt am Feld überstimmt — siehe athletes/index.blade.php
                             für dieselbe Erkenntnis — deshalb ein eigener, schrumpfbarer Wrapper-Div). --}}
                        <div class="grid grid-cols-3 gap-4 items-start">
                            <flux:field>
                                <flux:label>Schwimmzeit *</flux:label>
                                <div class="w-full max-w-40">
                                    <flux:input
                                        name="swim_time"
                                        type="text"
                                        value="{{ old('swim_time', $rec ? TimeParser::display($rec->swim_time) : '') }}"
                                        placeholder="00:00.00"
                                        required
                                        x-data
                                        x-init="IMask($el, { mask: '00:00.00', lazy: false, placeholderChar: '0' })"
                                    />
                                </div>
                                <flux:description class="mt-1!">MM:SS.cs — z.B. 01:05.32</flux:description>
                                <flux:error name="swim_time"/>
                            </flux:field>
                            <flux:field>
                                <flux:label>Rekorddatum</flux:label>
                                <flux:date-picker type="input" locale="de-AT" name="set_date"
                                                  value="{{ old('set_date', $rec?->set_date?->format('Y-m-d') ?? '') }}"
                                                  clearable/>
                            </flux:field>
                            <flux:field>
                                <flux:label>Austragungsland</flux:label>
                                <flux:select variant="listbox" searchable name="meet_nation_id" placeholder="Unbekannt"
                                             clearable>
                                    @foreach($nations as $nation)
                                        <flux:select.option value="{{ $nation->id }}"
                                                            :selected="old('meet_nation_id', $rec->meet_nation_id ?? '') == $nation->id">
                                            {{ $nation->code }} – {{ $nation->name_de }}
                                        </flux:select.option>
                                    @endforeach
                                </flux:select>
                            </flux:field>
                        </div>

                        <flux:field>
                            <flux:label>Anmerkung</flux:label>
                            <flux:input name="comment" value="{{ old('comment', $rec->comment ?? '') }}"/>
                        </flux:field>
                    </flux:tab.panel>

                    <flux:tab.panel name="splitzeiten">
                        <p class="text-xs text-zinc-400 mb-4">Leere Zeilen werden ignoriert. Kumulierte Zeit ab
                            Start. Zeilen passen sich der auf "Klassifizierung" gewählten Distanz an.</p>

                        <div class="space-y-2">
                            <div
                                class="grid grid-cols-2 gap-3 text-xs font-medium text-zinc-500 dark:text-zinc-400 px-1 mb-1">
                                <span>Distanz (m)</span>
                                <span>Zeit (MM:SS.cs)</span>
                            </div>
                            @for($i = 0; $i < $maxSplitRows; $i++)
                                <div class="grid grid-cols-2 gap-3"
                                     x-show="!distanceValue || {{ ($i + 1) * 50 }} < parseInt(distanceValue)">
                                    <flux:input
                                        name="splits[{{ $i }}][distance]"
                                        type="number"
                                        min="1"
                                        step="50"
                                        value="{{ old('splits.' . $i . '.distance', $rec?->splits[$i]->distance ?? '') }}"
                                        placeholder="{{ ($i + 1) * 50 }}"
                                    />
                                    <flux:input
                                        name="splits[{{ $i }}][split_time]"
                                        type="text"
                                        value="{{ old('splits.' . $i . '.split_time', isset($rec->splits[$i]) ? TimeParser::display($rec->splits[$i]->split_time) : '') }}"
                                        placeholder="00:00.00"
                                        x-data
                                        x-init="
                                            IMask($el, {
                                                mask: '00:00.00',
                                                lazy: false,
                                                placeholderChar: '0'
                                            })
                                        "
                                    />
                                </div>
                            @endfor
                        </div>
                    </flux:tab.panel>
                </flux:tab.group>

                <div class="flex gap-3 pt-6">
                    <flux:button type="submit" variant="primary">
                        {{ $rec ? 'Änderungen speichern' : 'Rekord eintragen' }}
                    </flux:button>
                    <flux:button
                        href="{{ $rec ? route('records.show', $rec) : route('records.index') }}"
                        variant="ghost">
                        Abbrechen
                    </flux:button>
                </div>
            </form>
        </div>
    </div>
@endsection
