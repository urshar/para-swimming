@php use App\Support\ListUrl; @endphp

@extends('layouts.app')

@section('title', 'Neue Nation')

@section('content')
    <div class="max-w-lg">
        <div class="mb-6">
            <div class="flex items-center gap-2">
                <flux:button href="{{ ListUrl::to('nations') }}" variant="primary" icon="arrow-left" size="sm"
                             title="Zurück" aria-label="Zurück"/>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Neue Nation</h1>
            </div>
        </div>
        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 p-6">
            <form method="POST" action="{{ route('nations.store') }}" class="space-y-4">
                @csrf

                <flux:field>
                    <flux:label>IOC-Code</flux:label>
                    <flux:description>Drei Buchstaben, z. B. AUT. Kann später nicht mehr geändert werden.</flux:description>
                    <flux:input name="code" value="{{ old('code') }}" maxlength="3" required
                                class="font-mono uppercase" autocomplete="off"/>
                    <flux:error name="code"/>
                </flux:field>

                <flux:field>
                    <flux:label>Name Deutsch</flux:label>
                    <flux:input name="name_de" value="{{ old('name_de') }}" required/>
                    <flux:error name="name_de"/>
                </flux:field>

                <flux:field>
                    <flux:label>Name Englisch</flux:label>
                    <flux:input name="name_en" value="{{ old('name_en') }}" required/>
                    <flux:error name="name_en"/>
                </flux:field>

                <flux:switch name="is_active" value="1" :checked="old('is_active', true)" label="Aktiv"/>

                <div class="flex gap-3 pt-2">
                    <flux:button type="submit" variant="primary">Anlegen</flux:button>
                    <flux:button href="{{ ListUrl::to('nations') }}" variant="ghost">Abbrechen</flux:button>
                </div>
            </form>
        </div>
    </div>
@endsection
