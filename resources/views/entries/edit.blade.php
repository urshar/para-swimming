@extends('layouts.app')

@section('title', 'Meldung bearbeiten')

@section('content')
    <div class="max-w-2xl">
        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 p-6">

            {{-- Read-only Info --}}
            <div class="grid grid-cols-2 gap-4 mb-6 pb-6 border-b border-zinc-200 dark:border-zinc-800">
                <div>
                    <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide mb-1">
                        Athlet
                    </div>
                    <div
                        class="text-sm font-medium text-zinc-900 dark:text-white">{{ $entry->athlete?->full_name }}</div>
                </div>
                <div>
                    <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide mb-1">
                        Disziplin
                    </div>
                    <div
                        class="text-sm font-medium text-zinc-900 dark:text-white">{{ $entry->swimEvent?->display_name }}</div>
                </div>
            </div>

            <form method="POST" action="{{ route('entries.update', $entry) }}" class="space-y-4">
                @csrf
                @method('PUT')

                <flux:field>
                    <flux:label>Meldender Club</flux:label>
                    <flux:select name="club_id" required>
                        @foreach($clubs as $club)
                            <option value="{{ $club->id }}" @selected(old('club_id', $entry->club_id) == $club->id)>
                                {{ $club->name }} ({{ $club->nation?->code }})
                            </option>
                        @endforeach
                    </flux:select>
                    <flux:error name="club_id"/>
                </flux:field>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Meldezeit (Hundertstelsekunden)</flux:label>
                        <flux:input name="entry_time" type="number" min="0"
                                    value="{{ old('entry_time', $entry->entry_time) }}"/>
                        <flux:error name="entry_time"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Bahnlänge der Meldezeit</flux:label>
                        <flux:select name="entry_course">
                            <option value="">–</option>
                            <option value="LCM" @selected(old('entry_course', $entry->entry_course) === 'LCM')>LCM
                                (50m)
                            </option>
                            <option value="SCM" @selected(old('entry_course', $entry->entry_course) === 'SCM')>SCM
                                (25m)
                            </option>
                        </flux:select>
                        <flux:error name="entry_course"/>
                    </flux:field>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Sport-Klasse</flux:label>
                        <flux:input name="sport_class" value="{{ old('sport_class', $entry->sport_class) }}"
                                    maxlength="15"/>
                        <flux:error name="sport_class"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Status</flux:label>
                        <flux:select name="status">
                            <option value="" @selected(!old('status', $entry->status))>Normal</option>
                            <option value="EXH" @selected(old('status', $entry->status) === 'EXH')>EXH –
                                Ausstellungsstart
                            </option>
                            <option value="WDR" @selected(old('status', $entry->status) === 'WDR')>WDR – Zurückgezogen
                            </option>
                            <option value="SICK" @selected(old('status', $entry->status) === 'SICK')>SICK – Krank
                            </option>
                        </flux:select>
                        <flux:error name="status"/>
                    </flux:field>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Lauf</flux:label>
                        <flux:input name="heat" type="number" min="1" value="{{ old('heat', $entry->heat) }}"/>
                        <flux:error name="heat"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Bahn</flux:label>
                        <flux:input name="lane" type="number" min="0" value="{{ old('lane', $entry->lane) }}"/>
                        <flux:error name="lane"/>
                    </flux:field>
                </div>

                <div class="flex gap-3 pt-2">
                    <flux:button type="submit" variant="primary">Speichern</flux:button>
                    <flux:button href="{{ route('meets.show', $entry->meet) }}" variant="ghost">Abbrechen</flux:button>
                </div>
            </form>
        </div>
    </div>
@endsection
