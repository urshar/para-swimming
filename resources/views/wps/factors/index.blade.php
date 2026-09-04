@extends('layouts.app')

@section('title', 'Kurzbahn-Umrechnung')

@section('content')
    <div class="max-w-5xl">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Kurzbahn-Umrechnung</h1>
            <div class="flex gap-2">
                <flux:button href="{{ route('wps.factors.report') }}" variant="filled" icon="chart-bar">
                    Faktorenbericht
                </flux:button>
            </div>
        </div>

        @if(session('success'))
            <div class="mb-4 p-4 bg-green-50 dark:bg-green-950/20 border border-green-200 dark:border-green-800 rounded-xl text-sm text-green-700 dark:text-green-400">
                {{ session('success') }}
            </div>
        @endif

        <div class="mb-6 p-4 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800 rounded-xl text-sm text-amber-700 dark:text-amber-400">
            <p class="font-medium mb-1">Wozu diese Faktoren dienen</p>
            <p>
                In Österreich wird im Para-Schwimmen ausschließlich Kurzbahn geschwommen,
                international dagegen Langbahn. Kurzbahnzeiten werden deshalb über diese Faktoren
                auf eine geschätzte Langbahnzeit umgerechnet
                (<span class="font-mono">Zeit&nbsp;LCM&nbsp;=&nbsp;Zeit&nbsp;SCM&nbsp;×&nbsp;Faktor</span>),
                und darauf wird die offizielle WPS-Tabelle angewandt.
            </p>
            <p class="mt-2">
                Die so ermittelten Punkte sind <strong>Schätzungen</strong> und nicht von World Para
                Swimming anerkannt. Bei Nachwuchsathleten fallen sie tendenziell zu hoch aus, weil die
                Faktoren überwiegend auf international startenden Athletinnen und Athleten beruhen.
            </p>
        </div>

        @if($factors->isEmpty())
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-8 text-center text-sm text-zinc-500 dark:text-zinc-400">
                Noch keine Faktoren hinterlegt. Ohne Faktor werden Kurzbahnergebnisse bei der
                Berechnung übersprungen.
            </div>
        @else
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Stil</flux:table.column>
                        <flux:table.column>Strecke</flux:table.column>
                        <flux:table.column>Klasse</flux:table.column>
                        <flux:table.column align="end">Faktor</flux:table.column>
                        <flux:table.column>Herkunft</flux:table.column>
                        <flux:table.column>Freigabe</flux:table.column>
                        <flux:table.column align="end">Athleten</flux:table.column>
                        <flux:table.column>Vertrauen</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows class="[&_td:first-child]:ps-4">
                        @foreach($factors as $factor)
                            <flux:table.row>
                                <flux:table.cell>{{ $factor->strokeType?->name_de ?? '–' }}</flux:table.cell>
                                <flux:table.cell>
                                    {{ $factor->distance ? $factor->distance.' m' : 'alle' }}
                                </flux:table.cell>
                                <flux:table.cell>{{ $factor->sport_class ?? 'alle' }}</flux:table.cell>
                                <flux:table.cell align="end" class="font-mono">
                                    {{ number_format($factor->factor, 4, ',', '.') }}
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge :color="$factor->sourceColor()" size="sm">
                                        {{ $factor->sourceLabel() }}
                                    </flux:badge>
                                </flux:table.cell>
                                {{-- Freigabe nur bei manuell gesetzten Faktoren: Sie sind die
                                     einzigen, die der Kalibrierungslauf nie aktualisiert. Wer
                                     sie wann gesetzt hat, ist deshalb die entscheidende
                                     Zusatzinformation. --}}
                                <flux:table.cell class="text-xs text-zinc-500 dark:text-zinc-400">
                                    @if($factor->isManual() && $factor->approved_at)
                                        {{ $factor->approved_at->format('d.m.Y') }}
                                        @if($factor->approvedBy)
                                            <span class="block">{{ $factor->approvedBy->name }}</span>
                                        @endif
                                    @else
                                        –
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell align="end">{{ $factor->sample_size ?? '–' }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm" :color="match($factor->confidence_level) {
                                        'high' => 'green', 'medium' => 'blue', default => 'zinc',
                                    }">
                                        {{ $factor->confidence_level }}
                                    </flux:badge>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <form method="POST"
                                          action="{{ route('wps.factors.destroy', $factor) }}"
                                          class="flex justify-end"
                                          x-data="{ submit() { if (confirm('Faktor löschen? Betroffene Kurzbahnergebnisse erhalten dann keine Punkte mehr.')) this.$el.submit() } }"
                                          @submit.prevent="submit()">
                                        @csrf
                                        @method('DELETE')
                                        <flux:button type="submit" size="sm" variant="ghost" icon="trash"
                                                     class="text-red-500!"/>
                                    </form>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        @endif
    </div>
@endsection
