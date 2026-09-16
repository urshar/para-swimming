{{--
    admin/documents/index — Dokumentenliste (Spec public-frontend §6). Dieselbe View für beide
    Einstiege: mit $meet gesetzt die Dokumente einer Veranstaltung, ohne $meet die Regelmente
    & Formulare (documentable = null).
--}}
@extends('layouts.app')

@section('title', $meet ? 'Dokumente – '.$meet->name : 'Regelmente & Formulare')

@section('content')

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
            {{ $meet ? 'Dokumente – '.$meet->name : 'Regelmente & Formulare' }}
        </h1>

        <div class="flex items-center flex-wrap gap-2 mt-4">
            @if($meet)
                <flux:button href="{{ route('meets.show', $meet) }}" variant="filled" icon="arrow-left" size="sm">
                    Zurück
                </flux:button>
            @endif

            <div class="ml-auto flex items-center flex-wrap gap-2">
                <flux:button
                    href="{{ $meet ? route('admin.meets.documents.create', $meet) : route('admin.documents.create') }}"
                    variant="primary" icon="plus">
                    Dokument hochladen
                </flux:button>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div
            class="mb-4 p-4 bg-green-50 dark:bg-green-950/20 border border-green-200 dark:border-green-800 rounded-xl text-sm text-green-700 dark:text-green-400">
            {{ session('success') }}
        </div>
    @endif

    <flux:table class="[&_td:first-child]:ps-4 [&_th:first-child]:ps-4 [&_td:last-child]:pe-4 [&_th:last-child]:pe-4">
        <flux:table.columns>
            <flux:table.column>Titel</flux:table.column>
            <flux:table.column>Kategorie</flux:table.column>
            <flux:table.column>Sprache</flux:table.column>
            <flux:table.column>Reihenfolge</flux:table.column>
            <flux:table.column>Status</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse($documents as $document)
                <flux:table.row>
                    <flux:table.cell class="font-medium text-zinc-900 dark:text-zinc-100">
                        {{ $document->title }}
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" color="zinc">
                            {{ __('public.documents.category.'.$document->category) }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell class="text-sm text-zinc-500">
                        {{ $document->locale ? strtoupper($document->locale) : 'neutral' }}
                    </flux:table.cell>
                    <flux:table.cell class="text-sm text-zinc-500">{{ $document->sort_order }}</flux:table.cell>
                    <flux:table.cell>
                        @if(! $document->is_public)
                            <flux:badge size="sm" color="zinc">Nicht öffentlich</flux:badge>
                        @elseif(! $document->published_at)
                            <flux:badge size="sm" color="amber">Entwurf</flux:badge>
                        @elseif($document->published_at->isFuture())
                            <flux:badge size="sm" color="amber">
                                Geplant ({{ $document->published_at->format('d.m.Y') }})
                            </flux:badge>
                        @else
                            <flux:badge size="sm" color="emerald">Veröffentlicht</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex items-center gap-1 justify-end">
                            <flux:button href="{{ route('admin.documents.edit', $document) }}" size="sm"
                                         variant="ghost" icon="pencil" class="text-amber-500!"/>
                            <form method="POST" action="{{ route('admin.documents.destroy', $document) }}"
                                  x-data="{ submit() { if (confirm('Dokument löschen?')) this.$el.submit() } }"
                                  @submit.prevent="submit()">
                                @csrf @method('DELETE')
                                <flux:button type="submit" size="sm" variant="ghost" icon="trash"
                                             class="text-red-500!"/>
                            </form>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="text-center py-12 text-zinc-400">
                        Noch keine Dokumente.
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

@endsection
