@php
    use App\Support\TimeParser;
@endphp

@extends('layouts.app')

@section('title', $result->athlete?->display_name . ' – ' . $result->swimEvent?->display_name)

@section('content')
    <div class="mb-6">
        <div class="flex items-center gap-2">
            <flux:button href="{{ url()->previous() }}" variant="primary" icon="arrow-left" size="sm"
                         title="Zurück" aria-label="Zurück"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                {{ $result->athlete?->display_name }} – {{ $result->swimEvent?->display_name }}
            </h1>
        </div>
        <div class="flex items-center flex-wrap justify-end gap-2 mt-4">
            <flux:button href="{{ route('results.edit', $result) }}" variant="filled" icon="pencil" size="sm"
                         class="text-amber-500!">
                Bearbeiten
            </flux:button>
            <form method="POST" action="{{ route('results.destroy', $result) }}"
                  x-data="{ submit() { if (confirm('Ergebnis löschen?')) this.$el.submit() } }"
                  @submit.prevent="submit()">
                @csrf @method('DELETE')
                <flux:button type="submit" variant="filled" icon="trash" size="sm" class="text-red-500!">
                    Löschen
                </flux:button>
            </form>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- Main Info --}}
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 p-6">
                <div class="grid grid-cols-2 gap-6">
                    <div>
                        <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide mb-1">
                            Athlet
                        </div>
                        <a href="{{ route('athletes.show', $result->athlete) }}"
                           class="text-lg font-semibold text-zinc-900 dark:text-white hover:text-blue-600">
                            {{ $result->athlete?->full_name }}
                        </a>
                        <div class="text-sm text-zinc-500 mt-0.5">{{ $result->athlete?->nation?->code }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide mb-1">
                            Wettkampf
                        </div>
                        <a href="{{ route('meets.show', $result->meet) }}"
                           class="font-medium text-zinc-900 dark:text-white hover:text-blue-600">
                            {{ $result->meet?->name }}
                        </a>
                        <div
                            class="text-sm text-zinc-500 mt-0.5">{{ $result->meet?->start_date?->format('d.m.Y') }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide mb-1">
                            Disziplin
                        </div>
                        <div
                            class="font-medium text-zinc-900 dark:text-white">{{ $result->swimEvent?->display_name }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide mb-1">
                            Sport-Klasse
                        </div>
                        <flux:badge>{{ $result->sport_class ?? '–' }}</flux:badge>
                    </div>
                </div>

                {{-- Zeit --}}
                <div class="mt-6 pt-6 border-t border-zinc-200 dark:border-zinc-800 flex items-center gap-6">
                    <div>
                        <div class="text-xs font-medium text-zinc-500 uppercase tracking-wide mb-1">Schwimmzeit</div>
                        <div class="text-3xl font-bold font-mono text-zinc-900 dark:text-white">
                            {{ $result->formatted_swim_time }}
                        </div>
                    </div>
                    @if($result->place)
                        <div>
                            <div class="text-xs font-medium text-zinc-500 uppercase tracking-wide mb-1">Platz</div>
                            <div class="text-3xl font-bold text-zinc-900 dark:text-white">#{{ $result->place }}</div>
                        </div>
                    @endif
                    @if($result->status)
                        <flux:badge color="red" class="self-end mb-1">{{ $result->status }}</flux:badge>
                    @endif
                    <div class="flex gap-2 self-end mb-1">
                        @if($result->is_world_record)
                            <flux:badge color="yellow">WR</flux:badge>
                        @endif
                        @if($result->is_european_record)
                            <flux:badge color="blue">ER</flux:badge>
                        @endif
                        @if($result->is_national_record)
                            <flux:badge color="green">NR</flux:badge>
                        @endif
                    </div>
                </div>

                @if($result->comment)
                    <div class="mt-4 text-sm text-zinc-500 dark:text-zinc-400 italic">{{ $result->comment }}</div>
                @endif
            </div>

            {{-- Splits --}}
            @if($result->splits->isNotEmpty())
                <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 p-6">
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-white mb-4">Splitzeiten</h3>
                    <div class="space-y-2">
                        @foreach($result->splits as $i => $split)
                            <div class="flex items-center gap-4">
                                <div class="text-sm text-zinc-500 w-16">{{ $split->distance }}m</div>
                                <div class="font-mono text-sm font-medium text-zinc-900 dark:text-white">
                                    {{ $split->formatted_split_time }}
                                </div>
                                @if($i > 0)
                                    @php $prev = $result->splits[$i - 1]; $lap = $split->split_time - $prev->split_time; @endphp
                                    <div class="text-xs text-zinc-400 font-mono">(+{{ TimeParser::display($lap) }})
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        {{-- Sidebar --}}
        <div class="space-y-4">
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 p-4">
                <h3 class="text-xs font-semibold text-zinc-500 uppercase tracking-wide mb-3">Details</h3>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-zinc-500">Lauf / Bahn</dt>
                        <dd class="text-zinc-900 dark:text-white">{{ $result->heat ?? '–' }}
                            / {{ $result->lane ?? '–' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-zinc-500">Reaktionszeit</dt>
                        <dd class="font-mono text-zinc-900 dark:text-white">
                            {{ $result->reaction_time !== null ? ($result->reaction_time >= 0 ? '+' : '') . number_format($result->reaction_time / 100, 2) . 's' : '–' }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-zinc-500">Punkte (World Aquatics)</dt>
                        <dd class="text-zinc-900 dark:text-white">{{ $result->points ?? '–' }}</dd>
                    </div>
                    {{-- Der WPS-Block erscheint nur, wenn Punkte berechnet wurden. Ohne
                         aktiviertes Punktesystem oder ohne passenden Parametersatz bleibt er
                         ausgeblendet, statt einen Platzhalter anzuzeigen. --}}
                    @if($result->hasWpsPoints())
                        <div class="flex justify-between">
                            <dt class="text-zinc-500">WPS-Punkte</dt>
                            <dd class="text-zinc-900 dark:text-white">
                                {{ $result->wps_points }}
                                @if($result->hasEstimatedWpsPoints())
                                    <flux:badge color="amber" size="sm" class="ms-1">geschätzt</flux:badge>
                                @else
                                    <flux:badge color="green" size="sm" class="ms-1">offiziell</flux:badge>
                                @endif
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-zinc-500">WPS-Version</dt>
                            <dd class="text-zinc-900 dark:text-white">
                                {{ $result->wpsPointVersion?->label ?? '–' }}
                            </dd>
                        </div>

                        {{-- Zeit und Hinweis stehen bewusst in EINER Bedingung: zwei getrennte
                             Prüfungen auf dieselbe Methode wären redundant, da sich dazwischen
                             nichts ändern kann.

                             Die geschätzte Langbahnzeit ist fachlich oft wichtiger als die
                             Punktzahl selbst — sie lässt sich unmittelbar gegen internationale
                             Melde- und Finalzeiten halten. --}}
                        @if($result->hasConvertedWpsTime())
                            <div class="flex justify-between">
                                <dt class="text-zinc-500">Geschätzt LCM</dt>
                                <dd class="text-zinc-900 dark:text-white font-mono">
                                    {{ $result->formatted_estimated_lcm_time }}
                                    <span class="text-xs text-zinc-500 font-sans ms-1">
                                        (Faktor {{ $result->wpsConversionFactor?->description }})
                                    </span>
                                </dd>
                            </div>

                            <div class="mt-3 p-3 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800 rounded-lg text-xs text-amber-700 dark:text-amber-400">
                                Kurzbahnzeit auf Langbahn umgerechnet. Nicht offiziell von World Para
                                Swimming anerkannt. Bei Nachwuchsathleten tendenziell zu optimistisch,
                                da der Faktor überwiegend auf international startenden Athletinnen und
                                Athleten beruht.
                            </div>
                        @endif
                    @endif
                    <div class="flex justify-between">
                        <dt class="text-zinc-500">Club</dt>
                        <dd class="text-zinc-900 dark:text-white">{{ $result->club?->display_name }}</dd>
                    </div>
                </dl>
            </div>
        </div>

    </div>
@endsection
