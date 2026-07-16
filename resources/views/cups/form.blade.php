@extends('layouts.app')

@section('title', $cup ? 'Cup bearbeiten' : 'Neuer Cup')

@section('content')
    <div class="max-w-2xl">
        <div class="flex items-center gap-3 mb-6">
            <flux:button href="{{ route('cups.index') }}" variant="ghost" icon="arrow-left" size="sm"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                {{ $cup ? 'Cup bearbeiten' : 'Neuer Cup' }}
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

        <form method="POST" action="{{ $cup ? route('cups.update', $cup) : route('cups.store') }}">
            @csrf
            @if($cup)
                @method('PUT')
            @endif

            <div
                class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4 mb-4">
                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Cup-Jahr *</flux:label>
                        <flux:input name="year" type="number" min="2000" max="2100"
                                    value="{{ old('year', $cup?->year) }}" required/>
                        <flux:error name="year"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Name *</flux:label>
                        <flux:input name="name" placeholder="z.B. ÖBSV Cup 2026"
                                    value="{{ old('name', $cup?->name) }}" required/>
                        <flux:error name="name"/>
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>ÖBSV-1000-Punkte-Tabelle *</flux:label>
                    <flux:select name="base_time_version_id" required>
                        <option value="">– auswählen –</option>
                        @foreach($baseTimeVersions as $version)
                            <option value="{{ $version->id }}"
                                @selected(old('base_time_version_id', $cup?->base_time_version_id) == $version->id)>
                                {{ $version->label }}
                            </option>
                        @endforeach
                    </flux:select>
                    <flux:error name="base_time_version_id"/>
                </flux:field>

                <div class="grid grid-cols-3 gap-4">
                    <flux:field>
                        <flux:label>Wertungsrunden *</flux:label>
                        <flux:input name="rounds_count" type="number" min="1" max="50"
                                    value="{{ old('rounds_count', $cup?->rounds_count ?? 1) }}" required/>
                        <flux:error name="rounds_count"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Beste X Ergebnisse *</flux:label>
                        <flux:input name="best_of_count" type="number" min="1" max="50"
                                    value="{{ old('best_of_count', $cup?->best_of_count ?? 1) }}" required/>
                        <flux:error name="best_of_count"/>
                    </flux:field>
                    <flux:field>
                        <flux:label>Top-Gruppe ab Punkten *</flux:label>
                        <flux:input name="top_group_points_threshold" type="number" min="0" max="1200"
                                    value="{{ old('top_group_points_threshold', $cup?->top_group_points_threshold ?? 450) }}"
                                    required/>
                        <flux:error name="top_group_points_threshold"/>
                    </flux:field>
                </div>

                <flux:field>
                    <flux:checkbox name="is_active" value="1"
                                   :checked="old('is_active', $cup?->is_active ?? true)"
                                   label="Aktiv"/>
                </flux:field>
            </div>

            <div
                class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-3 mb-4">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-2">Aktive Sportklassengruppen</h2>
                <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-3">
                    Nicht angehakte Gruppen werden für dieses Cup-Jahr bei der Wertung übersprungen.
                    "Gemeinsam" wertet Damen und Herren in einer Rangliste statt zweier getrennter.
                </p>
                @forelse($sportClassGroups as $group)
                    <div class="flex items-center justify-between gap-4 py-1">
                        <flux:field class="mb-0">
                            <flux:checkbox name="active_group_ids[]" value="{{ $group->id }}"
                                           :checked="in_array($group->id, old('active_group_ids', $activeGroupIds))"
                                           label="{{ $group->name_de }} ({{ $group->code }})"/>
                        </flux:field>
                        <flux:field class="mb-0">
                            <flux:checkbox name="gender_combined_group_ids[]" value="{{ $group->id }}"
                                           :checked="in_array($group->id, old('gender_combined_group_ids', $genderCombinedGroupIds))"
                                           label="Damen & Herren gemeinsam"/>
                        </flux:field>
                    </div>
                @empty
                    <p class="text-sm text-zinc-400">
                        Noch keine Sportklassengruppen angelegt —
                        <a href="{{ route('sport-class-groups.create') }}" class="text-blue-600 hover:underline">
                            jetzt anlegen
                        </a>.
                    </p>
                @endforelse
            </div>

            <div
                class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-3 mb-4">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-2">Aktive Altersgruppen</h2>
                <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-3">
                    Gilt nur für die Gesamtwertung (Punkt 5 der Spec). Nicht angehakte Altersgruppen werden nicht
                    separat gewertet — betroffene Athleten landen in einer gemeinsamen, altersgruppen-übergreifenden
                    Wertung statt in einer eigenen Alters-Kategorie.
                </p>
                @forelse($ageGroups as $ageGroup)
                    <flux:field>
                        <flux:checkbox name="active_age_group_ids[]" value="{{ $ageGroup->id }}"
                                       :checked="in_array($ageGroup->id, old('active_age_group_ids', $activeAgeGroupIds))"
                                       label="{{ $ageGroup->name_de }}"/>
                    </flux:field>
                @empty
                    <p class="text-sm text-zinc-400">
                        Noch keine Altersgruppen angelegt —
                        <a href="{{ route('age-groups.create') }}" class="text-blue-600 hover:underline">
                            jetzt anlegen
                        </a>.
                    </p>
                @endforelse
            </div>

            <div class="flex gap-3">
                <flux:button type="submit" variant="primary">
                    {{ $cup ? 'Speichern' : 'Anlegen' }}
                </flux:button>
                <flux:button href="{{ route('cups.index') }}" variant="ghost">
                    Abbrechen
                </flux:button>
            </div>
        </form>
    </div>
@endsection
