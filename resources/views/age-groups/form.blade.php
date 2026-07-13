@extends('layouts.app')

@section('title', $ageGroup ? 'Altersgruppe bearbeiten' : 'Neue Altersgruppe')

@section('content')
    <div class="max-w-lg">
        <div class="flex items-center gap-3 mb-6">
            <flux:button href="{{ route('age-groups.index') }}" variant="ghost" icon="arrow-left" size="sm"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                {{ $ageGroup ? 'Altersgruppe bearbeiten' : 'Neue Altersgruppe' }}
            </h1>
        </div>

        @if($errors->any())
            <div
                class="mb-4 p-4 bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-800 rounded-xl text-sm text-red-700 dark:text-red-400">
                @foreach($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <form method="POST"
              action="{{ $ageGroup ? route('age-groups.update', $ageGroup) : route('age-groups.store') }}">
            @csrf
            @if($ageGroup)
                @method('PUT')
            @endif

            <div
                class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4 mb-4">
                <flux:field>
                    <flux:label>Code *</flux:label>
                    <flux:input name="code" placeholder="z.B. JUGEND"
                                value="{{ old('code', $ageGroup?->code) }}" required/>
                    <flux:error name="code"/>
                </flux:field>

                <flux:field>
                    <flux:label>Bezeichnung *</flux:label>
                    <flux:input name="name_de" placeholder="z.B. Jugend"
                                value="{{ old('name_de', $ageGroup?->name_de) }}" required/>
                    <flux:error name="name_de"/>
                </flux:field>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Min. Alter <span class="font-normal text-zinc-400">(optional)</span></flux:label>
                        <flux:input name="min_age" type="number" min="0" max="120"
                                    value="{{ old('min_age', $ageGroup?->min_age) }}"/>
                        <flux:error name="min_age"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Max. Alter <span class="font-normal text-zinc-400">(optional)</span></flux:label>
                        <flux:input name="max_age" type="number" min="0" max="120"
                                    value="{{ old('max_age', $ageGroup?->max_age) }}"/>
                        <flux:error name="max_age"/>
                    </flux:field>
                </div>
                <p class="text-xs text-zinc-400">
                    Leer lassen für ein offenes Intervall, z.B. „Offen“ nur mit Min. Alter = 19.
                    Das Alter wird immer zum Wettkampfdatum berechnet.
                </p>

                <flux:field>
                    <flux:label>Sortierung</flux:label>
                    <flux:input name="sort_order" type="number" min="0" max="1000"
                                value="{{ old('sort_order', $ageGroup?->sort_order ?? 0) }}"/>
                    <flux:error name="sort_order"/>
                </flux:field>

                <flux:field>
                    <flux:checkbox name="is_active" value="1"
                                   :checked="old('is_active', $ageGroup?->is_active ?? true)"
                                   label="Aktiv"/>
                </flux:field>
            </div>

            <div class="flex gap-3">
                <flux:button type="submit" variant="primary">
                    {{ $ageGroup ? 'Speichern' : 'Anlegen' }}
                </flux:button>
                <flux:button href="{{ route('age-groups.index') }}" variant="ghost">
                    Abbrechen
                </flux:button>
            </div>
        </form>
    </div>
@endsection
