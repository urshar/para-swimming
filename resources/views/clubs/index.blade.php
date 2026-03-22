{{-- clubs/index.blade.php --}}
@extends('layouts.app')

@section('title', 'Vereine')

@section('content')

<div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Vereine</h1>
    <flux:button href="{{ route('clubs.create') }}" variant="primary" icon="plus">Neuer Verein</flux:button>
</div>

<form method="GET" class="flex gap-3 mb-4">
    <flux:input name="search" value="{{ request('search') }}" placeholder="Name oder Kürzel…" icon="magnifying-glass" class="w-64" />
    <flux:select name="nation_id" placeholder="Nation" class="w-40">
        <option value="">Alle Nationen</option>
        @foreach($nations as $nation)
            <option value="{{ $nation->id }}" @selected(request('nation_id') == $nation->id)>{{ $nation->code }}</option>
        @endforeach
    </flux:select>
    <flux:button type="submit" icon="funnel">Filtern</flux:button>
    @if(request()->hasAny(['search', 'nation_id']))
        <flux:button href="{{ route('clubs.index') }}" variant="ghost" icon="x-mark">Zurücksetzen</flux:button>
    @endif
</form>

<flux:table>
    <flux:table.columns>
        <flux:table.column>Verein</flux:table.column>
        <flux:table.column>Kürzel</flux:table.column>
        <flux:table.column>Nation</flux:table.column>
        <flux:table.column>Typ</flux:table.column>
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
                <flux:table.cell><flux:badge size="sm" color="zinc">{{ $club->nation?->code }}</flux:badge></flux:table.cell>
                <flux:table.cell class="text-sm text-zinc-500">{{ $club->type !== 'CLUB' ? $club->type : '–' }}</flux:table.cell>
                <flux:table.cell class="text-sm text-zinc-500">{{ $club->athletes_count }}</flux:table.cell>
                <flux:table.cell>
                    <div class="flex items-center gap-1 justify-end">
                        <flux:button href="{{ route('clubs.show', $club) }}" size="sm" variant="ghost" icon="eye" />
                        <flux:button href="{{ route('clubs.edit', $club) }}" size="sm" variant="ghost" icon="pencil" />
                        <form method="POST" action="{{ route('clubs.destroy', $club) }}"
                              x-data @submit.prevent="if(confirm('Verein löschen?')) $el.submit()">
                            @csrf @method('DELETE')
                            <flux:button type="submit" size="sm" variant="ghost" icon="trash" class="text-red-500" />
                        </form>
                    </div>
                </flux:table.cell>
            </flux:table.row>
        @empty
            <flux:table.row>
                <flux:table.cell colspan="6" class="text-center py-12 text-zinc-400">Keine Vereine gefunden.</flux:table.cell>
            </flux:table.row>
        @endforelse
    </flux:table.rows>
</flux:table>

<div class="mt-4">{{ $clubs->links() }}</div>

@endsection
