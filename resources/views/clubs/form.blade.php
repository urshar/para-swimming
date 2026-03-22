@extends('layouts.app')

@section('title', isset($club) ? $club->name . ' bearbeiten' : 'Neuer Verein')

@section('content')
    <div class="max-w-xl">
        <div class="flex items-center gap-3 mb-6">
            <flux:button href="{{ route('clubs.index') }}" variant="ghost" icon="arrow-left" size="sm"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                {{ isset($club) ? 'Verein bearbeiten' : 'Neuer Verein' }}
            </h1>
        </div>

        <form method="POST" action="{{ isset($club) ? route('clubs.update', $club) : route('clubs.store') }}">
            @csrf
            @if(isset($club))
                @method('PUT')
            @endif

            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4">

                <flux:field>
                    <flux:label>Name *</flux:label>
                    <flux:input name="name" value="{{ old('name', $club->name ?? '') }}" required/>
                    <flux:error name="name"/>
                </flux:field>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Kurzname</flux:label>
                        <flux:input name="short_name" value="{{ old('short_name', $club->short_name ?? '') }}"
                                    placeholder="Max. 40 Zeichen"/>
                        <flux:error name="short_name"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Vereinskürzel</flux:label>
                        <flux:input name="code" value="{{ old('code', $club->code ?? '') }}" placeholder="z.B. OEBSV"
                                    class="uppercase"/>
                        <flux:error name="code"/>
                    </flux:field>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Nation *</flux:label>
                        <flux:select name="nation_id" required>
                            <option value="">Bitte wählen…</option>
                            @foreach($nations as $nation)
                                <option
                                    value="{{ $nation->id }}" @selected(old('nation_id', $club->nation_id ?? '') == $nation->id)>
                                    {{ $nation->code }} – {{ $nation->name_de }}
                                </option>
                            @endforeach
                        </flux:select>
                        <flux:error name="nation_id"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Typ *</flux:label>
                        <flux:select name="type" required>
                            <option value="CLUB" @selected(old('type', $club->type ?? 'CLUB') === 'CLUB')>Verein
                            </option>
                            <option value="NATIONALTEAM" @selected(old('type', $club->type ?? '') === 'NATIONALTEAM')>
                                Nationalmannschaft
                            </option>
                            <option value="REGIONALTEAM" @selected(old('type', $club->type ?? '') === 'REGIONALTEAM')>
                                Regionalmannschaft
                            </option>
                            <option value="UNATTACHED" @selected(old('type', $club->type ?? '') === 'UNATTACHED')>Ohne
                                Verein
                            </option>
                        </flux:select>
                        <flux:error name="type"/>
                    </flux:field>
                </div>
            </div>

            <div class="flex gap-3 mt-6">
                <flux:button type="submit" variant="primary">
                    {{ isset($club) ? 'Speichern' : 'Verein anlegen' }}
                </flux:button>
                <flux:button href="{{ isset($club) ? route('clubs.show', $club) : route('clubs.index') }}"
                             variant="ghost">
                    Abbrechen
                </flux:button>
            </div>
        </form>
    </div>
@endsection
