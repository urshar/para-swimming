@extends('layouts.app')

@section('title', $version ? 'Version bearbeiten' : 'Neue Version')

@section('content')
    <div class="max-w-lg">
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                {{ $version ? 'Version bearbeiten' : 'Neue Version' }}
            </h1>
            <div class="mt-4">
                <flux:button href="{{ route('base-times.versions.index') }}" variant="filled" icon="arrow-left"
                             size="sm">
                    Zurück
                </flux:button>
            </div>
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
                    <flux:label>Bezeichnung <span class="text-red-500 dark:text-red-400">*</span></flux:label>
                    <flux:input name="label" placeholder="z.B. 2021–2026"
                                value="{{ old('label', $version?->label) }}" required/>
                    <flux:error name="label"/>
                </flux:field>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Gültig ab <span class="text-red-500 dark:text-red-400">*</span></flux:label>
                        <flux:date-picker type="input" locale="de-AT" name="valid_from"
                                    value="{{ old('valid_from', $version?->valid_from?->toDateString()) }}" required/>
                        <flux:error name="valid_from"/>
                    </flux:field>
                    <flux:field>
                        {{-- ms-1 statt reinem Leerzeichen im Text: flux:label rendert als
                             inline-flex (Flux' label.blade.php) — ein reines Leerzeichen zwischen
                             Text und Span wird dort als rein aus Whitespace bestehender
                             Flex-Item-Textknoten laut CSS-Flexbox-Spezifikation entfernt, der
                             Abstand ging dadurch verloren (Erik, 2026-09-03). --}}
                        <flux:label>Gültig bis <span class="font-normal text-zinc-400 ms-1">(optional)</span></flux:label>
                        <flux:date-picker type="input" locale="de-AT" name="valid_until"
                                    value="{{ old('valid_until', $version?->valid_until?->toDateString()) }}" clearable/>
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
