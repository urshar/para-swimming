@php
    use App\Models\Club;
    use App\Support\ListUrl;
@endphp

@extends('layouts.app')

@section('title', isset($club) ? 'Verein bearbeiten' : 'Verein anlegen')

@section('content')
    {{-- Vorbelegung Nation: AUT als häufigster Fall. --}}
    @php
        $autId = $nations->firstWhere('code', 'AUT')?->id;
        $types = ['CLUB' => 'Verein', 'NATIONALTEAM' => 'Nationalteam', 'REGIONALTEAM' => 'Regionalteam', 'VERBAND' => 'Verband', 'UNATTACHED' => 'Ohne Zuordnung'];
    @endphp
    <div class="max-w-2xl">
        <div class="flex items-center gap-2 mb-6">
            <flux:button href="{{ isset($club) ? route('clubs.show', $club) : ListUrl::to('clubs') }}" variant="primary" icon="arrow-left" size="sm"
                         title="Zurück" aria-label="Zurück"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                {{ isset($club) ? 'Verein bearbeiten' : 'Verein anlegen' }}
            </h1>
        </div>

        <form method="POST" action="{{ isset($club) ? route('clubs.update', $club) : route('clubs.store') }}">
            @csrf
            @if(isset($club))
                @method('PUT')
            @endif

            <div
                class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4 mb-4">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">Vereinsdaten</h2>

                <flux:field>
                    <flux:label>Name <span class="text-red-500 dark:text-red-400">*</span></flux:label>
                    <flux:input name="name" value="{{ old('name', $club->name ?? '') }}" required/>
                    <flux:error name="name"/>
                </flux:field>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Kurzname</flux:label>
                        <flux:input name="short_name" value="{{ old('short_name', $club->short_name ?? '') }}"
                                    placeholder="max. 20 Zeichen"/>
                        <flux:error name="short_name"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Code</flux:label>
                        <flux:input name="code" value="{{ old('code', $club->code ?? '') }}"
                                    placeholder="z.B. WBSV01"/>
                        <flux:description class="mt-1!">Eindeutig je Nation, wird in Großbuchstaben gespeichert.</flux:description>
                        <flux:error name="code"/>
                    </flux:field>
                </div>

                {{-- Gesperrte Auswahlfelder (ClubFieldRules) als schreibgeschützter Text ohne name: Der Server behält den Wert. --}}
                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Nation <span class="text-red-500 dark:text-red-400">*</span></flux:label>
                        @isset($locked['nation_id'])
                            <flux:input value="{{ $club->nation?->code }} – {{ $club->nation?->name_de }}" readonly/>
                            <x-locked-field-hint field="nation_id" :locked="$locked"/>
                        @else
                            <flux:select variant="listbox" searchable name="nation_id" placeholder="Wählen…" required>
                                @foreach($nations as $nation)
                                    <flux:select.option value="{{ $nation->id }}" :selected="old('nation_id', $club->nation_id ?? $autId) == $nation->id">{{ $nation->code }} – {{ $nation->name_de }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @endisset
                        <flux:error name="nation_id"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Typ <span class="text-red-500 dark:text-red-400">*</span></flux:label>
                        @isset($locked['type'])
                            <flux:input value="{{ $types[$club->type] ?? $club->type }}" readonly/>
                            <x-locked-field-hint field="type" :locked="$locked"/>
                        @else
                            <flux:select variant="listbox" name="type" required>
                                @foreach($types as $value => $label)
                                    <flux:select.option value="{{ $value }}" :selected="old('type', $club->type ?? 'CLUB') === $value">{{ $label }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @endisset
                        <flux:error name="type"/>
                    </flux:field>
                </div>

                {{--
                    Regionalverband — für österreichische Vereine.
                    Wird immer angezeigt, nullable → ÖBSV und nicht-österr. Vereine
                    lassen dieses Feld leer.
                --}}
                <flux:field>
                    <flux:label>Regionalverband (Österreich)</flux:label>
                    @isset($locked['regional_association'])
                        <flux:input value="{{ $club->regional_association ? $club->regional_association.' – '.$club->regional_association_name : 'Keiner' }}" readonly/>
                        <x-locked-field-hint field="regional_association" :locked="$locked"/>
                    @else
                        <flux:select variant="listbox" name="regional_association"
                                     placeholder="Keiner (bundesweit / nicht österreichisch)" clearable>
                            @foreach(Club::REGIONAL_ASSOCIATIONS as $code => $name)
                                <flux:select.option value="{{ $code }}" :selected="old('regional_association', $club->regional_association ?? '') === $code">{{ $code }} – {{ $name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:description>Nur für österreichische Vereine. ÖBSV-Vereine ohne Regionalzuordnung leer
                            lassen.
                        </flux:description>
                    @endisset
                    <flux:error name="regional_association"/>
                </flux:field>
            </div>

            {{-- Kontakt (Spec club-permissions N5) --}}
            <div
                class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4 mb-4">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">Kontakt</h2>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Kontaktperson</flux:label>
                        <flux:input name="contact_name" value="{{ old('contact_name', $club->contact_name ?? '') }}"/>
                        <flux:error name="contact_name"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>E-Mail</flux:label>
                        <flux:input type="email" name="contact_email"
                                    value="{{ old('contact_email', $club->contact_email ?? '') }}"/>
                        <flux:error name="contact_email"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Telefon</flux:label>
                        <flux:input type="tel" name="contact_phone"
                                    value="{{ old('contact_phone', $club->contact_phone ?? '') }}"/>
                        <flux:error name="contact_phone"/>
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>Straße</flux:label>
                    <flux:input name="address_street" value="{{ old('address_street', $club->address_street ?? '') }}"/>
                    <flux:error name="address_street"/>
                </flux:field>

                <div class="grid grid-cols-3 gap-4">
                    <flux:field>
                        <flux:label>PLZ</flux:label>
                        <flux:input name="address_zip" value="{{ old('address_zip', $club->address_zip ?? '') }}"/>
                        <flux:error name="address_zip"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Ort</flux:label>
                        <flux:input name="address_city" value="{{ old('address_city', $club->address_city ?? '') }}"/>
                        <flux:error name="address_city"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Land</flux:label>
                        <flux:select variant="listbox" searchable name="address_country" placeholder="Wählen…" clearable>
                            @foreach($nations as $nation)
                                <flux:select.option value="{{ $nation->code }}" :selected="old('address_country', $club->address_country ?? '') === $nation->code">{{ $nation->code }} – {{ $nation->name_de }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="address_country"/>
                    </flux:field>
                </div>
            </div>

            {{-- LENEX / Externe IDs --}}
            <div
                class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4 mb-6">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">Externe IDs</h2>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>SWRID</flux:label>
                        <flux:input name="swrid" value="{{ old('swrid', $club->swrid ?? '') }}"
                                    :readonly="isset($locked['swrid'])" placeholder="swimrankings.net ID"/>
                        <x-locked-field-hint field="swrid" :locked="$locked"/>
                        <flux:error name="swrid"/>
                    </flux:field>

                </div>
            </div>

            <div class="flex gap-3">
                <flux:button type="submit" variant="primary">
                    {{ isset($club) ? 'Änderungen speichern' : 'Verein anlegen' }}
                </flux:button>
                <flux:button href="{{ isset($club) ? route('clubs.show', $club) : ListUrl::to('clubs') }}" variant="ghost">Abbrechen</flux:button>
            </div>
        </form>
    </div>
@endsection
