@extends('layouts.app')

@section('title', 'Klassifizierer')

@section('content')

    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Klassifizierer</h1>
        <flux:button href="{{ route('classifiers.create') }}" variant="primary" icon="plus">
            Neuer Klassifizierer
        </flux:button>
    </div>

    {{-- Filter --}}
    <form method="GET" class="flex flex-wrap gap-3 mb-4">
        <flux:input name="search" value="{{ request('search') }}" placeholder="Name oder E-Mail…"
                    icon="magnifying-glass" class="w-64"/>
        <flux:select name="type" placeholder="Typ" class="w-48">
            <option value="">Alle Typen</option>
            <option value="MED" @selected(request('type') === 'MED')>Medizinisch</option>
            <option value="TECH" @selected(request('type') === 'TECH')>Technisch</option>
        </flux:select>
        <flux:select name="nation" placeholder="Nation" class="w-32">
            <option value="">Alle Nationen</option>
            @foreach($nations as $nation)
                <option value="{{ $nation }}" @selected(request('nation') === $nation)>{{ $nation }}</option>
            @endforeach
        </flux:select>
        <flux:select name="active_only" class="w-40">
            <option value="1" @selected(request('active_only', '1') === '1')>Nur aktive</option>
            <option value="0" @selected(request('active_only') === '0')>Alle</option>
        </flux:select>
        <flux:button type="submit" icon="funnel">Filtern</flux:button>
        @if(request()->hasAny(['search', 'type', 'nation', 'active_only']))
            <flux:button href="{{ route('classifiers.index') }}" variant="ghost" icon="x-mark">Zurücksetzen
            </flux:button>
        @endif
    </form>

    <flux:table class="[&_td:first-child]:ps-4 [&_th:first-child]:ps-4 [&_td:last-child]:pe-4 [&_th:last-child]:pe-4">
        <flux:table.columns>
            <flux:table.column>Name</flux:table.column>
            <flux:table.column>Typ</flux:table.column>
            <flux:table.column>Nation</flux:table.column>
            <flux:table.column>E-Mail</flux:table.column>
            <flux:table.column>Klassifikationen</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse($classifiers as $classifier)
                <flux:table.row class="{{ $classifier->is_active ? '' : 'opacity-50' }}">
                    <flux:table.cell>
                        <a href="{{ route('classifiers.show', $classifier) }}"
                           class="font-medium text-zinc-900 dark:text-zinc-100 hover:text-blue-600 dark:hover:text-blue-400 transition-colors">
                            {{ $classifier->full_name }}
                        </a>
                        <div class="text-xs text-zinc-400 mt-0.5">
                            {{ match($classifier->gender ?? '') { 'M' => 'Herr', 'F' => 'Dame', 'N' => 'Nicht binär', default => '' } }}
                            @if(!$classifier->is_active)
                                <flux:badge size="sm" color="zinc">Inaktiv</flux:badge>
                            @endif
                        </div>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" color="{{ $classifier->type === 'MED' ? 'red' : 'blue' }}">
                            {{ $classifier->type_name }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ $classifier->nation ?? '–' }}
                    </flux:table.cell>
                    <flux:table.cell class="text-sm text-zinc-500 dark:text-zinc-400">
                        @if($classifier->email)
                            <a href="mailto:{{ $classifier->email }}" class="hover:text-blue-600 transition-colors">
                                {{ $classifier->email }}
                            </a>
                        @else
                            –
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="text-sm text-zinc-500 dark:text-zinc-400">
                        @php
                            $total = $classifier->classifications_as_med_count
                                   + $classifier->classifications_as_tech1_count
                                   + $classifier->classifications_as_tech2_count;
                        @endphp
                        {{ $total ?: '–' }}
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex items-center gap-1 justify-end">
                            <flux:button href="{{ route('classifiers.show', $classifier) }}" size="sm" variant="ghost"
                                         icon="eye"/>
                            <flux:button href="{{ route('classifiers.edit', $classifier) }}" size="sm" variant="ghost"
                                         icon="pencil"/>
                            <form method="POST" action="{{ route('classifiers.destroy', $classifier) }}"
                                  x-data="{ del() { if(confirm('Klassifizierer wirklich löschen?')) this.$el.submit() } }"
                                  @submit.prevent="del()">
                                @csrf @method('DELETE')
                                <flux:button type="submit" size="sm" variant="ghost" icon="trash" class="text-red-500"/>
                            </form>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="text-center py-12 text-zinc-400">
                        Keine Klassifizierer gefunden.
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <div class="mt-4">{{ $classifiers->links() }}</div>

@endsection
