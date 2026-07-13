@extends('layouts.app')

@section('title', $kaderType ? 'Kaderart bearbeiten' : 'Neue Kaderart')

@section('content')
    <div class="max-w-lg">
        <div class="flex items-center gap-3 mb-6">
            <flux:button href="{{ route('kader-types.index') }}" variant="ghost" icon="arrow-left" size="sm"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                {{ $kaderType ? 'Kaderart bearbeiten' : 'Neue Kaderart' }}
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
              action="{{ $kaderType ? route('kader-types.update', $kaderType) : route('kader-types.store') }}">
            @csrf
            @if($kaderType)
                @method('PUT')
            @endif

            <div
                class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4 mb-4">
                <flux:field>
                    <flux:label>Code *</flux:label>
                    <flux:input name="code" placeholder="z.B. WELTKLASSE"
                                value="{{ old('code', $kaderType?->code) }}" required/>
                    <flux:error name="code"/>
                </flux:field>

                <flux:field>
                    <flux:label>Bezeichnung *</flux:label>
                    <flux:input name="name_de" placeholder="z.B. Weltklasse"
                                value="{{ old('name_de', $kaderType?->name_de) }}" required/>
                    <flux:error name="name_de"/>
                </flux:field>

                <flux:field>
                    <flux:label>Sortierung</flux:label>
                    <flux:input name="sort_order" type="number" min="0" max="1000"
                                value="{{ old('sort_order', $kaderType?->sort_order ?? 0) }}"/>
                    <flux:error name="sort_order"/>
                </flux:field>

                <flux:field>
                    <flux:checkbox name="is_active" value="1"
                                   :checked="old('is_active', $kaderType?->is_active ?? true)"
                                   label="Aktiv"/>
                </flux:field>
            </div>

            <div class="flex gap-3">
                <flux:button type="submit" variant="primary">
                    {{ $kaderType ? 'Speichern' : 'Anlegen' }}
                </flux:button>
                <flux:button href="{{ route('kader-types.index') }}" variant="ghost">
                    Abbrechen
                </flux:button>
            </div>
        </form>
    </div>
@endsection
