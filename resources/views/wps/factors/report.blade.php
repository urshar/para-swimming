@extends('layouts.app')

@section('title', 'Faktorenbericht')

@section('content')
    <div class="max-w-5xl">
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-3">
                <flux:button href="{{ route('wps.factors.index') }}" variant="ghost" icon="arrow-left" size="sm"/>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Faktorenbericht</h1>
            </div>
            <form method="POST" action="{{ route('wps.factors.calibrate') }}"
                  x-data="{ submit() { if (confirm('Faktoren aus den eigenen Ergebnissen neu ermitteln?')) this.$el.submit() } }"
                  @submit.prevent="submit()">
                @csrf
                <flux:button type="submit" variant="primary" icon="arrow-path">
                    Aus eigenen Daten ermitteln
                </flux:button>
            </form>
        </div>

        @if(session('success'))
            <div class="mb-4 p-4 bg-green-50 dark:bg-green-950/20 border border-green-200 dark:border-green-800 rounded-xl text-sm text-green-700 dark:text-green-400">
                {{ session('success') }}
            </div>
        @endif

        <div class="mb-6 p-4 bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-200 dark:border-zinc-700 rounded-xl text-sm text-zinc-600 dark:text-zinc-400">
            Gegenübergestellt wird der tatsächlich <strong>angesetzte</strong> Faktor und der aus den
            eigenen Ergebnissen <strong>beobachtete</strong> Wert. Grundlage sind Athletinnen und
            Athleten mit Zeiten auf beiden Bahnlängen im selben Bewerb und derselben Sportklasse.
            Ab {{ $minSampleSize }} Athleten gilt eine Kombination als ausreichend belegt; darunter
            wird kein eigener Faktor gebildet.
        </div>

        @if($rows->isEmpty())
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-8 text-center text-sm text-zinc-500 dark:text-zinc-400">
                Keine Vergleichspaare vorhanden. Dafür werden Athletinnen und Athleten benötigt, die
                denselben Bewerb in derselben Sportklasse sowohl auf der Lang- als auch auf der
                Kurzbahn geschwommen sind.
            </div>
        @else
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Bewerb</flux:table.column>
                        <flux:table.column>Klasse</flux:table.column>
                        <flux:table.column align="end">Athleten</flux:table.column>
                        <flux:table.column align="end">beobachtet</flux:table.column>
                        <flux:table.column align="end">Spanne</flux:table.column>
                        <flux:table.column align="end">angesetzt</flux:table.column>
                        <flux:table.column align="end">Abweichung</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows class="[&_td:first-child]:ps-4">
                        @foreach($rows as $row)
                            <flux:table.row>
                                <flux:table.cell>{{ $row['distance'] }} m {{ $row['lenex_code'] }}</flux:table.cell>
                                <flux:table.cell>{{ $row['sport_class'] }}</flux:table.cell>
                                <flux:table.cell align="end">
                                    <span @class(['text-zinc-400' => ! $row['sufficient']])>
                                        {{ $row['sample_size'] }}
                                    </span>
                                </flux:table.cell>
                                <flux:table.cell align="end" class="font-mono">
                                    {{ number_format($row['median'], 4, ',', '.') }}
                                </flux:table.cell>
                                <flux:table.cell align="end" class="font-mono text-xs text-zinc-500">
                                    {{ number_format($row['min'], 3, ',', '.') }}–{{ number_format($row['max'], 3, ',', '.') }}
                                </flux:table.cell>
                                <flux:table.cell align="end" class="font-mono">
                                    {{ $row['applied_factor'] !== null
                                        ? number_format($row['applied_factor'], 4, ',', '.')
                                        : '–' }}
                                </flux:table.cell>
                                <flux:table.cell align="end" class="font-mono">
                                    @if($row['deviation'] !== null)
                                        {{-- Große Abweichungen sind der eigentliche Zweck des Berichts:
                                             dort trägt der angesetzte Faktor die Realität nicht. --}}
                                        <span @class([
                                            'text-amber-600 dark:text-amber-400' => abs($row['deviation']) >= 0.01,
                                        ])>
                                            {{ $row['deviation'] >= 0 ? '+' : '' }}{{ number_format($row['deviation'], 4, ',', '.') }}
                                        </span>
                                    @else
                                        –
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        @endif
    </div>
@endsection
