@extends('layouts.app')

@section('title', 'Verein wählen – ' . $meet->name)

@section('content')
    <div class="max-w-md">

        <div class="mb-6">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Verein wählen</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">{{ $meet->name }}</p>

            <div class="mt-4">
                <flux:button href="{{ route('meets.show', $meet) }}" variant="filled" icon="arrow-left" size="sm">
                    Zurück
                </flux:button>
            </div>
        </div>

        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-4">
                Als Admin siehst du die Meldungen vereinsweise — bitte zuerst einen Verein auswählen.
            </p>

            <form method="GET" action="{{ route($routeName, $meet) }}" class="space-y-4">
                <flux:field>
                    <flux:label>Verein</flux:label>
                    <flux:select variant="listbox" searchable name="club_id" placeholder="Verein wählen…" required>
                        @foreach($clubs as $club)
                            <flux:select.option value="{{ $club->id }}">{{ $club->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </flux:field>

                <flux:button type="submit" variant="primary">Weiter</flux:button>
            </form>
        </div>

    </div>
@endsection
