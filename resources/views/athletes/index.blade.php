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
    <form method="GET" class="flex flex-wrap gap-3 mb-4">
        <flux:input name="search" value="{{ request('search') }}" placeholder="Name oder Lizenz…"
                    icon="magnifying-glass" class="w-64"/>
        <flux:select name="gender" placeholder="Geschlecht" class="w-36">
            <option value="">Alle</option>
            <option value="M" @selected(request('gender') === 'M')>Herren</option>
            <option value="F" @selected(request('gender') === 'F')>Damen</option>
            <option value="N" @selected(request('gender') === 'N')>Nicht binär</option>
        </flux:select>
        <flux:input name="sport_class" value="{{ request('sport_class') }}" placeholder="Klasse z.B. S4" class="w-32"/>
        <flux:select name="nation_id" placeholder="Nation" class="w-40">
            <option value="">Alle Nationen</option>
            @foreach($nations as $nation)
                <option value="{{ $nation->id }}" @selected(request('nation_id') == $nation->id)>
                    {{ $nation->code }} – {{ $nation->name_de }}
                </option>
            @endforeach
        </flux:select>
        <flux:select name="club_id" placeholder="Verein" class="w-48">
            <option value="">Alle Vereine</option>
            @foreach($clubs as $club)
                <option value="{{ $club->id }}" @selected(request('club_id') == $club->id)>
                    {{ $club->display_name }}
                </option>
            @endforeach
        </flux:select>
        {{-- Aktiv-Filter: Standard = nur aktive --}}
        <flux:select name="active_only" class="w-40">
            <option value="1" @selected(request('active_only', '1') === '1')>Nur aktive</option>
            <option value="0" @selected(request('active_only') === '0')>Alle (inkl. inaktive)</option>
        </flux:select>
        <flux:button type="submit" icon="funnel">Filtern</flux:button>
        @if(request()->hasAny(['search', 'gender', 'sport_class', 'nation_id', 'club_id', 'active_only']))
            <flux:button href="{{ route('athletes.index') }}" variant="ghost" icon="x-mark">Zurücksetzen</flux:button>
        @endif
    </form>

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
                            <flux:button href="{{ route('athletes.show', $athlete) }}" size="sm" variant="ghost"
                                         icon="eye"/>
                            <flux:button href="{{ route('athletes.edit', $athlete) }}" size="sm" variant="ghost"
                                         icon="pencil"/>
                            <form method="POST" action="{{ route('athletes.destroy', $athlete) }}"
                                  x-data="{ del() { if(confirm('Athlet wirklich löschen?')) $el.submit() } }"
                                  @submit.prevent="del()">
                                @csrf @method('DELETE')
                                <flux:button type="submit" size="sm" variant="ghost" icon="trash" class="text-red-500"/>
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
