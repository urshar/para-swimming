@extends('layouts.app')

@section('title', isset($classifier) ? $classifier->full_name . ' bearbeiten' : 'Neuer Klassifizierer')

@section('content')
    <div class="max-w-2xl">

        <div class="flex items-center gap-3 mb-6">
            <flux:button href="{{ route('classifiers.index') }}" variant="ghost" icon="arrow-left" size="sm"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                {{ isset($classifier) ? 'Klassifizierer bearbeiten' : 'Neuer Klassifizierer' }}
            </h1>
        </div>

        <form method="POST"
              action="{{ isset($classifier) ? route('classifiers.update', $classifier) : route('classifiers.store') }}">
            @csrf
            @if(isset($classifier))
                @method('PUT')
            @endif

            {{-- Stammdaten --}}
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4 mb-4">
                <div class="flex items-center justify-between">
                    <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">Stammdaten</h2>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <span class="text-sm text-zinc-600 dark:text-zinc-400">Aktiv</span>
                        <input type="hidden" name="is_active" value="0">
                        <flux:switch name="is_active" value="1" :checked="old('is_active', $classifier->is_active ?? true)"/>
                    </label>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Vorname <span class="text-red-500 dark:text-red-400">*</span></flux:label>
                        <flux:input name="first_name"
                                    value="{{ old('first_name', $classifier->first_name ?? '') }}" required/>
                        <flux:error name="first_name"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Nachname <span class="text-red-500 dark:text-red-400">*</span></flux:label>
                        <flux:input name="last_name"
                                    value="{{ old('last_name', $classifier->last_name ?? '') }}" required/>
                        <flux:error name="last_name"/>
                    </flux:field>
                </div>

                <div class="grid grid-cols-3 gap-4">
                    <flux:field>
                        <flux:label>Typ <span class="text-red-500 dark:text-red-400">*</span></flux:label>
                        <flux:select variant="listbox" name="type" placeholder="Bitte wählen…" required>
                            <flux:select.option value="MED" :selected="old('type', $classifier->type ?? '') === 'MED'">Medizinisch</flux:select.option>
                            <flux:select.option value="TECH" :selected="old('type', $classifier->type ?? '') === 'TECH'">Technisch</flux:select.option>
                        </flux:select>
                        <flux:error name="type"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Geschlecht</flux:label>
                        <flux:select variant="listbox" name="gender" placeholder="Nicht angegeben" clearable>
                            <flux:select.option value="M" :selected="old('gender', $classifier->gender ?? '') === 'M'">Männlich</flux:select.option>
                            <flux:select.option value="F" :selected="old('gender', $classifier->gender ?? '') === 'F'">Weiblich</flux:select.option>
                            <flux:select.option value="N" :selected="old('gender', $classifier->gender ?? '') === 'N'">Nicht binär</flux:select.option>
                        </flux:select>
                        <flux:error name="gender"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Nation</flux:label>
                        @php
                            $autId = $nations->firstWhere('code', 'AUT')?->id;
                            $defaultNationId = old('nation_id', $classifier->nation_id ?? $autId);
                        @endphp
                        <flux:select variant="listbox" searchable name="nation_id" placeholder="Nicht angegeben" clearable>
                            @foreach($nations as $nation)
                                <flux:select.option value="{{ $nation->id }}" :selected="$defaultNationId == $nation->id">{{ $nation->code }} – {{ $nation->name_de }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="nation_id"/>
                    </flux:field>
                </div>
            </div>

            {{-- Kontakt --}}
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4 mb-4">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">Kontakt</h2>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>E-Mail</flux:label>
                        <flux:input name="email" type="email"
                                    value="{{ old('email', $classifier->email ?? '') }}"
                                    placeholder="name@example.com"/>
                        <flux:error name="email"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Telefon</flux:label>
                        <flux:input name="phone"
                                    value="{{ old('phone', $classifier->phone ?? '') }}"
                                    placeholder="+43 …"/>
                        <flux:error name="phone"/>
                    </flux:field>
                </div>
            </div>

            {{-- Notizen --}}
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 mb-6">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-4">Notizen</h2>
                <flux:field>
                    <flux:textarea name="notes" rows="3"
                                   placeholder="Interne Notizen…">{{ old('notes', $classifier->notes ?? '') }}</flux:textarea>
                    <flux:error name="notes"/>
                </flux:field>
            </div>

            <div class="flex gap-3">
                <flux:button type="submit" variant="primary">
                    {{ isset($classifier) ? 'Speichern' : 'Klassifizierer anlegen' }}
                </flux:button>
                <flux:button href="{{ isset($classifier) ? route('classifiers.show', $classifier) : route('classifiers.index') }}"
                             variant="ghost">
                    Abbrechen
                </flux:button>
            </div>

        </form>
    </div>
@endsection
