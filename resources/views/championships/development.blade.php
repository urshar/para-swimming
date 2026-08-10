@extends('layouts.app')

@section('title', 'Förderansicht')

@section('content')
    @php
        use App\Support\QualificationAthleteSummary;
        use App\Support\TimeParser;
    @endphp

    <div class="max-w-5xl">
        <div class="flex items-start justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Förderansicht</h1>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    {{ $championship->display_name }} ·
                    Qualifikationszeitraum {{ $championship->qualification_start->format('d.m.Y') }}
                    bis {{ $championship->qualification_end->format('d.m.Y') }}
                </p>
            </div>
            <div class="flex gap-2">
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
            Planungswerkzeug, kein Nachweis. Umgerechnete Kurzbahnzeiten sind als „rechnerisch
            erreicht" gekennzeichnet und qualifizieren niemanden. Der Abstand zur Norm wird auch bei
            Nichterfüllung ausgewiesen — er ist die eigentliche Information für die
            Förderentscheidung.
        </div>

        <form method="GET" action="{{ route('championships.development', $championship) }}"
              class="mb-6 flex flex-wrap items-end gap-3">
            <flux:field class="w-72">
                <flux:label>Athlet suchen</flux:label>
                <flux:input name="q" value="{{ $search }}" placeholder="Name"/>
            </flux:field>
            <flux:button type="submit" variant="filled" size="sm">Suchen</flux:button>
            @if($search !== '')
                <flux:button href="{{ route('championships.development', $championship) }}"
                             variant="ghost" size="sm">Zurücksetzen
                </flux:button>
            @endif
        </form>

        @if($entries->total() > 0)
            <p class="mb-4 text-xs text-zinc-500 dark:text-zinc-400">
                {{ $entries->firstItem() }}–{{ $entries->lastItem() }} von
                {{ $entries->total() }} Athleten
            </p>
        @endif

        @forelse($entries as $eintrag)
            <div
                class="mb-6 bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                <div class="px-4 py-3 border-b border-zinc-200 dark:border-zinc-700">
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                        {{ $eintrag['athlete']->full_name }}
                    </h2>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                        {{ $eintrag['athlete']->gender === 'M' ? 'männlich' : 'weiblich' }}
                        · {{ QualificationAthleteSummary::primarySportClass($eintrag['rows']) ?? '–' }}
                        · {{ $eintrag['athlete']->club?->name }}
                    </p>
                </div>

                <flux:table class="[&_td:first-child]:ps-4 [&_th:first-child]:ps-4">
                    <flux:table.columns>
                        <flux:table.column>Bewerb</flux:table.column>
                        <flux:table.column>Leistung</flux:table.column>
                        <flux:table.column>MQS</flux:table.column>
                        <flux:table.column>ÖBSV-Norm</flux:table.column>
                        <flux:table.column>Ziel {{ $championship->course === 'LCM' ? 'SCM' : 'LCM' }}</flux:table.column>
                        <flux:table.column>Status</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach($eintrag['rows'] as $zeile)
                            @php($status = $zeile->status)
                            <flux:table.row>
                                <flux:table.cell class="whitespace-nowrap">
                                    {{ $zeile->eventLabel }}
                                    <span class="font-mono text-xs text-zinc-500">{{ $zeile->sportClass }}</span>
                                </flux:table.cell>

                                <flux:table.cell class="font-mono text-xs">
                                    @if($status->swimTime !== null)
                                        {{ TimeParser::display($status->swimTime) }} {{ $status->course }}
                                        @if($status->estimatedLcmTime !== null)
                                            <span class="block text-amber-600 dark:text-amber-400">
                                                → {{ TimeParser::display($status->estimatedLcmTime) }}
                                                {{ $championship->course }} geschätzt
                                                (Faktor {{ number_format($status->conversionFactor, 4, ',', '.') }})
                                            </span>
                                        @endif
                                    @else
                                        <span class="text-zinc-400">–</span>
                                    @endif
                                </flux:table.cell>

                                <flux:table.cell class="font-mono text-xs">
                                    {{ $zeile->standard?->formatted_mqs ?? '–' }}
                                </flux:table.cell>
                                <flux:table.cell class="font-mono text-xs">
                                    {{ $zeile->standard?->formatted_obsv ?? '–' }}
                                </flux:table.cell>
                                <flux:table.cell class="font-mono text-xs">
                                    {{ $zeile->targetTimeOtherCourse === null ? '–' : TimeParser::display($zeile->targetTimeOtherCourse) }}
                                </flux:table.cell>

                                <flux:table.cell>
                                    <flux:badge color="{{ $status->colour() }}" size="sm">
                                        {{ $status->label() }}
                                    </flux:badge>

                                    @if($status->formattedGap())
                                        <span class="block mt-0.5 font-mono text-xs text-zinc-500">
                                            Abstand: {{ $status->formattedGap() }}
                                        </span>
                                    @endif

                                    @if($zeile->metUsable === true)
                                        <span class="block mt-0.5 text-xs text-zinc-500">MET verwertbar</span>
                                    @elseif($zeile->metUsable === false)
                                        <span class="block mt-0.5 text-xs text-zinc-500">
                                            MET ohne MQS — wirkungslos
                                        </span>
                                    @endif

                                    @if($status->swimTime !== null && ! $status->meetApproved)
                                        <span class="block mt-0.5 text-xs text-amber-600 dark:text-amber-400">
                                            Wettkampf nicht WPS-anerkannt
                                        </span>
                                    @endif

                                    @if($status->exhibition)
                                        <span class="block mt-0.5 text-xs text-zinc-500">außer Konkurrenz (EXH)</span>
                                    @endif

                                    @if($status->note)
                                        <span class="block mt-0.5 text-xs text-zinc-500">{{ $status->note }}</span>
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>

                @if($eintrag['events_without_standard']->isNotEmpty())
                    <p class="px-4 py-2 border-t border-zinc-200 dark:border-zinc-700 text-xs text-zinc-500 dark:text-zinc-400">
                        {{ $eintrag['events_without_standard']->count() }} weitere(r) Bewerb(e) ohne
                        ausgeschriebene Norm:
                        {{ $eintrag['events_without_standard']->join(', ') }}
                    </p>
                @endif
            </div>
        @empty
            <div
                class="p-6 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl text-sm text-zinc-500 dark:text-zinc-400">
                Keine Athleten mit Ergebnissen im Qualifikationszeitraum.
            </div>
        @endforelse

        @if($entries->hasPages())
            <div class="mt-6">
                {{ $entries->links() }}
            </div>
        @endif
    </div>
@endsection
