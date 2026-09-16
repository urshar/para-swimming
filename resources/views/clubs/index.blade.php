{{-- clubs/index.blade.php --}}
@extends('layouts.app')

@section('title', 'Vereine')

@section('content')

    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Vereine</h1>
        <flux:button href="{{ route('clubs.create') }}" variant="primary" icon="plus">Neuer Verein</flux:button>
    </div>

    <form method="GET" class="flex flex-wrap items-center gap-3 mb-4">
        <div class="w-48 shrink-0">
            <flux:input name="search" value="{{ request('search') }}" placeholder="Name oder Kürzel…"
                        icon="magnifying-glass"/>
        </div>
        <flux:select variant="listbox" searchable name="nation_id" placeholder="Nation" clearable class="w-56">
            @foreach($nations as $nation)
                <flux:select.option value="{{ $nation->id }}" :selected="request('nation_id') == $nation->id">{{ $nation->code }} – {{ $nation->name_de }}</flux:select.option>
            @endforeach
        </flux:select>
        {{-- Filtern-Button an den rechten Rand der Zeile, wie im öffentlichen Bereich
             (public/qualifying-times/index.blade.php: ml-auto statt "letztes Element", sonst bleibt
             bei viel Platz in der Zeile sichtbarer Leerraum bis zum tatsächlichen rechten Rand). --}}
        <div class="ml-auto flex items-center gap-3">
            @if(request()->hasAny(['search', 'nation_id']))
                <flux:button href="{{ route('clubs.index') }}" variant="ghost" icon="x-mark">Zurücksetzen</flux:button>
            @endif
            <flux:button type="submit" variant="primary" icon="funnel">Filtern</flux:button>
        </div>
    </form>

    <flux:table class="[&_td:first-child]:ps-4 [&_th:first-child]:ps-4 [&_td:last-child]:pe-4 [&_th:last-child]:pe-4">
        <flux:table.columns>
            <flux:table.column>Verein</flux:table.column>
            <flux:table.column>Code</flux:table.column>
            <flux:table.column>Nation</flux:table.column>
            <flux:table.column>Typ</flux:table.column>
            <flux:table.column>Verband</flux:table.column>
            <flux:table.column>Athleten</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse($clubs as $club)
                <flux:table.row>
                    <flux:table.cell>
                        <a href="{{ route('clubs.show', $club) }}"
                           class="font-medium text-zinc-900 dark:text-zinc-100 hover:text-blue-600 transition-colors">
                            {{ $club->name }}
                        </a>
                        @if($club->short_name && $club->short_name !== $club->name)
                            <span class="text-xs text-zinc-400 ml-1">({{ $club->short_name }})</span>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="font-mono text-sm text-zinc-500">{{ $club->code ?? '–' }}</flux:table.cell>
                    <flux:table.cell>
                        @if($club->nation)
                            <x-flag code="{{ $club->nation->code }}" :label="$club->nation->name_de" class="w-7 h-5"/>
                        @else
                            –
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" color="{{ match($club->type) {
                            'NATIONALTEAM' => 'blue',
                            'REGIONALTEAM' => 'indigo',
                            'VERBAND'      => 'violet',
                            default        => 'zinc',
                        } }}">
                            {{ match($club->type) {
                                'CLUB'         => 'Verein',
                                'NATIONALTEAM' => 'Nationalteam',
                                'REGIONALTEAM' => 'Regionalteam',
                                'VERBAND'      => 'Verband',
                                'UNATTACHED'   => 'Ohne Zuordnung',
                                default        => $club->type,
                            } }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell class="text-sm text-zinc-500">
                        {{ $club->regional_association ?? '–' }}
                    </flux:table.cell>
                    <flux:table.cell class="text-sm text-zinc-500">
                        @if($club->athletes_active_count > 0 || $club->athletes_inactive_count > 0)
                            <span
                                class="text-zinc-900 dark:text-zinc-100 font-medium">{{ $club->athletes_active_count }}</span>
                            @if($club->athletes_inactive_count > 0)
                                <span class="text-zinc-400 ml-1">({{ $club->athletes_inactive_count }} inaktiv)</span>
                            @endif
                        @else
                            <span class="text-zinc-400">–</span>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex items-center gap-1 justify-end">
                            <flux:button href="{{ route('clubs.show', $club) }}" size="sm" variant="ghost" icon="eye"/>
                            <flux:button href="{{ route('clubs.edit', $club) }}" size="sm" variant="ghost"
                                         icon="pencil" class="text-amber-500!"/>
                            <form method="POST" action="{{ route('clubs.destroy', $club) }}"
                                  x-data="{ submit() { if (confirm('Verein löschen?')) this.$el.submit() } }"
                                  @submit.prevent="submit()">
                                @csrf @method('DELETE')
                                <flux:button type="submit" size="sm" variant="ghost" icon="trash" class="text-red-500!"/>
                            </form>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="7" class="text-center py-12 text-zinc-400">Keine Vereine gefunden.
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <div class="mt-4">{{ $clubs->links() }}</div>

@endsection
