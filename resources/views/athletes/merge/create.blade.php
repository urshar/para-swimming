@extends('layouts.app')

@section('title', 'Athleten zusammenführen')

@section('content')
    @php
        $previewBase = url("athletes/$athlete->id/merge");
    @endphp

    <div class="max-w-2xl">
        <div class="mb-6">
            <div class="flex items-center gap-2">
                <flux:button href="{{ route('athletes.show', $athlete) }}" variant="primary" icon="arrow-left"
                             size="sm" title="Zurück" aria-label="Zurück"/>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Athleten zusammenführen</h1>
            </div>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                #{{ $athlete->id }} {{ $athlete->display_name }} bleibt. Die Dublette wählen, die in diesen Athleten
                übernommen und danach gelöscht wird. In der Vorschau lässt sich die Richtung noch tauschen.
            </p>
        </div>

        {{-- Wie bei der WPS-Analyse: durchsuchbares Auswahlfeld, das direkt zur Vorschau weiterleitet
             (x-model + $watch statt onchange, siehe CLAUDE.md zu flux:select). --}}
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6"
             x-data='{ athleteId: "", base: @json($previewBase) }'
             x-init="$watch('athleteId', (v) => { if (v) window.location = base + '/' + v; })">
            <flux:field>
                <flux:label>Dublette</flux:label>
                <flux:select variant="listbox" searchable placeholder="Suchen …" x-model="athleteId">
                    @foreach($athletes as $other)
                        <flux:select.option value="{{ $other->id }}">
                            {{ $other->display_name }}
                            · {{ $other->birth_date?->format('d.m.Y') ?? 'ohne Geburtsdatum' }}
                            @if($other->club)
                                · {{ $other->club->display_name }}
                            @endif
                            @if($other->license)
                                · {{ $other->license }}
                            @endif
                            · #{{ $other->id }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>
        </div>
    </div>
@endsection
