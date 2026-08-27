@extends('layouts.app')

@section('title', isset($athlete) ? $athlete->display_name . ' bearbeiten' : 'Neuer Athlet')

@section('content')
    <div class="max-w-3xl">

        <div class="flex items-center gap-3 mb-6">
            <flux:button href="{{ session('athletes.list_url', route('athletes.index')) }}" variant="ghost"
                         icon="arrow-left" size="sm"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                {{ isset($athlete) ? 'Athlet bearbeiten' : 'Neuer Athlet' }}
            </h1>
        </div>

        {{-- Hinweis-Banner: History-Aktionen nur in der Detailansicht --}}
        @if(isset($athlete))
            <div
                class="mb-4 flex items-center justify-between gap-4 rounded-lg border border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-950/40 px-4 py-3 text-sm text-blue-800 dark:text-blue-300">
                <div class="flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24"
                         stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M13 16h-1v-4h-1m1-4h.01M12 2a10 10 0 110 20A10 10 0 0112 2z"/>
                    </svg>
                    <span>Vereinswechsel, Klassifikationen und Level-Änderungen werden in der Detailansicht verwaltet.</span>
                </div>
                <a href="{{ route('athletes.show', $athlete) }}"
                   class="shrink-0 font-medium underline underline-offset-2 hover:text-blue-600 dark:hover:text-blue-100 transition-colors">
                    Zur Detailansicht →
                </a>
            </div>
        @endif

        <form method="POST"
              action="{{ isset($athlete) ? route('athletes.update', $athlete) : route('athletes.store') }}">
            @csrf
            @if(isset($athlete))
                @method('PUT')
            @endif

            {{-- Stammdaten --}}
            <div
                class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4 mb-4">
                <div class="flex items-center justify-between">
                    <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">Stammdaten</h2>
                    {{-- Aktiv-Schalter --}}
                    <label class="flex items-center gap-2 cursor-pointer">
                        <span class="text-sm text-zinc-600 dark:text-zinc-400">Aktiver Schwimmer</span>
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1"
                               @checked(old('is_active', $athlete->is_active ?? true))
                               class="rounded border-zinc-300 dark:border-zinc-600 text-blue-600">
                    </label>
                </div>

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
                        <flux:select name="gender" required>
                            <option value="M" @selected(old('gender', $athlete->gender ?? 'M') === 'M')>Männlich
                            </option>
                            <option value="F" @selected(old('gender', $athlete->gender ?? '') === 'F')>Weiblich</option>
                            <option value="N" @selected(old('gender', $athlete->gender ?? '') === 'N')>Nicht binär
                            </option>
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
                        <flux:select name="nation_id" required>
                            <option value="">Bitte wählen…</option>
                            @foreach($nations as $nation)
                                <option
                                    value="{{ $nation->id }}" @selected(old('nation_id', $athlete->nation_id ?? '') == $nation->id)>
                                    {{ $nation->code }} – {{ $nation->name_de }}
                                </option>
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
                        <flux:select name="club_id">
                            <option value="">Kein Verein</option>
                            @foreach($clubs as $club)
                                <option
                                    value="{{ $club->id }}" @selected(old('club_id', $athlete->club_id ?? '') == $club->id)>
                                    {{ $club->display_name }} ({{ $club->nation?->code }})
                                </option>
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
                        <flux:select name="status">
                            <option value="">Normal</option>
                            <option
                                value="EXHIBITION" @selected(old('status', $athlete->status ?? '') === 'EXHIBITION')>
                                Exhibition
                            </option>
                            <option
                                value="FOREIGNER" @selected(old('status', $athlete->status ?? '') === 'FOREIGNER')>
                                Ausländer
                            </option>
                            <option value="ROOKIE" @selected(old('status', $athlete->status ?? '') === 'ROOKIE')>
                                Rookie
                            </option>
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
                        <flux:select name="disability_type">
                            <option value="">Nicht angegeben</option>
                            <option
                                value="physical" @selected(old('disability_type', $athlete->disability_type ?? '') === 'physical')>
                                Körperlich
                            </option>
                            <option
                                value="visual" @selected(old('disability_type', $athlete->disability_type ?? '') === 'visual')>
                                Sehbehinderung
                            </option>
                            <option
                                value="intellectual" @selected(old('disability_type', $athlete->disability_type ?? '') === 'intellectual')>
                                Intellektuell
                            </option>
                            <option
                                value="deaf" @selected(old('disability_type', $athlete->disability_type ?? '') === 'deaf')>
                                Hörbehinderung
                            </option>
                            <option
                                value="trisomie" @selected(old('disability_type', $athlete->disability_type ?? '') === 'trisomie')>
                                Down Syndrom
                            </option>
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

            {{-- Kontakt & Adresse --}}
            <div
                class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4 mb-4">
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
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 mb-4">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-4">Notizen</h2>
                <flux:field>
                    <flux:textarea name="notes" rows="4"
                                   placeholder="Interne Notizen zum Athleten …">{{ old('notes', $athlete->notes ?? '') }}</flux:textarea>
                    <flux:error name="notes"/>
                </flux:field>
            </div>

            {{-- Sportklassen und WPS Exceptions werden nicht mehr hier gepflegt, sondern ausschließlich
                 über "Klassifikation eintragen" in der Detailansicht (athletes.show) — das ist der
                 einzige Weg, der auch die Klassifikations-History korrekt fortschreibt. Nach dem
                 Anlegen eines neuen Athleten geht's per Redirect direkt dorthin, siehe
                 AthleteController::store(). --}}

            <div class="flex gap-3">
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
