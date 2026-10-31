@php use App\Support\ListUrl; @endphp

@extends('layouts.app')

@section('title', $coach->exists ? 'Trainer bearbeiten' : 'Trainer anlegen')

@section('content')
    @php
        // Zurück: beim Bearbeiten zur Detailseite, beim Anlegen zur gemerkten Liste (CLAUDE.md, ListUrl).
        $backUrl = $coach->exists ? route('coaches.show', $coach) : ListUrl::to('coaches');
    @endphp
    <div class="max-w-2xl">
        <div class="flex items-center gap-2 mb-6">
            <flux:button href="{{ $backUrl }}" variant="primary" icon="arrow-left" size="sm" title="Zurück" aria-label="Zurück"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                {{ $coach->exists ? 'Trainer bearbeiten' : 'Trainer anlegen' }}
            </h1>
        </div>

        <form method="POST" action="{{ $coach->exists ? route('coaches.update', $coach) : route('coaches.store') }}">
            @csrf
            @if($coach->exists)
                @method('PUT')
            @endif

            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4 mb-6">
                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Vorname <span class="text-red-500 dark:text-red-400">*</span></flux:label>
                        <flux:input name="first_name" value="{{ old('first_name', $coach->first_name) }}" required/>
                        <flux:error name="first_name"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Nachname <span class="text-red-500 dark:text-red-400">*</span></flux:label>
                        <flux:input name="last_name" value="{{ old('last_name', $coach->last_name) }}" required/>
                        <flux:error name="last_name"/>
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>Verein <span class="text-red-500 dark:text-red-400">*</span></flux:label>
                    @if($clubs->isEmpty())
                        {{-- Kein name: Bei "eigener Verein" setzt der Server den eigenen Verein (CoachController). --}}
                        <flux:input value="{{ $coach->club?->display_name ?? auth()->user()->club?->display_name }}" readonly/>
                        <flux:description class="mt-1! flex items-center gap-1">
                            <flux:icon.lock-closed variant="micro" class="shrink-0"/>
                            eigener Verein
                        </flux:description>
                    @else
                        <flux:select variant="listbox" searchable name="club_id" placeholder="Wählen…" required>
                            @foreach($clubs as $club)
                                <flux:select.option value="{{ $club->id }}" :selected="old('club_id', $coach->club_id) == $club->id">{{ $club->display_name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif
                    <flux:error name="club_id"/>
                </flux:field>

                <flux:field>
                    <flux:label>E-Mail</flux:label>
                    <flux:input type="email" name="email" value="{{ old('email', $coach->email) }}"/>
                    <flux:error name="email"/>
                </flux:field>

                <flux:field>
                    <flux:label>Telefon</flux:label>
                    <flux:input type="tel" name="phone" value="{{ old('phone', $coach->phone) }}"/>
                    <flux:error name="phone"/>
                </flux:field>

                <flux:field>
                    <flux:label>Notizen</flux:label>
                    <flux:textarea name="notes" rows="3">{{ old('notes', $coach->notes) }}</flux:textarea>
                    <flux:error name="notes"/>
                </flux:field>

                <flux:field variant="inline">
                    <flux:switch name="is_active" value="1" :checked="(bool) old('is_active', $coach->is_active)"/>
                    <flux:label>Aktiv</flux:label>
                </flux:field>
            </div>

            <div class="flex gap-3">
                <flux:button type="submit" variant="primary">{{ $coach->exists ? 'Änderungen speichern' : 'Trainer anlegen' }}</flux:button>
                <flux:button href="{{ $backUrl }}" variant="ghost">Abbrechen</flux:button>
            </div>
        </form>
    </div>
@endsection
