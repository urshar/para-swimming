@extends('layouts.app')

@section('title', 'Nation bearbeiten – ' . $nation->code)

@section('content')
    <div class="max-w-lg">
        <div class="mb-6">
            <div class="flex items-center gap-2">
                <flux:button href="{{ route('nations.index') }}" variant="primary" icon="arrow-left" size="sm"
                             title="Zurück" aria-label="Zurück"/>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                    Nation bearbeiten – {{ $nation->code }}
                </h1>
            </div>
        </div>
        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 p-6">
            <form method="POST" action="{{ route('nations.update', $nation) }}" class="space-y-4">
                @csrf
                @method('PUT')

                <flux:field>
                    <flux:label>IOC-Code</flux:label>
                    <flux:input value="{{ $nation->code }}" disabled class="font-mono"/>
                </flux:field>

                <flux:field>
                    <flux:label>Name Deutsch</flux:label>
                    <flux:input name="name_de" value="{{ old('name_de', $nation->name_de) }}" required/>
                    <flux:error name="name_de"/>
                </flux:field>

                <flux:field>
                    <flux:label>Name Englisch</flux:label>
                    <flux:input name="name_en" value="{{ old('name_en', $nation->name_en) }}" required/>
                    <flux:error name="name_en"/>
                </flux:field>

                <flux:switch name="is_active" value="1" :checked="old('is_active', $nation->is_active)" label="Aktiv"/>

                <div class="flex gap-3 pt-2">
                    <flux:button type="submit" variant="primary">Speichern</flux:button>
                    <flux:button href="{{ route('nations.index') }}" variant="ghost">Abbrechen</flux:button>
                </div>
            </form>
        </div>
    </div>
@endsection
