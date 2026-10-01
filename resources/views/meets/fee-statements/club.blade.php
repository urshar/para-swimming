@php
    use App\Support\AthleteFees;
    use App\Support\FeeLine;
    use App\Support\Money;
@endphp

@extends('layouts.app')

@section('title', 'Meldegeld – ' . $club->display_name . ' – ' . $meet->name)

@section('content')
    @php
        // Admin kommt aus der Übersicht aller Vereine, der Verein aus seiner Meldungsliste ("Listen"-Dropdown).
        $backUrl = auth()->user()->is_admin ? route('meets.fees.index', $meet) : route('club-entries.index', $meet);
    @endphp

    <div class="max-w-3xl">
        <div class="mb-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <flux:button href="{{ $backUrl }}" variant="primary" icon="arrow-left" size="sm"
                                 title="Zurück" aria-label="Zurück"/>
                    <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Meldegeld – {{ $club->display_name }}</h1>
                </div>
                @unless(auth()->user()->is_admin)
                    <flux:button href="{{ route('meets.entry-lists.meldegeld.pdf', $meet) }}" target="_blank"
                                 variant="filled" size="sm" icon="document-text" class="text-blue-500!">
                        PDF
                    </flux:button>
                @endunless
            </div>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">{{ $meet->name }}</p>
        </div>

        @if(! $statement)
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-8 text-center text-sm text-zinc-500 dark:text-zinc-400">
                Für diesen Verein gibt es bei dieser Veranstaltung keine berechneten Meldungen.
            </div>
        @else
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-6">

                {{-- Einzelstarts je Athlet --}}
                @if($statement->athletes->isNotEmpty())
                <section aria-labelledby="fee-starts">
                    <h2 id="fee-starts" class="font-semibold text-zinc-900 dark:text-zinc-100 mb-2">Einzelstarts</h2>
                    <table class="w-full text-sm">
                        <thead class="sr-only">
                            <tr><th scope="col">Athlet / Bewerb</th><th scope="col">Betrag</th></tr>
                        </thead>
                        <tbody>
                            @foreach($statement->athletes as $athleteFees)
                                @php /** @var AthleteFees $athleteFees */ @endphp
                                <tr class="border-t border-zinc-100 dark:border-zinc-700">
                                    <th scope="row" class="pt-2 text-start font-medium text-zinc-900 dark:text-zinc-100">
                                        {{ $athleteFees->athlete->display_name }}
                                        @if($athleteFees->starts->isEmpty())
                                            <span class="font-normal text-zinc-500 dark:text-zinc-400">(nur Staffel)</span>
                                        @endif
                                    </th>
                                    <td class="pt-2 text-end tabular-nums text-zinc-900 dark:text-zinc-100">
                                        {{ Money::format($athleteFees->totalCents) }}
                                    </td>
                                </tr>
                                @foreach($athleteFees->starts as $start)
                                    @php /** @var FeeLine $start */ @endphp
                                    <tr>
                                        <td class="ps-4 text-zinc-600 dark:text-zinc-300">{{ $start->label }}</td>
                                        <td class="text-end tabular-nums text-zinc-600 dark:text-zinc-300">{{ Money::format($start->totalCents) }}</td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </section>
                @endif

                @if($statement->relays->isNotEmpty())
                    <section aria-labelledby="fee-relays">
                        <h2 id="fee-relays" class="font-semibold text-zinc-900 dark:text-zinc-100 mb-2">Staffeln</h2>
                        <table class="w-full text-sm">
                            <thead class="sr-only">
                                <tr><th scope="col">Staffel / Bewerb</th><th scope="col">Betrag</th></tr>
                            </thead>
                            <tbody>
                                @foreach($statement->relays as $relay)
                                    @php /** @var FeeLine $relay */ @endphp
                                    <tr class="border-t border-zinc-100 dark:border-zinc-700">
                                        <td class="py-1 text-zinc-900 dark:text-zinc-100">{{ $relay->label }}</td>
                                        <td class="py-1 text-end tabular-nums text-zinc-900 dark:text-zinc-100">{{ Money::format($relay->totalCents) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </section>
                @endif

                @if($statement->flatFees->isNotEmpty())
                    <section aria-labelledby="fee-flat">
                        <h2 id="fee-flat" class="font-semibold text-zinc-900 dark:text-zinc-100 mb-2">Pauschalen</h2>
                        <table class="w-full text-sm">
                            <thead class="sr-only">
                                <tr><th scope="col">Pauschale</th><th scope="col">Berechnung</th><th scope="col">Betrag</th></tr>
                            </thead>
                            <tbody>
                                @foreach($statement->flatFees as $line)
                                    @php /** @var FeeLine $line */ @endphp
                                    <tr class="border-t border-zinc-100 dark:border-zinc-700">
                                        <td class="py-1 text-zinc-900 dark:text-zinc-100">{{ $line->label }}</td>
                                        <td class="py-1 text-end tabular-nums text-zinc-500 dark:text-zinc-400">
                                            {{ $line->quantity }} × {{ Money::format($line->unitCents) }}
                                        </td>
                                        <td class="py-1 text-end tabular-nums text-zinc-900 dark:text-zinc-100">{{ Money::format($line->totalCents) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </section>
                @endif

                <div class="flex justify-between border-t-2 border-zinc-300 dark:border-zinc-600 pt-3 text-base font-semibold text-zinc-900 dark:text-white">
                    <span>Summe</span>
                    <span class="tabular-nums">{{ Money::format($statement->totalCents) }}</span>
                </div>
            </div>
        @endif
    </div>
@endsection
