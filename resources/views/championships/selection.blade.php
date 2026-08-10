@extends('layouts.app')

@section('title', 'Auswahl-Rangliste')

@section('content')
    @php
        use App\Http\Controllers\ChampionshipSelectionController as Auswahl;
        use App\Support\AthleteAge;
        use App\Support\TimeParser;
    @endphp

    <div class="max-w-5xl">
        <div class="flex items-start justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Auswahl-Rangliste</h1>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    {{ $championship->display_name }} ·
                    Qualifikationszeitraum {{ $championship->qualification_start->format('d.m.Y') }}
                    bis {{ $championship->qualification_end->format('d.m.Y') }}
                </p>
            </div>
            <div class="flex gap-2">
                <flux:button href="{{ route('championships.selection.pdf', $championship) }}"
                             variant="filled" size="sm" icon="document-arrow-down">PDF
                </flux:button>
                <flux:button href="{{ route('championships.qualified', $championship) }}"
                             variant="ghost" size="sm">Qualifikanten
                </flux:button>
                <flux:button href="{{ route('championships.show', $championship) }}"
                             variant="ghost" size="sm">Normen
                </flux:button>
            </div>
        </div>

        <div
            class="mb-6 p-4 bg-zinc-50 dark:bg-zinc-900/40 border border-zinc-200 dark:border-zinc-700 rounded-xl text-sm text-zinc-600 dark:text-zinc-400">
            Sortiert nach WPS-Punkten, nicht nach Zeit — Zeiten sind über Bewerbe und Sportklassen
            hinweg nicht vergleichbar. Grundlage sind ausschließlich nachgewiesene Qualifikationen.
            Die Reihenfolge ist ein Vorschlag; die Auswahl trifft der Verband.
        </div>

        <form method="GET" class="mb-6 flex flex-wrap items-end gap-3">
            <flux:field class="w-40">
                <flux:label>Beste n je Liste</flux:label>
                <flux:input name="limit" type="number" min="1" value="{{ $limit }}" placeholder="alle"/>
            </flux:field>
            <flux:button type="submit" variant="filled" size="sm">Anwenden</flux:button>
            @if($limit !== null)
                <flux:button href="{{ route('championships.selection', $championship) }}"
                             variant="ghost" size="sm">Alle zeigen
                </flux:button>
            @endif
        </form>

        {{-- ── Gesamtrangliste der Athleten ─────────────────────────────────── --}}
        <h2 class="mb-2 text-sm font-semibold text-zinc-700 dark:text-zinc-300">
            Athleten gesamt
        </h2>
        <p class="mb-3 text-xs text-zinc-500 dark:text-zinc-400">
            Gemessen an der besten einzelnen Punktzahl, nicht an der Summe: Wer viele Bewerbe
            schwimmt, ist deshalb nicht stärker aufgestellt.
        </p>

        <div
            class="mb-8 bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
            <flux:table class="[&_td:first-child]:ps-4 [&_th:first-child]:ps-4">
                <flux:table.columns>
                    <flux:table.column>Rang</flux:table.column>
                    <flux:table.column>Athlet</flux:table.column>
                    <flux:table.column>Alter</flux:table.column>
                    <flux:table.column>Verein</flux:table.column>
                    <flux:table.column>Bester Bewerb</flux:table.column>
                    <flux:table.column>Zeit</flux:table.column>
                    <flux:table.column>Punkte</flux:table.column>
                    <flux:table.column>Normen</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse(Auswahl::applyLimit($athleteRanking, $limit) as $eintrag)
                        <flux:table.row>
                            <flux:table.cell class="font-mono">{{ $eintrag->rank ?? '–' }}</flux:table.cell>
                            <flux:table.cell>{{ $eintrag->athlete->full_name }}</flux:table.cell>
                            <flux:table.cell class="text-xs whitespace-nowrap">
                                {{ AthleteAge::label($eintrag->athlete, $championship->year) ?? '–' }}
                            </flux:table.cell>
                            <flux:table.cell class="text-xs">{{ $eintrag->athlete->club?->name }}</flux:table.cell>
                            <flux:table.cell class="text-xs">
                                {{ $eintrag->row->eventLabel }}
                                <span class="font-mono text-zinc-500">{{ $eintrag->row->sportClass }}</span>
                            </flux:table.cell>
                            <flux:table.cell class="font-mono text-xs">
                                {{ TimeParser::display($eintrag->row->status->swimTime) }}
                            </flux:table.cell>
                            <flux:table.cell class="font-mono">
                                @if($eintrag->points === null)
                                    <span class="text-zinc-400">ohne Bewertung</span>
                                @else
                                    {{ $eintrag->points }}
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>{{ $eintrag->fulfilledCount }}</flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="8">
                                <span class="text-sm text-zinc-500 dark:text-zinc-400">
                                    Bislang hat niemand eine Norm nachweislich erfüllt.
                                </span>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>

        {{-- ── Ranglisten je Bewerb ─────────────────────────────────────────── --}}
        <h2 class="mb-3 text-sm font-semibold text-zinc-700 dark:text-zinc-300">Je Bewerb</h2>

        @foreach($eventRankings as $bezeichnung => $eintraege)
            <div class="mb-6">
                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                    {{ $bezeichnung }}
                </h3>
                <div
                    class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                    <flux:table class="[&_td:first-child]:ps-4 [&_th:first-child]:ps-4">
                        <flux:table.columns>
                            <flux:table.column>Rang</flux:table.column>
                            <flux:table.column>Athlet</flux:table.column>
                            <flux:table.column>Alter</flux:table.column>
                            <flux:table.column>Verein</flux:table.column>
                            <flux:table.column>Zeit</flux:table.column>
                            <flux:table.column>Punkte</flux:table.column>
                            <flux:table.column>Wettkampf</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach(Auswahl::applyLimit($eintraege, $limit) as $eintrag)
                                <flux:table.row>
                                    <flux:table.cell class="font-mono">{{ $eintrag->rank ?? '–' }}</flux:table.cell>
                                    <flux:table.cell>{{ $eintrag->athlete->full_name }}</flux:table.cell>
                                    <flux:table.cell class="text-xs whitespace-nowrap">
                                        {{ AthleteAge::label($eintrag->athlete, $championship->year) ?? '–' }}
                                    </flux:table.cell>
                                    <flux:table.cell class="text-xs">{{ $eintrag->athlete->club?->name }}</flux:table.cell>
                                    <flux:table.cell class="font-mono text-xs">
                                        {{ TimeParser::display($eintrag->row->status->swimTime) }}
                                    </flux:table.cell>
                                    <flux:table.cell class="font-mono">
                                        @if($eintrag->points === null)
                                            <span class="text-zinc-400">ohne Bewertung</span>
                                        @else
                                            {{ $eintrag->points }}
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell class="text-xs">
                                        {{ $eintrag->row->status->meetName }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            </div>
        @endforeach
    </div>
@endsection
