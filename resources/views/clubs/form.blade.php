@php
    use App\Models\Club;
@endphp

@extends('layouts.app')

@section('title', isset($club) ? 'Verein bearbeiten' : 'Verein anlegen')

@section('content')
    <div class="max-w-2xl">
        <div class="flex items-center gap-3 mb-6">
            <flux:button href="{{ route('clubs.index') }}" variant="ghost" icon="arrow-left" size="sm"/>
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
                    <flux:label>Name *</flux:label>
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
                        <flux:error name="code"/>
                    </flux:field>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Nation *</flux:label>
                        <flux:select name="nation_id" required>
                            <option value="">Wählen…</option>
                            @foreach($nations as $nation)
                                <option value="{{ $nation->id }}"
                                    @selected(old('nation_id', $club->nation_id ?? '') == $nation->id)>
                                    {{ $nation->code }} – {{ $nation->name_de }}
                                </option>
                            @endforeach
                        </flux:select>
                        <flux:error name="nation_id"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Typ *</flux:label>
                        <flux:select name="type" required>
                            @foreach(['CLUB' => 'Verein', 'NATIONALTEAM' => 'Nationalteam', 'REGIONALTEAM' => 'Regionalteam', 'VERBAND' => 'Verband', 'UNATTACHED' => 'Ohne Zuordnung'] as $value => $label)
                                <option value="{{ $value }}"
                                    @selected(old('type', $club->type ?? 'CLUB') === $value)>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </flux:select>
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
                    <flux:select name="regional_association">
                        <option value="">Keiner (bundesweit / nicht österreichisch)</option>
                        @foreach(Club::REGIONAL_ASSOCIATIONS as $code => $name)
                            <option value="{{ $code }}"
                                @selected(old('regional_association', $club->regional_association ?? '') === $code)>
                                {{ $code }} – {{ $name }}
                            </option>
                        @endforeach
                    </flux:select>
                    <flux:description>Nur für österreichische Vereine. ÖBSV-Vereine ohne Regionalzuordnung leer
                        lassen.
                    </flux:description>
                    <flux:error name="regional_association"/>
                </flux:field>
            </div>

            {{-- LENEX / Externe IDs --}}
            <div
                class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4 mb-6">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">Externe IDs</h2>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>SWRID</flux:label>
                        <flux:input name="swrid" value="{{ old('swrid', $club->swrid ?? '') }}"
                                    placeholder="swimrankings.net ID"/>
                        <flux:error name="swrid"/>
                    </flux:field>

                </div>
            </div>

            <div class="flex gap-3">
                <flux:button type="submit" variant="primary">
                    {{ isset($club) ? 'Änderungen speichern' : 'Verein anlegen' }}
                </flux:button>
                <flux:button href="{{ route('clubs.index') }}" variant="ghost">Abbrechen</flux:button>
            </div>
        </form>
    </div>
@endsection
