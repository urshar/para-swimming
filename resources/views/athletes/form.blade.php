@extends('layouts.app')

@section('title', isset($athlete) ? $athlete->display_name . ' bearbeiten' : 'Neuer Athlet')

@section('content')
    {{-- Vorbelegung Nation: AUT als häufigster Fall, damit nicht bei jeder Neuanlage manuell
         ausgewählt werden muss (wie schon in classifiers/form.blade.php). --}}
    @php $autId = $nations->firstWhere('code', 'AUT')?->id; @endphp
    <div class="max-w-3xl">

        <div class="flex items-center gap-3 mb-6">
            <flux:button href="{{ session('athletes.list_url', route('athletes.index')) }}" variant="ghost"
                         icon="arrow-left" size="sm"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                {{ isset($athlete) ? 'Athlet bearbeiten' : 'Neuer Athlet' }}
            </h1>
        </div>

        <form method="POST"
              action="{{ isset($athlete) ? route('athletes.update', $athlete) : route('athletes.store') }}">
            @csrf
            @if(isset($athlete))
                @method('PUT')
            @endif

            {{-- Tabs statt gestapelter/nebeneinander liegender Karten: jede Karte bekommt die volle
                 Formularbreite (ein direktes Nebeneinander drängte Kontakt&Adresse/Notizen zu schmal
                 zusammen — Rückmeldung), und der Wechsel zwischen den Karten spart weiterhin die
                 Scroll-Strecke bis zum Speichern-Button, ohne dass irgendetwas eng wird. --}}
            <flux:tab.group>
                <flux:tabs class="mb-4">
                    <flux:tab name="stammdaten">Stammdaten</flux:tab>
                    <flux:tab name="kontakt">Kontakt & Adresse</flux:tab>
                </flux:tabs>

                <flux:tab.panel name="stammdaten">
            {{-- Stammdaten --}}
            <div
                class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4">
                <div class="flex items-center justify-between gap-4">
                    <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">Stammdaten</h2>
                    {{-- Aktiv-Schalter --}}
                    <label class="flex items-center gap-2 cursor-pointer shrink-0">
                        <span class="text-sm text-zinc-600 dark:text-zinc-400">Aktiver Schwimmer</span>
                        <input type="hidden" name="is_active" value="0">
                        <flux:switch name="is_active" value="1" :checked="old('is_active', $athlete->is_active ?? true)"/>
                    </label>
                </div>

                {{-- Eigene Zeile statt in der Überschriftenzeile mitgedrängt (Rückmeldung: wirkte zu
                     weit rechts zusammengequetscht) — jetzt mit der vollen Kartenbreite Platz genug für
                     Text und Button nebeneinander. --}}
                @if(isset($athlete))
                    <div class="flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                        <flux:icon.information-circle class="size-4 shrink-0 text-blue-500" variant="mini"/>
                        <span>Vereinswechsel, Klassifikationen und Level-Änderungen werden in der Detailansicht verwaltet.</span>
                        <flux:button href="{{ route('athletes.show', $athlete) }}" size="xs" variant="ghost"
                                     class="ms-auto shrink-0">
                            Detailansicht
                        </flux:button>
                    </div>
                @endif

                <div class="grid grid-cols-3 gap-4">
                    <flux:field>
                        <flux:label>Namenspräfix</flux:label>
                        <flux:input name="name_prefix" value="{{ old('name_prefix', $athlete->name_prefix ?? '') }}"
                                    placeholder="van den…"/>
                        <flux:error name="name_prefix"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Vorname *</flux:label>
                        <flux:input name="first_name" value="{{ old('first_name', $athlete->first_name ?? '') }}"
                                    required/>
                        <flux:error name="first_name"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Nachname *</flux:label>
                        <flux:input name="last_name" value="{{ old('last_name', $athlete->last_name ?? '') }}"
                                    required/>
                        <flux:error name="last_name"/>
                    </flux:field>
                </div>

                <div class="grid grid-cols-3 gap-4">
                    <flux:field>
                        <flux:label>Geschlecht *</flux:label>
                        <flux:select variant="listbox" name="gender" required>
                            <flux:select.option value="M" :selected="old('gender', $athlete->gender ?? 'M') === 'M'">Männlich</flux:select.option>
                            <flux:select.option value="F" :selected="old('gender', $athlete->gender ?? '') === 'F'">Weiblich</flux:select.option>
                            <flux:select.option value="N" :selected="old('gender', $athlete->gender ?? '') === 'N'">Nicht binär</flux:select.option>
                        </flux:select>
                        <flux:error name="gender"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Geburtsdatum</flux:label>
                        <flux:date-picker type="input" locale="de-AT" name="birth_date"
                                    value="{{ old('birth_date', isset($athlete) && $athlete->birth_date ? $athlete->birth_date->format('Y-m-d') : '') }}"
                                    clearable/>
                        <flux:error name="birth_date"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Nation *</flux:label>
                        <flux:select variant="listbox" searchable name="nation_id" placeholder="Bitte wählen…" required>
                            @foreach($nations as $nation)
                                <flux:select.option value="{{ $nation->id }}" :selected="old('nation_id', $athlete->nation_id ?? $autId) == $nation->id">{{ $nation->code }} – {{ $nation->name_de }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="nation_id"/>
                    </flux:field>
                </div>

                {{-- Verein/Vereinseintritt/Status in einer einheitlichen Zeile statt des vorherigen
                     zweizeiligen Grids mit leerem Platzhalter-Feld bei Neuanlage. --}}
                <div class="grid {{ isset($athlete) ? 'grid-cols-2' : 'grid-cols-3' }} gap-4">
                    <flux:field>
                        <flux:label>Verein</flux:label>
                        <flux:select variant="listbox" searchable name="club_id" placeholder="Kein Verein" clearable>
                            @foreach($clubs as $club)
                                <flux:select.option value="{{ $club->id }}" :selected="old('club_id', $athlete->club_id ?? '') == $club->id">{{ $club->display_name }} ({{ $club->nation?->code }})</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="club_id"/>
                    </flux:field>
                    @if(!isset($athlete))
                        {{-- Nur bei Neuanlage: Eintrittsdatum für die Club-History --}}
                        <flux:field>
                            <flux:label>Vereinseintritt</flux:label>
                            <flux:date-picker type="input" locale="de-AT" name="club_joined_at"
                                        value="{{ old('club_joined_at', today()->format('Y-m-d')) }}" clearable/>
                            <flux:error name="club_joined_at"/>
                        </flux:field>
                    @endif
                    <flux:field>
                        <flux:label>Status</flux:label>
                        <flux:select variant="listbox" name="status" placeholder="Normal" clearable>
                            <flux:select.option value="EXHIBITION" :selected="old('status', $athlete->status ?? '') === 'EXHIBITION'">Exhibition</flux:select.option>
                            <flux:select.option value="FOREIGNER" :selected="old('status', $athlete->status ?? '') === 'FOREIGNER'">Ausländer</flux:select.option>
                            <flux:select.option value="ROOKIE" :selected="old('status', $athlete->status ?? '') === 'ROOKIE'">Rookie</flux:select.option>
                        </flux:select>
                        <flux:error name="status"/>
                    </flux:field>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Nationale Lizenznummer</flux:label>
                        <flux:input name="license" value="{{ old('license', $athlete->license ?? '') }}"/>
                        <flux:error name="license"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>SDMS ID (IPC/WPS)</flux:label>
                        <flux:input name="license_ipc" value="{{ old('license_ipc', $athlete->license_ipc ?? '') }}"
                                    placeholder="World Para Swimming ID"/>
                        <flux:error name="license_ipc"/>
                    </flux:field>
                </div>

                {{-- items-start statt des Grid-Defaults (stretch): sonst zieht die Beschreibung unter
                     "ÖBSV Level" die Zeile in die Höhe und das Behinderungsart-Select wird sichtbar mitgestreckt,
                     wodurch die beiden Felder ungleich groß wirken. --}}
                <div class="grid grid-cols-2 gap-4 items-start">
                    <flux:field>
                        <flux:label>Behinderungsart</flux:label>
                        <flux:select variant="listbox" name="disability_type" placeholder="Nicht angegeben" clearable>
                            <flux:select.option value="physical" :selected="old('disability_type', $athlete->disability_type ?? '') === 'physical'">Körperlich</flux:select.option>
                            <flux:select.option value="visual" :selected="old('disability_type', $athlete->disability_type ?? '') === 'visual'">Sehbehinderung</flux:select.option>
                            <flux:select.option value="intellectual" :selected="old('disability_type', $athlete->disability_type ?? '') === 'intellectual'">Intellektuell</flux:select.option>
                            <flux:select.option value="deaf" :selected="old('disability_type', $athlete->disability_type ?? '') === 'deaf'">Hörbehinderung</flux:select.option>
                            <flux:select.option value="trisomie" :selected="old('disability_type', $athlete->disability_type ?? '') === 'trisomie'">Down Syndrom</flux:select.option>
                        </flux:select>
                    </flux:field>
                    {{-- Keine flux:description hier (wie bei keinem anderen Feld in diesem Formular) —
                         der Hinweistext "Änderungen werden protokolliert" brach in der halben Spaltenbreite
                         immer zweizeilig um und ließ die Zeile gegenüber "Behinderungsart" unruhig wirken.
                         Die Historie ist ohnehin über den Level-History-Block in der Detailansicht sichtbar. --}}
                    <flux:field>
                        <flux:label>ÖBSV Level</flux:label>
                        <flux:input name="level" value="{{ old('level', $athlete->level ?? '') }}"
                                    placeholder="z.B. Elite, Talent, 1, 2 …"/>
                        <flux:error name="level"/>
                    </flux:field>
                </div>
            </div>
            {{-- /Stammdaten --}}
                </flux:tab.panel>

                <flux:tab.panel name="kontakt" class="space-y-4">
            {{-- Kontakt & Adresse --}}
            <div
                class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">Kontakt & Adresse</h2>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>E-Mail</flux:label>
                        <flux:input name="email" type="email"
                                    value="{{ old('email', $athlete->email ?? '') }}"
                                    placeholder="athlet@example.com"/>
                        <flux:error name="email"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Telefon / Mobil</flux:label>
                        <flux:input name="phone"
                                    value="{{ old('phone', $athlete->phone ?? '') }}"
                                    placeholder="+43 …"/>
                        <flux:error name="phone"/>
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>Straße & Hausnummer</flux:label>
                    <flux:input name="address_street"
                                value="{{ old('address_street', $athlete->address_street ?? '') }}"/>
                    <flux:error name="address_street"/>
                </flux:field>

                <div class="grid grid-cols-3 gap-4">
                    <flux:field>
                        <flux:label>PLZ</flux:label>
                        <flux:input name="address_zip"
                                    value="{{ old('address_zip', $athlete->address_zip ?? '') }}"
                                    placeholder="1010"/>
                        <flux:error name="address_zip"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Ort</flux:label>
                        <flux:input name="address_city"
                                    value="{{ old('address_city', $athlete->address_city ?? '') }}"
                                    placeholder="Wien"/>
                        <flux:error name="address_city"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Land (ISO)</flux:label>
                        <flux:input name="address_country"
                                    value="{{ old('address_country', $athlete->address_country ?? 'AUT') }}"
                                    placeholder="AUT" maxlength="3"/>
                        <flux:error name="address_country"/>
                    </flux:field>
                </div>
            </div>

            {{-- Notizen --}}
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-4">Notizen</h2>
                <flux:field>
                    <flux:textarea name="notes" rows="4"
                                   placeholder="Interne Notizen zum Athleten …">{{ old('notes', $athlete->notes ?? '') }}</flux:textarea>
                    <flux:error name="notes"/>
                </flux:field>
            </div>
            {{-- /Notizen --}}
                </flux:tab.panel>
            </flux:tab.group>

            {{-- Sportklassen und WPS Exceptions werden nicht mehr hier gepflegt, sondern ausschließlich
                 über "Klassifikation eintragen" in der Detailansicht (athletes.show) — das ist der
                 einzige Weg, der auch die Klassifikations-History korrekt fortschreibt. Nach dem
                 Anlegen eines neuen Athleten geht's per Redirect direkt dorthin, siehe
                 AthleteController::store(). --}}

            <div class="flex gap-3 mt-4">
                <flux:button type="submit" variant="primary">
                    {{ isset($athlete) ? 'Speichern' : 'Athlet anlegen' }}
                </flux:button>
                <flux:button
                    href="{{ isset($athlete) ? route('athletes.show', $athlete) : session('athletes.list_url', route('athletes.index')) }}"
                    variant="ghost">
                    Abbrechen
                </flux:button>
            </div>

        </form>
    </div>
@endsection
