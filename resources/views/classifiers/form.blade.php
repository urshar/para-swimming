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
            <div
                class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4 mb-4">
                <div class="flex items-center justify-between">
                    <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">Stammdaten</h2>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <span class="text-sm text-zinc-600 dark:text-zinc-400">Aktiv</span>
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1"
                               @checked(old('is_active', $classifier->is_active ?? true))
                               class="rounded border-zinc-300 dark:border-zinc-600 text-blue-600">
                    </label>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Vorname *</flux:label>
                        <flux:input name="first_name"
                                    value="{{ old('first_name', $classifier->first_name ?? '') }}" required/>
                        <flux:error name="first_name"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Nachname *</flux:label>
                        <flux:input name="last_name"
                                    value="{{ old('last_name', $classifier->last_name ?? '') }}" required/>
                        <flux:error name="last_name"/>
                    </flux:field>
                </div>

                <div class="grid grid-cols-3 gap-4">
                    <flux:field>
                        <flux:label>Typ *</flux:label>
                        <flux:select name="type" required>
                            <option value="">Bitte wählen…</option>
                            <option value="MED" @selected(old('type', $classifier->type ?? '') === 'MED')>
                                Medizinisch
                            </option>
                            <option value="TECH" @selected(old('type', $classifier->type ?? '') === 'TECH')>
                                Technisch
                            </option>
                        </flux:select>
                        <flux:error name="type"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Geschlecht</flux:label>
                        <flux:select name="gender">
                            <option value="">Nicht angegeben</option>
                            <option value="M" @selected(old('gender', $classifier->gender ?? '') === 'M')>Männlich
                            </option>
                            <option value="F" @selected(old('gender', $classifier->gender ?? '') === 'F')>Weiblich
                            </option>
                            <option value="N" @selected(old('gender', $classifier->gender ?? '') === 'N')>Nicht binär
                            </option>
                        </flux:select>
                        <flux:error name="gender"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Nation</flux:label>
                        <flux:select name="nation">
                            <option value="">Nicht angegeben</option>
                            @foreach($nations as $nation)
                                <option value="{{ $nation->code }}"
                                    @selected(old('nation', $classifier->nation ?? 'AUT') === $nation->code)>
                                    {{ $nation->code }} – {{ $nation->name_de }}
                                </option>
                            @endforeach
                        </flux:select>
                        <flux:error name="nation"/>
                    </flux:field>
                </div>
            </div>

            {{-- Kontakt --}}
            <div
                class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4 mb-4">
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
                <flux:button
                    href="{{ isset($classifier) ? route('classifiers.show', $classifier) : route('classifiers.index') }}"
                    variant="ghost">
                    Abbrechen
                </flux:button>
            </div>

        </form>
    </div>
@endsection
