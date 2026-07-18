@extends('layouts.app')

@section('title', "Qualifikation $list->year")

@section('content')
    <div class="max-w-5xl">
        <div class="flex items-center gap-3 mb-6">
            <flux:button href="{{ route('qualifying-time-lists.show', $list) }}" variant="ghost" icon="arrow-left"
                         size="sm"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Qualifikation {{ $list->year }}</h1>
            <flux:badge color="zinc">{{ $qualifications->count() }} Schwimmer</flux:badge>
        </div>

        {{-- Filter --}}
        <form method="GET" action="{{ route('qualifying-time-lists.qualifications', $list) }}"
              class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 mb-6 grid grid-cols-2 md:grid-cols-5 gap-3 items-end">
            <flux:field>
                <flux:label>Bewerb</flux:label>
                <flux:select name="stroke_type_id_distance" onchange="
                    const [s, d] = this.value.split('|');
                    this.form.stroke_type_id.value = s ?? '';
                    this.form.distance.value = d ?? '';
                    this.form.submit();
                ">
                    <option value="">Alle</option>
                    @foreach($events as $event)
                        <option value="{{ $event['stroke_type_id'] }}|{{ $event['distance'] }}"
                            @selected(request('stroke_type_id') == $event['stroke_type_id'] && request('distance') == $event['distance'])>
                            {{ $event['label'] }}
                        </option>
                    @endforeach
                </flux:select>
                <input type="hidden" name="stroke_type_id" value="{{ request('stroke_type_id') }}"/>
                <input type="hidden" name="distance" value="{{ request('distance') }}"/>
            </flux:field>

            <flux:field>
                <flux:label>Geschlecht</flux:label>
                <flux:select name="gender" onchange="this.form.submit()">
                    <option value="">Alle</option>
                    @foreach($genders as $gender)
                        <option value="{{ $gender }}" @selected(request('gender') == $gender)>
                            {{ $gender }}
                        </option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field>
                <flux:label>Sportklasse</flux:label>
                <flux:select name="sport_class" onchange="this.form.submit()">
                    <option value="">Alle</option>
                    @foreach($sportClasses as $sportClass)
                        <option value="{{ $sportClass }}" @selected(request('sport_class') == $sportClass)>
                            {{ $sportClass }}
                        </option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field>
                <flux:label>Verein</flux:label>
                <flux:select name="club_id" onchange="this.form.submit()">
                    <option value="">Alle</option>
                    @foreach($clubs as $club)
                        <option value="{{ $club->id }}" @selected(request('club_id') == $club->id)>
                            {{ $club->display_name ?? $club->name }}
                        </option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field>
                <flux:label>Suche (Name/Verein)</flux:label>
                <div class="flex gap-2">
                    <flux:input name="search" value="{{ request('search') }}" placeholder="Name oder Verein"/>
                    <flux:button type="submit" variant="primary" size="sm" icon="magnifying-glass"/>
                </div>
            </flux:field>
        </form>

        @if(request()->anyFilled(['stroke_type_id', 'gender', 'sport_class', 'club_id', 'search']))
            <div class="mb-4">
                <flux:button href="{{ route('qualifying-time-lists.qualifications', $list) }}" variant="ghost"
                             size="sm" icon="x-mark">
                    Filter zurücksetzen
                </flux:button>
            </div>
        @endif

        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
            <flux:table
                class="[&_td:first-child]:ps-4 [&_th:first-child]:ps-4 [&_td:last-child]:pe-4 [&_th:last-child]:pe-4">
                <flux:table.columns>
                    <flux:table.column>Name</flux:table.column>
                    <flux:table.column>Verein</flux:table.column>
                    <flux:table.column>Geschlecht</flux:table.column>
                    <flux:table.column>Sportklasse</flux:table.column>
                    <flux:table.column>Bewerb</flux:table.column>
                    <flux:table.column>Zeit</flux:table.column>
                    <flux:table.column>Richtzeit</flux:table.column>
                    <flux:table.column>Punkte</flux:table.column>
                    <flux:table.column>Datum</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse($qualifications as $q)
                        <flux:table.row>
                            <flux:table.cell class="font-medium">
                                {{ $q->athlete?->last_name }}, {{ $q->athlete?->first_name }}
                            </flux:table.cell>
                            <flux:table.cell>{{ $q->club?->display_name ?? $q->club?->name ?? '–' }}</flux:table.cell>
                            <flux:table.cell>{{ $q->qualifyingTime->gender }}</flux:table.cell>
                            <flux:table.cell class="font-mono">{{ $q->sport_class }}</flux:table.cell>
                            <flux:table.cell>
                                {{ $q->qualifyingTime->distance }}m {{ $q->qualifyingTime->strokeType?->name_de }}
                            </flux:table.cell>
                            <flux:table.cell class="font-mono">{{ $q->formatted_swim_time }}</flux:table.cell>
                            <flux:table.cell class="font-mono text-zinc-400">
                                {{ $q->qualifyingTime->formatted_value }}
                            </flux:table.cell>
                            <flux:table.cell>{{ $q->points ?? '–' }}</flux:table.cell>
                            <flux:table.cell>{{ $q->qualified_at->format('d.m.Y') }}</flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="9">
                                <p class="text-sm text-zinc-400 py-6 text-center">
                                    Keine Qualifikationen gefunden — ggf. Filter anpassen oder zuerst unter
                                    „Bearbeiten" die Qualifikation berechnen.
                                </p>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    </div>
@endsection
