@extends('layouts.app')

@section('title', $athlete->display_name)

@section('content')

    <div class="flex items-start justify-between mb-6">
        <div class="flex items-center gap-3">
            <flux:button href="{{ route('athletes.index') }}" variant="ghost" icon="arrow-left" size="sm"/>
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $athlete->full_name }}</h1>
                    @if(!$athlete->is_active)
                        <flux:badge color="zinc">Inaktiv</flux:badge>
                    @endif
                    @if($athlete->level)
                        <flux:badge color="blue">Level: {{ $athlete->level }}</flux:badge>
                    @endif
                </div>
                <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                    {{ match($athlete->gender) { 'M' => 'Herr', 'F' => 'Dame', default => 'Nicht binär' } }}
                    @if($athlete->birth_date) · *{{ $athlete->birth_date->format('d.m.Y') }} @endif
                    · {{ $athlete->nation?->code }}
                </p>
            </div>
        </div>
        <flux:button href="{{ route('athletes.edit', $athlete) }}" variant="ghost" icon="pencil" size="sm">
            Bearbeiten
        </flux:button>
    </div>

    <div class="grid grid-cols-3 gap-6 mb-6">

        {{-- Stammdaten --}}
        <div class="col-span-2 bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-5">
            <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-4">Stammdaten</h2>
            <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Verein</dt>
                    <dd class="font-medium mt-0.5">
                        @if($athlete->club)
                            <a href="{{ route('clubs.show', $athlete->club) }}" class="hover:text-blue-600 transition-colors">
                                {{ $athlete->club->display_name }}
                            </a>
                        @else
                            <span class="text-zinc-400">–</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Nation</dt>
                    <dd class="font-medium mt-0.5">{{ $athlete->nation?->name_de ?? '–' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Lizenznummer</dt>
                    <dd class="font-medium mt-0.5 font-mono">{{ $athlete->license ?? '–' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">SDMS ID</dt>
                    <dd class="font-medium mt-0.5 font-mono">{{ $athlete->license_ipc ?? '–' }}</dd>
                </div>
                @if($athlete->disability_type)
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">Behinderungsart</dt>
                        <dd class="font-medium mt-0.5">{{ ucfirst($athlete->disability_type) }}</dd>
                    </div>
                @endif
                @if($athlete->email)
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">E-Mail</dt>
                        <dd class="font-medium mt-0.5">
                            <a href="mailto:{{ $athlete->email }}" class="hover:text-blue-600 transition-colors">
                                {{ $athlete->email }}
                            </a>
                        </dd>
                    </div>
                @endif
                @if($athlete->phone)
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">Telefon</dt>
                        <dd class="font-medium mt-0.5">{{ $athlete->phone }}</dd>
                    </div>
                @endif
                @if($athlete->address_street || $athlete->address_city)
                    <div class="col-span-2">
                        <dt class="text-zinc-500 dark:text-zinc-400">Adresse</dt>
                        <dd class="font-medium mt-0.5">
                            {{ $athlete->address_street }}
                            @if($athlete->address_street && $athlete->address_city), @endif
                            {{ $athlete->address_zip }} {{ $athlete->address_city }}
                            @if($athlete->address_country) · {{ $athlete->address_country }} @endif
                        </dd>
                    </div>
                @endif
            </dl>

            @if($athlete->notes)
                <div class="mt-4 pt-4 border-t border-zinc-100 dark:border-zinc-700">
                    <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide mb-1">Notizen</dt>
                    <p class="text-sm text-zinc-700 dark:text-zinc-300 whitespace-pre-line">{{ $athlete->notes }}</p>
                </div>
            @endif
        </div>

        {{-- Sport-Klassen --}}
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-5">
            <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-4">Sport-Klassen</h2>
            @forelse($athlete->sportClasses as $sc)
                <div class="py-2 border-b border-zinc-100 dark:border-zinc-700 last:border-0">
                    <div class="flex items-center justify-between">
                        <span class="font-mono font-bold text-lg text-zinc-900 dark:text-zinc-100">
                            {{ $sc->sport_class }}
                        </span>
                        <div class="flex items-center gap-1.5">
                            {{-- Scope: INTL oder NAT --}}
                            <flux:badge size="sm" color="{{ $sc->classification_scope === 'INTL' ? 'violet' : 'zinc' }}">
                                {{ $sc->classification_scope }}
                            </flux:badge>
                            {{-- Status inkl. FRD-Jahr --}}
                            @if($sc->classification_status)
                                <flux:badge size="sm" color="{{ $sc->status_color }}">
                                    {{ $sc->status_label }}
                                </flux:badge>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <p class="text-sm text-zinc-400">Keine Klassen zugeordnet.</p>
            @endforelse

            @if($athlete->exceptions->isNotEmpty())
                <h3 class="font-medium text-zinc-700 dark:text-zinc-300 mt-4 mb-2 text-sm">Exceptions</h3>
                <div class="flex flex-wrap gap-1">
                    @foreach($athlete->exceptions as $exc)
                        <flux:badge size="sm" color="zinc" class="font-mono">{{ $exc->code }}</flux:badge>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════════════
         VEREINS-HISTORY & UMMELDUNG
    ════════════════════════════════════════════════════════════════════════ --}}
    <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-5 mb-6"
         x-data="{ openTransfer: false }">

        <div class="flex items-center justify-between mb-4">
            <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">Vereins-History</h2>
            <flux:button size="sm" variant="ghost" icon="arrows-right-left"
                         x-on:click="openTransfer = !openTransfer">
                Ummeldung
            </flux:button>
        </div>

        {{-- Ummeldungs-Formular (Alpine toggle) --}}
        <div x-show="openTransfer" x-cloak
             class="mb-5 p-4 bg-zinc-50 dark:bg-zinc-700/40 rounded-lg border border-zinc-200 dark:border-zinc-600">
            <h3 class="text-sm font-semibold text-zinc-800 dark:text-zinc-200 mb-3">Vereinswechsel eintragen</h3>
            <form method="POST" action="{{ route('athletes.transfer-club', $athlete) }}">
                @csrf
                <div class="grid grid-cols-3 gap-3">
                    <flux:field class="col-span-2">
                        <flux:label>Neuer Verein *</flux:label>
                        <flux:select name="club_id" required>
                            <option value="">Bitte wählen…</option>
                            @foreach($clubs as $club)
                                <option value="{{ $club->id }}" @selected($club->id === $athlete->club_id)>
                                    {{ $club->display_name }} ({{ $club->nation?->code }})
                                </option>
                            @endforeach
                        </flux:select>
                        <flux:error name="club_id"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Datum *</flux:label>
                        <flux:input name="joined_at" type="date"
                                    value="{{ old('joined_at', today()->format('Y-m-d')) }}" required/>
                        <flux:error name="joined_at"/>
                    </flux:field>
                </div>
                <flux:field class="mt-3">
                    <flux:label>Bemerkung</flux:label>
                    <flux:input name="notes" value="{{ old('notes') }}" placeholder="Grund der Ummeldung…"/>
                </flux:field>
                <div class="flex gap-2 mt-3">
                    <flux:button type="submit" variant="primary" size="sm">Ummeldung speichern</flux:button>
                    <flux:button type="button" variant="ghost" size="sm" x-on:click="openTransfer = false">Abbrechen</flux:button>
                </div>
            </form>
        </div>

        {{-- History-Tabelle --}}
        @if($athlete->clubHistory->isNotEmpty())
            <div class="divide-y divide-zinc-100 dark:divide-zinc-700">
                @foreach($athlete->clubHistory->sortByDesc('joined_at') as $entry)
                    <div class="flex items-center justify-between py-2.5 text-sm">
                        <div class="flex items-center gap-3">
                            @if($entry->is_active)
                                <flux:badge size="sm" color="emerald">Aktuell</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc">Ehemalig</flux:badge>
                            @endif
                            <span class="font-medium text-zinc-900 dark:text-zinc-100">
                                {{ $entry->club?->display_name ?? '–' }}
                            </span>
                            @if($entry->notes)
                                <span class="text-zinc-400 text-xs">· {{ $entry->notes }}</span>
                            @endif
                        </div>
                        <div class="text-zinc-500 dark:text-zinc-400 text-xs text-right">
                            {{ $entry->joined_at->format('d.m.Y') }}
                            @if($entry->left_at) – {{ $entry->left_at->format('d.m.Y') }}
                            @elseif($entry->is_active) – heute
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-sm text-zinc-400">Noch keine Vereinshistory vorhanden.</p>
        @endif
    </div>

    {{-- ═══════════════════════════════════════════════════════════════════════
         KLASSIFIKATIONS-HISTORY
    ════════════════════════════════════════════════════════════════════════ --}}
    <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-5 mb-6"
         x-data="{ openClassification: false }">

        <div class="flex items-center justify-between mb-4">
            <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">Klassifikations-History</h2>
            <flux:button size="sm" variant="ghost" icon="plus"
                         x-on:click="openClassification = !openClassification">
                Neue Klassifikation
            </flux:button>
        </div>

        {{-- Neues Klassifikations-Formular --}}
        <div x-show="openClassification" x-cloak
             class="mb-5 p-4 bg-zinc-50 dark:bg-zinc-700/40 rounded-lg border border-zinc-200 dark:border-zinc-600">
            <h3 class="text-sm font-semibold text-zinc-800 dark:text-zinc-200 mb-3">Klassifikation eintragen</h3>
            @php $defaultScope = $athlete->license_ipc ? 'INTL' : 'NAT'; @endphp
            <form method="POST" action="{{ route('athletes.classifications.store', $athlete) }}">
                @csrf
                <div class="grid grid-cols-2 gap-3">
                    <flux:field>
                        <flux:label>Datum *</flux:label>
                        <flux:input name="classified_at" type="date"
                                    value="{{ old('classified_at', today()->format('Y-m-d')) }}" required/>
                        <flux:error name="classified_at"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Ort</flux:label>
                        <flux:input name="location" value="{{ old('location') }}" placeholder="Stadt / Veranstaltungsort"/>
                        <flux:error name="location"/>
                    </flux:field>
                </div>

                {{-- Scope + Status + FRD-Jahr --}}
                <div class="grid grid-cols-3 gap-3 mt-3"
                     x-data="{ status: @js(old('classification_status', '')) }">
                    <flux:field>
                        <flux:label>Gültigkeit *</flux:label>
                        <flux:select name="classification_scope">
                            <option value="INTL" @selected(old('classification_scope', $defaultScope) === 'INTL')>
                                🌍 International (SDMS)
                            </option>
                            <option value="NAT"  @selected(old('classification_scope', $defaultScope) === 'NAT')>
                                🇦🇹 Nur national (ÖBSV)
                            </option>
                        </flux:select>
                        <flux:error name="classification_scope"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Status</flux:label>
                        <flux:select name="classification_status" x-model="status">
                            <option value="">–</option>
                            <option value="NEW"       @selected(old('classification_status') === 'NEW')>New</option>
                            <option value="CONFIRMED" @selected(old('classification_status') === 'CONFIRMED')>Confirmed</option>
                            <option value="REVIEW"    @selected(old('classification_status') === 'REVIEW')>Review</option>
                            <option value="FRD"       @selected(old('classification_status') === 'FRD')>Fixed Review Date (FRD)</option>
                            <option value="NE"        @selected(old('classification_status') === 'NE')>Not Eligible (NE)</option>
                        </flux:select>
                        <flux:error name="classification_status"/>
                    </flux:field>
                    <flux:field x-show="status === 'FRD'" x-cloak>
                        @php $frdDefault = (int) date('Y') + 2; @endphp
                        <flux:label>FRD Jahr *</flux:label>
                        <flux:input name="frd_year" type="number" min="2000" max="2100"
                                    value="{{ old('frd_year', $frdDefault) }}"
                                    placeholder="{{ $frdDefault }}"/>
                        <flux:error name="frd_year"/>
                    </flux:field>
                </div>

                <div class="grid grid-cols-3 gap-3 mt-3">
                    <flux:field>
                        <flux:label>Med. Klassifizierer</flux:label>
                        <flux:select name="med_classifier_id">
                            <option value="">–</option>
                            @foreach($medClassifiers as $c)
                                <option value="{{ $c->id }}" @selected(old('med_classifier_id') == $c->id)>
                                    {{ $c->full_name }}
                                </option>
                            @endforeach
                        </flux:select>
                    </flux:field>
                    <flux:field>
                        <flux:label>Tech. Klassifizierer 1</flux:label>
                        <flux:select name="tech1_classifier_id">
                            <option value="">–</option>
                            @foreach($techClassifiers as $c)
                                <option value="{{ $c->id }}" @selected(old('tech1_classifier_id') == $c->id)>
                                    {{ $c->full_name }}
                                </option>
                            @endforeach
                        </flux:select>
                    </flux:field>
                    <flux:field>
                        <flux:label>Tech. Klassifizierer 2</flux:label>
                        <flux:select name="tech2_classifier_id">
                            <option value="">–</option>
                            @foreach($techClassifiers as $c)
                                <option value="{{ $c->id }}" @selected(old('tech2_classifier_id') == $c->id)>
                                    {{ $c->full_name }}
                                </option>
                            @endforeach
                        </flux:select>
                    </flux:field>
                </div>
                <div class="mt-3">
                    <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Ergebnis Sportklassen</p>
                    <div class="grid grid-cols-3 gap-3">
                        <flux:field>
                            <flux:label>S (Freistil/Rücken/Fly)</flux:label>
                            <flux:input name="result_s" value="{{ old('result_s') }}" placeholder="z.B. 4"/>
                            <flux:error name="result_s"/>
                        </flux:field>
                        <flux:field>
                            <flux:label>SB (Brust)</flux:label>
                            <flux:input name="result_sb" value="{{ old('result_sb') }}" placeholder="z.B. 3"/>
                            <flux:error name="result_sb"/>
                        </flux:field>
                        <flux:field>
                            <flux:label>SM (Lagen)</flux:label>
                            <flux:input name="result_sm" value="{{ old('result_sm') }}" placeholder="z.B. 4"/>
                            <flux:error name="result_sm"/>
                        </flux:field>
                    </div>
                    <flux:description>Leer lassen wenn der Athlet diese Kategorie nicht schwimmt.</flux:description>
                </div>

                {{-- Exceptions --}}
                <div class="mt-3">
                    <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">WPS Exceptions</p>
                    <div class="space-y-1 max-h-48 overflow-y-auto pr-1">
                        @foreach($exceptionCodes as $code)
                            <div class="flex items-center gap-3 py-1.5 border-b border-zinc-100 dark:border-zinc-700 last:border-0">
                                <input type="checkbox"
                                       name="exceptions[{{ $loop->index }}][code_id]"
                                       value="{{ $code->id }}"
                                       id="new_exc_{{ $code->id }}"
                                       class="rounded border-zinc-300 dark:border-zinc-600 text-blue-600">
                                <label for="new_exc_{{ $code->id }}"
                                       class="flex-1 text-xs font-mono font-bold text-zinc-900 dark:text-zinc-100 cursor-pointer">
                                    {{ $code->code }}
                                    <span class="font-sans font-normal text-zinc-500 dark:text-zinc-400 ml-1">{{ $code->name_de }}</span>
                                </label>
                                <flux:select name="exceptions[{{ $loop->index }}][category]" class="w-24 text-xs">
                                    <option value="">–</option>
                                    <option value="S">S</option>
                                    <option value="SB">SB</option>
                                    <option value="SM">SM</option>
                                </flux:select>
                            </div>
                        @endforeach
                    </div>
                </div>
                <flux:field class="mt-3">
                    <flux:label>Notizen</flux:label>
                    <flux:textarea name="notes" rows="2">{{ old('notes') }}</flux:textarea>
                </flux:field>
                <div class="flex gap-2 mt-3">
                    <flux:button type="submit" variant="primary" size="sm">Speichern</flux:button>
                    <flux:button type="button" variant="ghost" size="sm" x-on:click="openClassification = false">Abbrechen</flux:button>
                </div>
            </form>
        </div>

        {{-- Klassifikations-Liste --}}
        @if($athlete->classifications->isNotEmpty())
            <div class="divide-y divide-zinc-100 dark:divide-zinc-700">
                @foreach($athlete->classifications as $cl)
                    <div class="py-3 text-sm" x-data="{ editing: false }">

                        {{-- Anzeigemodus --}}
                        <div x-show="!editing">
                            <div class="flex items-start justify-between">
                                <div>
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="font-medium text-zinc-900 dark:text-zinc-100">
                                            {{ $cl->classified_at->format('d.m.Y') }}
                                        </span>
                                        @if($cl->location)
                                            <span class="text-zinc-500">· {{ $cl->location }}</span>
                                        @endif
                                        @if($cl->result_s || $cl->result_sb || $cl->result_sm)
                                            <flux:badge size="sm" color="blue">{{ $cl->sport_class_results_display }}</flux:badge>
                                        @endif
                                        {{-- Scope-Badge: INTL oder NAT --}}
                                        <flux:badge size="sm" color="{{ $cl->classification_scope === 'INTL' ? 'violet' : 'zinc' }}">
                                            {{ $cl->classification_scope }}
                                        </flux:badge>
                                        {{-- Status inkl. FRD-Jahr --}}
                                        @if($cl->classification_status)
                                            <flux:badge size="sm" color="{{ $cl->status_color }}">
                                                {{ $cl->status_label }}
                                            </flux:badge>
                                        @endif
                                    </div>
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400 space-x-3">
                                        @if($cl->medClassifier)
                                            <span>
                                                <span class="font-medium text-zinc-600 dark:text-zinc-300">Med:</span>
                                                {{ $cl->medClassifier->full_name }}
                                            </span>
                                        @endif
                                        @if($cl->tech1Classifier)
                                            <span>
                                                <span class="font-medium text-zinc-600 dark:text-zinc-300">Tech 1:</span>
                                                {{ $cl->tech1Classifier->full_name }}
                                            </span>
                                        @endif
                                        @if($cl->tech2Classifier)
                                            <span>
                                                <span class="font-medium text-zinc-600 dark:text-zinc-300">Tech 2:</span>
                                                {{ $cl->tech2Classifier->full_name }}
                                            </span>
                                        @endif
                                    </div>
                                    @if($cl->exceptions->isNotEmpty())
                                        <div class="flex flex-wrap gap-1 mt-1">
                                            @foreach($cl->exceptions as $exc)
                                                <flux:badge size="sm" color="zinc" class="font-mono">
                                                    {{ $exc->code }}
                                                    @if($exc->pivot->category)
                                                        <span class="opacity-60">/{{ $exc->pivot->category }}</span>
                                                    @endif
                                                </flux:badge>
                                            @endforeach
                                        </div>
                                    @endif
                                    @if($cl->notes)
                                        <p class="text-xs text-zinc-400 mt-1">{{ $cl->notes }}</p>
                                    @endif
                                </div>
                                <div class="flex items-center gap-1 shrink-0">
                                    <flux:button size="sm" variant="ghost" icon="pencil"
                                                 x-on:click="editing = true"/>
                                    <form method="POST"
                                          action="{{ route('athletes.classifications.destroy', [$athlete, $cl]) }}"
                                          x-data="{ del() { if(confirm('Klassifikation löschen?')) this.$el.submit() } }"
                                          @submit.prevent="del()">
                                        @csrf @method('DELETE')
                                        <flux:button type="submit" size="sm" variant="ghost" icon="trash"
                                                     class="text-red-400"/>
                                    </form>
                                </div>
                            </div>
                        </div>

                        {{-- Bearbeitungsmodus --}}
                        <div x-show="editing" x-cloak
                             class="p-4 bg-zinc-50 dark:bg-zinc-700/40 rounded-lg border border-zinc-200 dark:border-zinc-600">
                            <h4 class="text-sm font-semibold text-zinc-800 dark:text-zinc-200 mb-3">Klassifikation bearbeiten</h4>
                            <form method="POST"
                                  action="{{ route('athletes.classifications.update', [$athlete, $cl]) }}">
                                @csrf @method('PUT')
                                <div class="grid grid-cols-2 gap-3">
                                    <flux:field>
                                        <flux:label>Datum *</flux:label>
                                        <flux:input name="classified_at" type="date"
                                                    value="{{ $cl->classified_at->format('Y-m-d') }}" required/>
                                        <flux:error name="classified_at"/>
                                    </flux:field>
                                    <flux:field>
                                        <flux:label>Ort</flux:label>
                                        <flux:input name="location" value="{{ $cl->location }}"
                                                    placeholder="Stadt / Veranstaltungsort"/>
                                    </flux:field>
                                </div>

                                {{-- Scope + Status + FRD --}}
                                <div class="grid grid-cols-3 gap-3 mt-3"
                                     x-data="{ status: @js($cl->classification_status ?? '') }">
                                    <flux:field>
                                        <flux:label>Gültigkeit *</flux:label>
                                        <flux:select name="classification_scope">
                                            <option value="INTL" @selected($cl->classification_scope === 'INTL')>
                                                🌍 International (SDMS)
                                            </option>
                                            <option value="NAT"  @selected($cl->classification_scope === 'NAT')>
                                                🇦🇹 Nur national (ÖBSV)
                                            </option>
                                        </flux:select>
                                        <flux:error name="classification_scope"/>
                                    </flux:field>
                                    <flux:field>
                                        <flux:label>Status</flux:label>
                                        <flux:select name="classification_status" x-model="status">
                                            <option value="">–</option>
                                            <option value="NEW"       @selected($cl->classification_status === 'NEW')>New</option>
                                            <option value="CONFIRMED" @selected($cl->classification_status === 'CONFIRMED')>Confirmed</option>
                                            <option value="REVIEW"    @selected($cl->classification_status === 'REVIEW')>Review</option>
                                            <option value="FRD"       @selected($cl->classification_status === 'FRD')>Fixed Review Date (FRD)</option>
                                            <option value="NE"        @selected($cl->classification_status === 'NE')>Not Eligible (NE)</option>
                                        </flux:select>
                                        <flux:error name="classification_status"/>
                                    </flux:field>
                                    <flux:field x-show="status === 'FRD'" x-cloak>
                                        <flux:label>FRD Jahr *</flux:label>
                                        <flux:input name="frd_year" type="number" min="2000" max="2100"
                                                    value="{{ $cl->frd_year }}"/>
                                        <flux:error name="frd_year"/>
                                    </flux:field>
                                </div>

                                <div class="grid grid-cols-3 gap-3 mt-3">
                                    <flux:field>
                                        <flux:label>Med. Klassifizierer</flux:label>
                                        <flux:select name="med_classifier_id">
                                            <option value="">–</option>
                                            @foreach($medClassifiers as $c)
                                                <option value="{{ $c->id }}" @selected($cl->med_classifier_id == $c->id)>
                                                    {{ $c->full_name }}
                                                </option>
                                            @endforeach
                                        </flux:select>
                                    </flux:field>
                                    <flux:field>
                                        <flux:label>Tech. Klassifizierer 1</flux:label>
                                        <flux:select name="tech1_classifier_id">
                                            <option value="">–</option>
                                            @foreach($techClassifiers as $c)
                                                <option value="{{ $c->id }}" @selected($cl->tech1_classifier_id == $c->id)>
                                                    {{ $c->full_name }}
                                                </option>
                                            @endforeach
                                        </flux:select>
                                    </flux:field>
                                    <flux:field>
                                        <flux:label>Tech. Klassifizierer 2</flux:label>
                                        <flux:select name="tech2_classifier_id">
                                            <option value="">–</option>
                                            @foreach($techClassifiers as $c)
                                                <option value="{{ $c->id }}" @selected($cl->tech2_classifier_id == $c->id)>
                                                    {{ $c->full_name }}
                                                </option>
                                            @endforeach
                                        </flux:select>
                                    </flux:field>
                                </div>
                                <div class="mt-3">
                                    <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Ergebnis Sportklassen</p>
                                    <div class="grid grid-cols-3 gap-3">
                                        <flux:field>
                                            <flux:label>S (Freistil/Rücken/Fly)</flux:label>
                                            <flux:input name="result_s" value="{{ $cl->result_s }}" placeholder="z.B. 4"/>
                                            <flux:error name="result_s"/>
                                        </flux:field>
                                        <flux:field>
                                            <flux:label>SB (Brust)</flux:label>
                                            <flux:input name="result_sb" value="{{ $cl->result_sb }}" placeholder="z.B. 3"/>
                                            <flux:error name="result_sb"/>
                                        </flux:field>
                                        <flux:field>
                                            <flux:label>SM (Lagen)</flux:label>
                                            <flux:input name="result_sm" value="{{ $cl->result_sm }}" placeholder="z.B. 4"/>
                                            <flux:error name="result_sm"/>
                                        </flux:field>
                                    </div>
                                    <flux:description>Leer lassen wenn der Athlet diese Kategorie nicht schwimmt.</flux:description>
                                </div>

                                {{-- Exceptions --}}
                                <div class="mt-3">
                                    <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">WPS Exceptions</p>
                                    <div class="space-y-1 max-h-48 overflow-y-auto pr-1">
                                        @foreach($exceptionCodes as $code)
                                            @php $excExisting = $cl->exceptions->firstWhere('id', $code->id); @endphp
                                            <div class="flex items-center gap-3 py-1.5 border-b border-zinc-100 dark:border-zinc-700 last:border-0">
                                                <input type="checkbox"
                                                       name="exceptions[{{ $loop->index }}][code_id]"
                                                       value="{{ $code->id }}"
                                                       id="edit_exc_{{ $cl->id }}_{{ $code->id }}"
                                                       @checked($excExisting !== null)
                                                       class="rounded border-zinc-300 dark:border-zinc-600 text-blue-600">
                                                <label for="edit_exc_{{ $cl->id }}_{{ $code->id }}"
                                                       class="flex-1 text-xs font-mono font-bold text-zinc-900 dark:text-zinc-100 cursor-pointer">
                                                    {{ $code->code }}
                                                    <span class="font-sans font-normal text-zinc-500 dark:text-zinc-400 ml-1">{{ $code->name_de }}</span>
                                                </label>
                                                <flux:select name="exceptions[{{ $loop->index }}][category]" class="w-24 text-xs">
                                                    <option value="">–</option>
                                                    <option value="S"  @selected($excExisting?->pivot?->category === 'S')>S</option>
                                                    <option value="SB" @selected($excExisting?->pivot?->category === 'SB')>SB</option>
                                                    <option value="SM" @selected($excExisting?->pivot?->category === 'SM')>SM</option>
                                                </flux:select>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                                <flux:field class="mt-3">
                                    <flux:label>Notizen</flux:label>
                                    <flux:textarea name="notes" rows="2">{{ $cl->notes }}</flux:textarea>
                                </flux:field>
                                <div class="flex gap-2 mt-3">
                                    <flux:button type="submit" variant="primary" size="sm">Speichern</flux:button>
                                    <flux:button type="button" variant="ghost" size="sm"
                                                 x-on:click="editing = false">Abbrechen</flux:button>
                                </div>
                            </form>
                        </div>

                    </div>
                @endforeach
            </div>
        @else
            <p class="text-sm text-zinc-400">Noch keine Klassifikationen eingetragen.</p>
        @endif
    </div>

    {{-- ═══════════════════════════════════════════════════════════════════════
         LEVEL-HISTORY
    ════════════════════════════════════════════════════════════════════════ --}}
    <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-5 mb-6"
         x-data="{ openLevel: false }">

        <div class="flex items-center justify-between mb-4">
            <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">
                Level-History
                @if($athlete->level)
                    <span class="text-zinc-500 font-normal text-sm ml-1">— aktuell: <strong class="text-zinc-800 dark:text-zinc-200">{{ $athlete->level }}</strong></span>
                @endif
            </h2>
            <flux:button size="sm" variant="ghost" icon="plus"
                         x-on:click="openLevel = !openLevel">
                Level ändern
            </flux:button>
        </div>

        {{-- Level-Formular --}}
        <div x-show="openLevel" x-cloak
             class="mb-5 p-4 bg-zinc-50 dark:bg-zinc-700/40 rounded-lg border border-zinc-200 dark:border-zinc-600">
            <h3 class="text-sm font-semibold text-zinc-800 dark:text-zinc-200 mb-3">Neuen Level eintragen</h3>
            <form method="POST" action="{{ route('athletes.levels.store', $athlete) }}">
                @csrf
                <div class="grid grid-cols-2 gap-3">
                    <flux:field>
                        <flux:label>Neuer Level *</flux:label>
                        <flux:input name="level" value="{{ old('level') }}"
                                    placeholder="z.B. Elite, Talent, 1, 2 …" required/>
                        <flux:error name="level"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Datum *</flux:label>
                        <flux:input name="changed_at" type="date"
                                    value="{{ old('changed_at', today()->format('Y-m-d')) }}" required/>
                        <flux:error name="changed_at"/>
                    </flux:field>
                </div>
                <flux:field class="mt-3">
                    <flux:label>Bemerkung</flux:label>
                    <flux:input name="notes" value="{{ old('notes') }}" placeholder="Begründung…"/>
                </flux:field>
                <div class="flex gap-2 mt-3">
                    <flux:button type="submit" variant="primary" size="sm">Speichern</flux:button>
                    <flux:button type="button" variant="ghost" size="sm" x-on:click="openLevel = false">Abbrechen</flux:button>
                </div>
            </form>
        </div>

        {{-- Level-Tabelle --}}
        @if($athlete->levelHistory->isNotEmpty())
            <div class="divide-y divide-zinc-100 dark:divide-zinc-700">
                @foreach($athlete->levelHistory as $lh)
                    <div class="flex items-center justify-between py-2.5 text-sm">
                        <div class="flex items-center gap-3">
                            <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $lh->level }}</span>
                            @if($lh->previous_level)
                                <span class="text-xs text-zinc-400">
                                    (vorher: {{ $lh->previous_level }})
                                </span>
                            @endif
                            @if($lh->notes)
                                <span class="text-xs text-zinc-400">· {{ $lh->notes }}</span>
                            @endif
                        </div>
                        <div class="text-right text-xs text-zinc-500 dark:text-zinc-400">
                            <div>{{ $lh->changed_at->format('d.m.Y') }}</div>
                            @if($lh->user)
                                <div class="text-zinc-400">{{ $lh->user->name }}</div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-sm text-zinc-400">Noch keine Level-Einträge vorhanden.</p>
        @endif
    </div>

    {{-- ═══════════════════════════════════════════════════════════════════════
         ERGEBNISSE
    ════════════════════════════════════════════════════════════════════════ --}}
    <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100 mb-3">Ergebnisse</h2>

    <flux:table class="[&_td:first-child]:ps-4 [&_th:first-child]:ps-4 [&_td:last-child]:pe-4 [&_th:last-child]:pe-4">
        <flux:table.columns>
            <flux:table.column>Wettkampf</flux:table.column>
            <flux:table.column>Disziplin</flux:table.column>
            <flux:table.column>Klasse</flux:table.column>
            <flux:table.column>Zeit</flux:table.column>
            <flux:table.column>Platz</flux:table.column>
            <flux:table.column>Rekorde</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse($results as $result)
                <flux:table.row>
                    <flux:table.cell class="text-sm">
                        <a href="{{ route('meets.show', $result->meet) }}" class="hover:text-blue-600 transition-colors">
                            {{ $result->meet?->name }}
                        </a>
                        <div class="text-xs text-zinc-400">{{ $result->meet?->start_date?->format('d.m.Y') }}</div>
                    </flux:table.cell>
                    <flux:table.cell class="text-sm">{{ $result->swimEvent?->display_name }}</flux:table.cell>
                    <flux:table.cell>
                        @if($result->sport_class)
                            <flux:badge size="sm" color="blue">{{ $result->sport_class }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="font-mono font-medium">
                        @if($result->status)
                            <flux:badge size="sm" color="red">{{ $result->status }}</flux:badge>
                        @else
                            {{ $result->formatted_swim_time }}
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="text-sm text-zinc-500">
                        {{ $result->place ? $result->place . '.' : '–' }}
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex gap-1">
                            @if($result->is_world_record)    <flux:badge size="sm" color="yellow">WR</flux:badge> @endif
                            @if($result->is_european_record) <flux:badge size="sm" color="blue">ER</flux:badge> @endif
                            @if($result->is_national_record) <flux:badge size="sm" color="emerald">NR</flux:badge> @endif
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="text-center py-8 text-zinc-400">
                        Noch keine Ergebnisse vorhanden.
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <div class="mt-4">{{ $results->links() }}</div>

@endsection
