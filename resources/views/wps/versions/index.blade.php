@extends('layouts.app')

@section('title', 'WPS Point Scores')

@section('content')
    <div class="max-w-5xl">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">WPS Point Scores</h1>
            <flux:button href="{{ route('wps.import') }}" variant="primary" icon="arrow-up-tray">
                Importieren
            </flux:button>
        </div>

        @if(session('success'))
            <div class="mb-4 p-4 bg-green-50 dark:bg-green-950/20 border border-green-200 dark:border-green-800 rounded-xl text-sm text-green-700 dark:text-green-400">
                {{ session('success') }}
            </div>
        @endif

        @if($errors->any())
            <div class="mb-4 p-4 bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-800 rounded-xl text-sm text-red-700 dark:text-red-400">
                @foreach($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        @if($versions->isEmpty())
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-8 text-center text-sm text-zinc-500 dark:text-zinc-400">
                Noch keine WPS-Version importiert.
            </div>
        @else
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Bezeichnung</flux:table.column>
                        <flux:table.column>Jahr</flux:table.column>
                        <flux:table.column>Version</flux:table.column>
                        <flux:table.column>Gültigkeit</flux:table.column>
                        <flux:table.column align="end">Parameter</flux:table.column>
                        <flux:table.column>Status</flux:table.column>
                        <flux:table.column/>
                    </flux:table.columns>
                    <flux:table.rows class="[&_td:first-child]:ps-4">
                        @foreach($versions as $version)
                            <flux:table.row>
                                <flux:table.cell>
                                    <a href="{{ route('wps.versions.show', $version) }}"
                                       class="font-medium text-blue-600 dark:text-blue-400 hover:underline">
                                        {{ $version->label }}
                                    </a>
                                </flux:table.cell>
                                <flux:table.cell>{{ $version->year }}</flux:table.cell>
                                <flux:table.cell>{{ $version->version ?? '—' }}</flux:table.cell>
                                <flux:table.cell>
                                    {{ $version->valid_from?->format('d.m.Y') ?? '—' }}
                                    –
                                    {{ $version->valid_until?->format('d.m.Y') ?? 'offen' }}
                                </flux:table.cell>
                                <flux:table.cell align="end">{{ $version->parameters_count }}</flux:table.cell>
                                <flux:table.cell>
                                    @if($version->isArchived())
                                        <flux:badge color="zinc" size="sm">Archiviert</flux:badge>
                                    @else
                                        <flux:badge color="green" size="sm">Aktiv</flux:badge>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>
                                    <div class="flex justify-end gap-1">
                                        @if($version->isArchived())
                                            <form method="POST"
                                                  action="{{ route('wps.versions.activate', $version) }}">
                                                @csrf
                                                <flux:button type="submit" size="sm" variant="ghost">
                                                    Aktivieren
                                                </flux:button>
                                            </form>
                                        @else
                                            <form method="POST"
                                                  action="{{ route('wps.versions.archive', $version) }}">
                                                @csrf
                                                <flux:button type="submit" size="sm" variant="ghost">
                                                    Archivieren
                                                </flux:button>
                                            </form>
                                        @endif

                                        <form method="POST"
                                              action="{{ route('wps.versions.destroy', $version) }}"
                                              onsubmit="return confirm('Version wirklich löschen?')">
                                            @csrf
                                            @method('DELETE')
                                            <flux:button type="submit" size="sm" variant="ghost"
                                                         icon="trash"/>
                                        </form>
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        @endif
    </div>
@endsection
