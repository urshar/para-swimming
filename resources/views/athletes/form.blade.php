@extends('layouts.app')

@section('title', isset($athlete) ? $athlete->display_name . ' bearbeiten' : 'Neuer Athlet')

@section('content')
    <div class="max-w-3xl">

        <div class="flex items-center gap-3 mb-6">
            <flux:button href="{{ route('athletes.index') }}" variant="ghost" icon="arrow-left" size="sm"/>
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
                        <flux:input name="birth_date" type="date"
                                    value="{{ old('birth_date', isset($athlete) && $athlete->birth_date ? $athlete->birth_date->format('Y-m-d') : '') }}"/>
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

                <div class="grid grid-cols-2 gap-4">
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
                            <flux:input name="club_joined_at" type="date"
                                        value="{{ old('club_joined_at', today()->format('Y-m-d')) }}"/>
                            <flux:description>Datum des Vereinsbeitritts</flux:description>
                            <flux:error name="club_joined_at"/>
                        </flux:field>
                    @else
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
                    @endif
                </div>

                @if(!isset($athlete))
                    <div class="grid grid-cols-2 gap-4">
                        <div></div>
                        <flux:field>
                            <flux:label>Status</flux:label>
                            <flux:select name="status">
                                <option value="">Normal</option>
                                <option value="EXHIBITION" @selected(old('status') === 'EXHIBITION')>Exhibition</option>
                                <option value="FOREIGNER" @selected(old('status') === 'FOREIGNER')>Ausländer</option>
                                <option value="ROOKIE" @selected(old('status') === 'ROOKIE')>Rookie</option>
                            </flux:select>
                            <flux:error name="status"/>
                        </flux:field>
                    </div>
                @endif

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

                <div class="grid grid-cols-2 gap-4">
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
                    <flux:field>
                        <flux:label>ÖBSV Level</flux:label>
                        <flux:input name="level" value="{{ old('level', $athlete->level ?? '') }}"
                                    placeholder="z.B. Elite, Talent, 1, 2 …"/>
                        <flux:description>Einstufung durch den ÖBSV — Änderungen werden protokolliert.
                        </flux:description>
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

            {{-- Sport-Klassen --}}
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 mb-4">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-4">Sport-Klassen</h2>

                @foreach([['S', 'S (Freistil / Rücken / Schmetterling)'], ['SB', 'SB (Brust)'], ['SM', 'SM (Lagen)']] as [$cat, $label])
                    @php
                        $existing   = isset($athlete) ? $athlete->sportClasses->firstWhere('category', $cat) : null;
                        $loop_index = $loop->index;
                        $defaultScope = isset($athlete) && $athlete->license_ipc ? 'INTL' : 'NAT';
                    @endphp
                    <div class="mb-4 pb-4 border-b border-zinc-100 dark:border-zinc-700 last:border-0"
                         x-data="{ status: @js(old('sport_classes.' . $loop_index . '.classification_status', $existing?->classification_status ?? '')) }">
                        <input type="hidden" name="sport_classes[{{ $loop_index }}][category]" value="{{ $cat }}">
                        <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">{{ $label }}</p>
                        <div class="grid grid-cols-4 gap-3">
                            <flux:field>
                                <flux:label>Klassen-Nr.</flux:label>
                                <flux:input
                                    name="sport_classes[{{ $loop_index }}][class_number]"
                                    value="{{ old('sport_classes.' . $loop_index . '.class_number', $existing?->class_number ?? '') }}"
                                    placeholder="z.B. 4"/>
                            </flux:field>
                            <flux:field>
                                <flux:label>Gültigkeit</flux:label>
                                <flux:select name="sport_classes[{{ $loop_index }}][classification_scope]">
                                    <option
                                        value="INTL" @selected(old('sport_classes.' . $loop_index . '.classification_scope', $existing?->classification_scope ?? $defaultScope) === 'INTL')>
                                        🌍 International
                                    </option>
                                    <option
                                        value="NAT" @selected(old('sport_classes.' . $loop_index . '.classification_scope', $existing?->classification_scope ?? $defaultScope) === 'NAT')>
                                        🇦🇹 National
                                    </option>
                                </flux:select>
                            </flux:field>
                            <flux:field>
                                <flux:label>Status</flux:label>
                                <flux:select name="sport_classes[{{ $loop_index }}][classification_status]"
                                             x-model="status">
                                    <option value="">–</option>
                                    <option
                                        value="NEW" @selected(old('sport_classes.' . $loop_index . '.classification_status', $existing?->classification_status ?? '') === 'NEW')>
                                        New
                                    </option>
                                    <option
                                        value="CONFIRMED" @selected(old('sport_classes.' . $loop_index . '.classification_status', $existing?->classification_status ?? '') === 'CONFIRMED')>
                                        Confirmed
                                    </option>
                                    <option
                                        value="REVIEW" @selected(old('sport_classes.' . $loop_index . '.classification_status', $existing?->classification_status ?? '') === 'REVIEW')>
                                        Review
                                    </option>
                                    <option
                                        value="FRD" @selected(old('sport_classes.' . $loop_index . '.classification_status', $existing?->classification_status ?? '') === 'FRD')>
                                        Fixed Review Date (FRD)
                                    </option>
                                    <option
                                        value="NE" @selected(old('sport_classes.' . $loop_index . '.classification_status', $existing?->classification_status ?? '') === 'NE')>
                                        Not Eligible (NE)
                                    </option>
                                </flux:select>
                            </flux:field>
                            <flux:field x-show="status === 'FRD'" x-cloak>
                                @php $frdDefault = (int) date('Y') + 2; @endphp
                                <flux:label>FRD Jahr</flux:label>
                                <flux:input name="sport_classes[{{ $loop_index }}][frd_year]"
                                            type="number" min="2000" max="2100"
                                            value="{{ old('sport_classes.' . $loop_index . '.frd_year', $existing?->frd_year ?? $frdDefault) }}"/>
                            </flux:field>
                        </div>
                    </div>
                @endforeach
                <flux:description>Klassen-Nr. leer lassen wenn nicht zutreffend.</flux:description>
            </div>

            {{-- Exceptions --}}
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 mb-6">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-4">WPS Exceptions</h2>
                <div class="space-y-2">
                    @foreach($exceptionCodes as $code)
                        @php
                            $existing = isset($athlete) ? $athlete->exceptions->firstWhere('id', $code->id) : null;
                            $checked  = $existing !== null;
                        @endphp
                        <div
                            class="flex items-start gap-3 py-2 border-b border-zinc-100 dark:border-zinc-700 last:border-0">
                            <input type="checkbox"
                                   name="exceptions[{{ $loop->index }}][code_id]"
                                   value="{{ $code->id }}"
                                   id="exc_{{ $code->id }}"
                                   @checked($checked)
                                   class="mt-1 rounded border-zinc-300 dark:border-zinc-600 text-blue-600">
                            <div class="flex-1">
                                <label for="exc_{{ $code->id }}"
                                       class="font-mono font-bold text-sm text-zinc-900 dark:text-zinc-100 cursor-pointer">
                                    {{ $code->code }}
                                </label>
                                <span class="text-sm text-zinc-600 dark:text-zinc-400 ml-2">{{ $code->name_de }}</span>
                                @if($code->applies_to)
                                    <flux:badge size="sm" color="zinc" class="ml-2">{{ $code->applies_to }}</flux:badge>
                                @endif
                            </div>
                            <flux:select name="exceptions[{{ $loop->index }}][category]" class="w-28">
                                <option value="">Allgemein</option>
                                <option value="S" @selected($existing?->pivot?->category === 'S')>S</option>
                                <option value="SB" @selected($existing?->pivot?->category === 'SB')>SB</option>
                                <option value="SM" @selected($existing?->pivot?->category === 'SM')>SM</option>
                            </flux:select>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="flex gap-3">
                <flux:button type="submit" variant="primary">
                    {{ isset($athlete) ? 'Speichern' : 'Athlet anlegen' }}
                </flux:button>
                <flux:button href="{{ isset($athlete) ? route('athletes.show', $athlete) : route('athletes.index') }}"
                             variant="ghost">
                    Abbrechen
                </flux:button>
            </div>

        </form>
    </div>
@endsection
