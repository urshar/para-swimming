@extends('layouts.app')

@section('title', 'Athleten')

@section('content')

    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Athleten</h1>
        @if(auth()->user()?->is_admin)
        <div class="flex items-center gap-2">
            <flux:button href="{{ route('team-manager-import') }}" variant="filled" icon="arrow-up-tray">
                Team Manager übernehmen
            </flux:button>
            <flux:button href="{{ route('athletes.create') }}" variant="primary" icon="plus">
                Neuer Athlet
            </flux:button>
        </div>
        @endif
    </div>

    {{-- Filter --}}
    {{--
        Breiten für flux:input NICHT direkt per class="w-.." setzen: flux:input rendert
        einen Wrapper-Div mit fix eingebautem "w-full" (vendor/livewire/flux/.../input/index.blade.php).
        Eine übergebene Breitenklasse landet zwar auf demselben Wrapper, verliert aber im CSS gegen
        dieses eingebaute w-full — das Feld reißt dann auf volle Containerbreite auf und sprengt die
        Zeile (bei flux:select dagegen ungefährlich, das setzt sein w-full mit :where() ohne Spezifität).
        Deshalb hier in einen eigenen, schrumpfbaren Wrapper-Div mit der Breitenklasse packen.
    --}}
    @php
        // Startwerte der Filter fürs Alpine-x-data (indexFilters, siehe clubs/index.blade.php).
        // active_only fällt ohne Parameter auf "1" (nur aktive) zurück — Default gehört in die Config,
        // damit x-model die richtige Option vorbelegt.
        $filterConfig = [
            'search' => (string) request('search', ''),
            'gender' => (string) request('gender', ''),
            'sport_class' => (string) request('sport_class', ''),
            'nation_id' => (string) request('nation_id', ''),
            'club_id' => (string) request('club_id', ''),
            'active_only' => (string) request('active_only', '1'),
        ];
    @endphp
    {{-- Kein Filtern-Button: jedes Feld löst bei Änderung sofort eine neue Suche aus. Selects über
         x-model + $watch, Suche/Klasse als native Felder über x-model.debounce. Generische
         Alpine-Komponente in resources/js/index-filters.js. --}}
    <form method="GET" class="flex flex-wrap items-start gap-3 mb-2"
          x-data='indexFilters(@json($filterConfig))'>
        {{-- Aktiven Buchstaben (eigener Link-Filter darunter) beim Auto-Submit erhalten, damit ein
             Dropdown-Wechsel die Buchstabenauswahl nicht verwirft. --}}
        <input type="hidden" name="letter" value="{{ request('letter') }}"/>
        <div class="w-64 shrink-0">
            <flux:input name="search" x-model.debounce.500ms="search" placeholder="Name oder Lizenz…"
                        icon="magnifying-glass"/>
        </div>
        <flux:select variant="listbox" name="gender" x-model="gender" placeholder="Geschlecht" clearable class="w-36">
            <flux:select.option value="M">Herren</flux:select.option>
            <flux:select.option value="F">Damen</flux:select.option>
            <flux:select.option value="N">Nicht binär</flux:select.option>
        </flux:select>
        <div class="w-32 shrink-0">
            <flux:input name="sport_class" x-model.debounce.500ms="sport_class" placeholder="Klasse z.B. S4"/>
        </div>
        <flux:select variant="listbox" searchable name="nation_id" x-model="nation_id" placeholder="Nation" clearable class="w-40">
            @foreach($nations as $nation)
                <flux:select.option value="{{ $nation->id }}">{{ $nation->code }} – {{ $nation->name_de }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select variant="listbox" searchable name="club_id" x-model="club_id" placeholder="Verein" clearable class="w-48">
            @foreach($clubs as $club)
                <flux:select.option value="{{ $club->id }}">{{ $club->display_name }}</flux:select.option>
            @endforeach
        </flux:select>
        {{-- Aktiv-Filter: Standard = nur aktive --}}
        <flux:select variant="listbox" name="active_only" x-model="active_only" class="w-40">
            <flux:select.option value="1">Nur aktive</flux:select.option>
            <flux:select.option value="0">Alle (inkl. inaktive)</flux:select.option>
            <flux:select.option value="2">Nur inaktive</flux:select.option>
        </flux:select>
        @if(request()->hasAny(['search', 'letter', 'gender', 'sport_class', 'nation_id', 'club_id', 'active_only']))
            <div class="ml-auto flex items-center">
                <flux:button href="{{ route('athletes.index') }}" variant="filled" icon="x-mark"
                             class="text-red-500!">Zurücksetzen</flux:button>
            </div>
        @endif
    </form>

    {{-- Buchstaben-Filter nach Nachname --}}
    <div class="flex flex-wrap gap-1 mb-4">
        <flux:button href="{{ route('athletes.index', request()->except(['letter', 'page'])) }}"
                     size="sm" variant="{{ request('letter') ? 'ghost' : 'filled' }}">
            Alle
        </flux:button>
        @foreach(range('A', 'Z') as $letter)
            <flux:button href="{{ route('athletes.index', array_merge(request()->except('page'), ['letter' => $letter])) }}"
                         size="sm" variant="{{ request('letter') === $letter ? 'filled' : 'ghost' }}"
                         class="w-8 justify-center px-0">
                {{ $letter }}
            </flux:button>
        @endforeach
    </div>

    <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden p-4 [--flux-bleed:1rem]">
    <flux:table bleed>
        <flux:table.columns>
            <flux:table.column>Athlet</flux:table.column>
            <flux:table.column>Verein</flux:table.column>
            <flux:table.column>Nation</flux:table.column>
            <flux:table.column>Sport-Klassen</flux:table.column>
            <flux:table.column>Level</flux:table.column>
            <flux:table.column>Geburtsdatum</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse($athletes as $athlete)
                <flux:table.row class="{{ $athlete->is_active ? '' : 'opacity-50' }}">
                    <flux:table.cell>
                        <a href="{{ route('athletes.show', $athlete) }}"
                           class="font-medium text-zinc-900 dark:text-zinc-100 hover:text-blue-600 dark:hover:text-blue-400 transition-colors">
                            {{ $athlete->display_name }}
                        </a>
                        <div class="text-xs text-zinc-400 mt-0.5 flex items-center gap-1">
                            {{ match($athlete->gender) { 'M' => 'Herr', 'F' => 'Dame', default => 'Nicht binär' } }}
                            @if($athlete->license)
                                · {{ $athlete->license }}
                            @endif
                            @if(!$athlete->is_active)
                                <flux:badge size="sm" color="zinc">Inaktiv</flux:badge>
                            @endif
                        </div>
                    </flux:table.cell>
                    <flux:table.cell class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ $athlete->club?->display_name ?? '–' }}
                    </flux:table.cell>
                    <flux:table.cell>
                        @if($athlete->nation)
                            <flux:badge size="sm" color="zinc">{{ $athlete->nation->code }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ $athlete->sport_classes_display ?: '–' }}
                    </flux:table.cell>
                    <flux:table.cell class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ $athlete->level ?? '–' }}
                    </flux:table.cell>
                    <flux:table.cell class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ $athlete->birth_date?->format('d.m.Y') ?? '–' }}
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex items-center gap-1 justify-end">
                            <flux:button
                                href="{{ route('wps.athletes.show', ['athlete' => $athlete, 'from' => 'athlete']) }}"
                                size="sm" variant="ghost" icon="chart-bar" class="text-violet-500!"
                                title="WPS-Analyse"/>
                            <flux:button href="{{ route('athletes.show', $athlete) }}" size="sm" variant="ghost"
                                         icon="eye"/>
                            @if(auth()->user()?->is_admin)
                            <flux:button href="{{ route('athletes.edit', $athlete) }}" size="sm" variant="ghost"
                                         icon="pencil" class="text-amber-500!"/>
                            <form method="POST" action="{{ route('athletes.destroy', $athlete) }}"
                                  x-data="{ del() { if(confirm('Athlet wirklich löschen?')) this.$el.submit() } }"
                                  @submit.prevent="del()">
                                @csrf @method('DELETE')
                                <flux:button type="submit" size="sm" variant="ghost" icon="trash" class="text-red-500!"/>
                            </form>
                            @endif
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="7" class="text-center py-12 text-zinc-400">
                        Keine Athleten gefunden.
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
    </div>

    <div class="mt-4">{{ $athletes->links() }}</div>

@endsection
