@extends('layouts.app')

@section('title', 'Athleten')

@section('content')

    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Athleten</h1>
        <flux:button href="{{ route('athletes.create') }}" variant="primary" icon="plus">
            Neuer Athlet
        </flux:button>
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
    <form method="GET" class="flex flex-wrap items-start gap-3 mb-2">
        <div class="w-64 shrink-0">
            <flux:input name="search" value="{{ request('search') }}" placeholder="Name oder Lizenz…"
                        icon="magnifying-glass"/>
        </div>
        <flux:select variant="listbox" name="gender" placeholder="Geschlecht" clearable class="w-36">
            <flux:select.option value="M" :selected="request('gender') === 'M'">Herren</flux:select.option>
            <flux:select.option value="F" :selected="request('gender') === 'F'">Damen</flux:select.option>
            <flux:select.option value="N" :selected="request('gender') === 'N'">Nicht binär</flux:select.option>
        </flux:select>
        <div class="w-32 shrink-0">
            <flux:input name="sport_class" value="{{ request('sport_class') }}" placeholder="Klasse z.B. S4"/>
        </div>
        <flux:select variant="listbox" searchable name="nation_id" placeholder="Nation" clearable class="w-40">
            @foreach($nations as $nation)
                <flux:select.option value="{{ $nation->id }}" :selected="request('nation_id') == $nation->id">{{ $nation->code }} – {{ $nation->name_de }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select variant="listbox" searchable name="club_id" placeholder="Verein" clearable class="w-48">
            @foreach($clubs as $club)
                <flux:select.option value="{{ $club->id }}" :selected="request('club_id') == $club->id">{{ $club->display_name }}</flux:select.option>
            @endforeach
        </flux:select>
        {{-- Aktiv-Filter: Standard = nur aktive --}}
        <flux:select variant="listbox" name="active_only" class="w-40">
            <flux:select.option value="1" :selected="request('active_only', '1') === '1'">Nur aktive</flux:select.option>
            <flux:select.option value="0" :selected="request('active_only') === '0'">Alle (inkl. inaktive)</flux:select.option>
            <flux:select.option value="2" :selected="request('active_only') === '2'">Nur inaktive</flux:select.option>
        </flux:select>
        {{-- Filtern-Button an den rechten Rand der Zeile, wie im öffentlichen Bereich
             (public/qualifying-times/index.blade.php: ml-auto statt "letztes Element"). --}}
        <div class="ml-auto flex items-center gap-3">
            @if(request()->hasAny(['search', 'letter', 'gender', 'sport_class', 'nation_id', 'club_id', 'active_only']))
                <flux:button href="{{ route('athletes.index') }}" variant="ghost" icon="x-mark">Zurücksetzen</flux:button>
            @endif
            <flux:button type="submit" variant="primary" icon="funnel">Filtern</flux:button>
        </div>
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

    <flux:table class="[&_td:first-child]:ps-4 [&_th:first-child]:ps-4 [&_td:last-child]:pe-4 [&_th:last-child]:pe-4">
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
                            <flux:button href="{{ route('wps.athletes.show', $athlete) }}" size="sm"
                                         variant="ghost" icon="chart-bar" class="text-violet-500!" title="WPS-Analyse"/>
                            <flux:button href="{{ route('athletes.show', $athlete) }}" size="sm" variant="ghost"
                                         icon="eye"/>
                            <flux:button href="{{ route('athletes.edit', $athlete) }}" size="sm" variant="ghost"
                                         icon="pencil" class="text-amber-500!"/>
                            <form method="POST" action="{{ route('athletes.destroy', $athlete) }}"
                                  x-data="{ del() { if(confirm('Athlet wirklich löschen?')) $el.submit() } }"
                                  @submit.prevent="del()">
                                @csrf @method('DELETE')
                                <flux:button type="submit" size="sm" variant="ghost" icon="trash" class="text-red-500!"/>
                            </form>
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

    <div class="mt-4">{{ $athletes->links() }}</div>

@endsection
