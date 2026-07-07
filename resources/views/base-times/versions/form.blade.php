@extends('layouts.app')

@section('title', $version ? 'Version bearbeiten' : 'Neue Version')

@section('content')
    <div class="max-w-lg">
        <div class="flex items-center gap-3 mb-6">
            <flux:button href="{{ route('base-times.versions.index') }}" variant="ghost" icon="arrow-left" size="sm"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                {{ $version ? 'Version bearbeiten' : 'Neue Version' }}
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
              action="{{ $version ? route('base-times.versions.update', $version) : route('base-times.versions.store') }}">
            @csrf
            @if($version)
                @method('PUT')
            @endif

            <div
                class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4 mb-4">
                <flux:field>
                    <flux:label>Bezeichnung *</flux:label>
                    <flux:input name="label" placeholder="z.B. 2021–2026"
                                value="{{ old('label', $version?->label) }}" required/>
                    <flux:error name="label"/>
                </flux:field>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Gültig ab *</flux:label>
                        <flux:input name="valid_from" type="date"
                                    value="{{ old('valid_from', $version?->valid_from?->toDateString()) }}" required/>
                        <flux:error name="valid_from"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Gültig bis <span class="font-normal text-zinc-400">(optional)</span></flux:label>
                        <flux:input name="valid_until" type="date"
                                    value="{{ old('valid_until', $version?->valid_until?->toDateString()) }}"/>
                        <flux:error name="valid_until"/>
                    </flux:field>
                </div>
            </div>

            <div class="flex gap-3">
                <flux:button type="submit" variant="primary">
                    {{ $version ? 'Speichern' : 'Anlegen' }}
                </flux:button>
                <flux:button href="{{ route('base-times.versions.index') }}" variant="ghost">
                    Abbrechen
                </flux:button>
            </div>
        </form>
    </div>
@endsection
