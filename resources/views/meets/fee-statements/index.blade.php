@php
    use App\Support\ClubFeeStatement;
    use App\Support\ListUrl;
    use App\Support\Money;
@endphp

@extends('layouts.app')

@section('title', 'Meldegeld-Abrechnung – ' . $meet->name)

@section('content')
    <div class="mb-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                {{-- Einstieg über das "Listen"-Dropdown in "Alle Meldungen" → gemerkte Meldungsliste (ListUrl). --}}
                <flux:button href="{{ ListUrl::to('entries') }}" variant="primary" icon="arrow-left" size="sm"
                             title="Zurück" aria-label="Zurück"/>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Meldegeld-Abrechnung</h1>
            </div>
            <div class="flex items-center gap-2">
                <flux:button href="{{ route('meets.fees.edit', $meet) }}" variant="filled" size="sm" icon="banknotes">
                    Meldegelder bearbeiten
                </flux:button>
                <flux:button href="{{ route('meets.entry-lists.meldegeld.pdf', $meet) }}" target="_blank" variant="filled"
                             size="sm" icon="document-text" class="text-blue-500!">
                    PDF
                </flux:button>
            </div>
        </div>
        <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">{{ $meet->name }}</p>
    </div>

    @if($statements->isEmpty())
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-8 text-center text-sm text-zinc-500 dark:text-zinc-400">
            Für diese Veranstaltung gibt es noch keine berechneten Meldungen.
        </div>
    @else
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden p-4 [--flux-bleed:1rem]">
            <flux:table bleed>
                <flux:table.columns>
                    <flux:table.column>Verein</flux:table.column>
                    <flux:table.column align="end">Athleten</flux:table.column>
                    <flux:table.column align="end">Einzelstarts</flux:table.column>
                    <flux:table.column align="end">Staffeln</flux:table.column>
                    <flux:table.column align="end">Startgebühren</flux:table.column>
                    <flux:table.column align="end">Staffelgebühren</flux:table.column>
                    <flux:table.column align="end">Pauschalen</flux:table.column>
                    <flux:table.column align="end">Summe</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach($statements as $statement)
                        @php /** @var ClubFeeStatement $statement */ @endphp
                        <flux:table.row>
                            <flux:table.cell>
                                <a href="{{ route('meets.fees.club', [$meet, $statement->club]) }}"
                                   class="font-medium text-zinc-900 dark:text-white hover:underline">
                                    {{ $statement->club->display_name }}
                                </a>
                            </flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $statement->athletes->count() }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $statement->startCount }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ $statement->relays->count() }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ Money::format($statement->startsCents()) }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ Money::format($statement->relaysCents()) }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ Money::format($statement->flatCents()) }}</flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums font-semibold text-zinc-900 dark:text-white">
                                {{ Money::format($statement->totalCents) }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                    <flux:table.row>
                        <flux:table.cell class="font-semibold text-zinc-900 dark:text-white">Gesamt</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $statements->sum(fn (ClubFeeStatement $s) => $s->athletes->count()) }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $statements->sum('startCount') }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $statements->sum(fn (ClubFeeStatement $s) => $s->relays->count()) }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ Money::format($statements->sum(fn (ClubFeeStatement $s) => $s->startsCents())) }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ Money::format($statements->sum(fn (ClubFeeStatement $s) => $s->relaysCents())) }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ Money::format($statements->sum(fn (ClubFeeStatement $s) => $s->flatCents())) }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums font-semibold text-zinc-900 dark:text-white">
                            {{ Money::format($totalCents) }}
                        </flux:table.cell>
                    </flux:table.row>
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
@endsection
