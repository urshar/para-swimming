@extends('layouts.app')

@section('title', $version->label)

@section('content')
    <div class="max-w-5xl">
        <div class="flex items-center gap-3 mb-6">
            <flux:button href="{{ route('wps.versions.index') }}" variant="ghost" icon="arrow-left" size="sm"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $version->label }}</h1>
            @if($version->isArchived())
                <flux:badge color="zinc" size="sm">Archiviert</flux:badge>
            @endif
        </div>

        @if(session('success'))
            <div class="mb-4 p-4 bg-green-50 dark:bg-green-950/20 border border-green-200 dark:border-green-800 rounded-xl text-sm text-green-700 dark:text-green-400">
                {{ session('success') }}
            </div>
        @endif

        <form method="GET" class="flex flex-wrap items-end gap-3 mb-4">
            <flux:field>
                <flux:label>Geschlecht</flux:label>
                <select name="gender"
                        class="rounded-lg border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800 text-sm">
                    <option value="">alle</option>
                    <option value="M" @if(($filters['gender'] ?? '') === 'M') selected @endif>männlich</option>
                    <option value="F" @if(($filters['gender'] ?? '') === 'F') selected @endif>weiblich</option>
                </select>
            </flux:field>

            <flux:field>
                <flux:label>Sportklasse</flux:label>
                <select name="sport_class"
                        class="rounded-lg border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800 text-sm">
                    <option value="">alle</option>
                    @foreach($sportClasses as $sportClass)
                        <option value="{{ $sportClass }}"
                                @if(($filters['sport_class'] ?? '') === $sportClass) selected @endif>
                            {{ $sportClass }}
                        </option>
                    @endforeach
                </select>
            </flux:field>

            <flux:field>
                <flux:label>Bahnlänge</flux:label>
                <select name="course"
                        class="rounded-lg border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800 text-sm">
                    <option value="">alle</option>
                    <option value="LCM" @if(($filters['course'] ?? '') === 'LCM') selected @endif>LCM</option>
                    <option value="SCM" @if(($filters['course'] ?? '') === 'SCM') selected @endif>SCM</option>
                </select>
            </flux:field>

            <flux:button type="submit" variant="filled">Filtern</flux:button>
            <flux:button href="{{ route('wps.versions.show', $version) }}" variant="ghost">Zurücksetzen</flux:button>
        </form>

        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Bahn</flux:table.column>
                    <flux:table.column>Geschlecht</flux:table.column>
                    <flux:table.column>Bewerb</flux:table.column>
                    <flux:table.column>Klasse</flux:table.column>
                    <flux:table.column align="end">a</flux:table.column>
                    <flux:table.column align="end">b</flux:table.column>
                    <flux:table.column align="end">c</flux:table.column>
                    <flux:table.column>Art</flux:table.column>
                </flux:table.columns>
                <flux:table.rows class="[&_td:first-child]:ps-4">
                    @foreach($parameters as $parameter)
                        <flux:table.row>
                            <flux:table.cell>{{ $parameter->course }}</flux:table.cell>
                            <flux:table.cell>{{ $parameter->gender }}</flux:table.cell>
                            <flux:table.cell>
                                {{ $parameter->distance }} m {{ $parameter->strokeType?->name_de }}
                            </flux:table.cell>
                            <flux:table.cell>{{ $parameter->sport_class }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $parameter->parameter_a }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $parameter->parameter_b }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $parameter->parameter_c }}</flux:table.cell>
                            <flux:table.cell>
                                @if($parameter->official)
                                    <flux:badge color="green" size="sm">offiziell</flux:badge>
                                @else
                                    <flux:badge color="amber" size="sm">abgeleitet</flux:badge>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>

        <div class="mt-4">
            {{ $parameters->links() }}
        </div>
    </div>
@endsection
