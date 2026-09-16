@extends('layouts.app')

@section('title', $group ? 'Gruppe bearbeiten' : 'Neue Sportklassengruppe')

@section('content')
    <div class="max-w-4xl">
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                {{ $group ? 'Gruppe bearbeiten' : 'Neue Sportklassengruppe' }}
            </h1>
            <div class="mt-4">
                <flux:button href="{{ route('sport-class-groups.index') }}" variant="filled" icon="arrow-left"
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

        @if(session('success'))
            <div
                class="mb-4 p-4 bg-green-50 dark:bg-green-950/20 border border-green-200 dark:border-green-800 rounded-xl text-sm text-green-700 dark:text-green-400">
                {{ session('success') }}
            </div>
        @endif

        {{-- Nebeneinander statt untereinander, sobald es eine "Zugeordnete Sportklassen"-Karte
             gibt (Design-Feedback Erik, 04.09.2026: "kann man die Sportklassen recht[s]
             hinzufügen, so dass es übersichtlich bleibt?") — beim Anlegen oder bei virtuellen
             Gruppen gibt es diese Karte nicht, dann bleibt es einspaltig. --}}
        @php($hasMembers = $group && ! $group->is_virtual)
        <div class="{{ $hasMembers ? 'grid grid-cols-1 lg:grid-cols-2 gap-4 items-start' : '' }}">
            <div>
                <form method="POST"
                      action="{{ $group ? route('sport-class-groups.update', $group) : route('sport-class-groups.store') }}">
                    @csrf
                    @if($group)
                        @method('PUT')
                    @endif

                    <div
                        class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4 mb-4">
                        <flux:field>
                            <flux:label>Code<span class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
                            <flux:input name="code" placeholder="z.B. PI, VI, II, T21, HI, TOP"
                                        value="{{ old('code', $group?->code) }}" required/>
                            <flux:error name="code"/>
                        </flux:field>

                        <flux:field>
                            <flux:label>Bezeichnung<span
                                    class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
                            <flux:input name="name_de" placeholder="z.B. Körperliche Behinderung"
                                        value="{{ old('name_de', $group?->name_de) }}" required/>
                            <flux:error name="name_de"/>
                        </flux:field>

                        <flux:field>
                            <flux:label>Sortierung</flux:label>
                            <flux:input name="sort_order" type="number" min="0" max="1000"
                                        value="{{ old('sort_order', $group?->sort_order ?? 0) }}"/>
                            <flux:error name="sort_order"/>
                        </flux:field>

                        <flux:switch name="is_virtual" value="1" align="left"
                                     :checked="old('is_virtual', $group?->is_virtual ?? false)"
                                     label="Virtuelle Gruppe (z.B. Top-Gruppe — keine festen Sportklassen)"/>

                        <flux:switch name="is_active" value="1" align="left"
                                     :checked="old('is_active', $group?->is_active ?? true)"
                                     label="Aktiv"/>
                    </div>

                    <div class="flex gap-3">
                        <flux:button type="submit" variant="primary">
                            {{ $group ? 'Speichern' : 'Anlegen' }}
                        </flux:button>
                        <flux:button href="{{ route('sport-class-groups.index') }}" variant="ghost">
                            Abbrechen
                        </flux:button>
                    </div>
                </form>
            </div>

            @if($hasMembers)
                <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                    <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-4">Zugeordnete Sportklassen</h2>

                    <form method="POST" action="{{ route('sport-class-groups.members.store', $group) }}"
                          class="flex gap-3 mb-4">
                        @csrf
                        <flux:input name="sport_classes" placeholder="z.B. S1, S2, S3, SB1, SM1"
                                    class="flex-1"/>
                        <flux:button type="submit" variant="primary" size="sm">Hinzufügen</flux:button>
                    </form>
                    <p class="text-xs text-zinc-400 mb-4">
                        Mehrere Sportklassen kommagetrennt eingeben. Eine Sportklasse kann nur einer Gruppe
                        angehören.
                    </p>

                    @if($group->members->isNotEmpty())
                        <div class="flex flex-wrap gap-2">
                            @foreach($group->members as $member)
                                <span
                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-zinc-100 dark:bg-zinc-700 text-sm text-zinc-800 dark:text-zinc-200">
                                    {{ $member->sport_class }}
                                    <form method="POST"
                                          action="{{ route('sport-class-groups.members.destroy', [$group, $member]) }}"
                                          onsubmit="return confirm('Sportklasse „{{ $member->sport_class }}“ entfernen?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-zinc-400 hover:text-red-500">&times;</button>
                                    </form>
                                </span>
                            @endforeach
                        </div>
                    @else
                        <p class="text-sm text-zinc-400">Noch keine Sportklassen zugeordnet.</p>
                    @endif
                </div>
            @endif
        </div>
    </div>
@endsection
