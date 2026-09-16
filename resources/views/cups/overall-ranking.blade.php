@extends('layouts.app')

@section('title', 'Gesamtwertung — '.$cup->name)

@section('content')
    <div class="max-w-6xl">
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Gesamtwertung</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                {{ $cup->name }} · beste {{ $cup->best_of_count }} Tageswertungen
                @if($calculatedAt)
                    · berechnet am {{ $calculatedAt->format('d.m.Y H:i') }} Uhr
                @endif
            </p>

            <div class="flex items-center flex-wrap gap-2 mt-4">
                {{-- Zurück zur Gesamtwertungs-Übersicht (der öffentliche Einstieg für alle Nutzer),
                     nicht zur Cup-Konfiguration: die ist admin-only - ein Nichtadmin, der über
                     "Gesamtwertung" hierher kam, bekäme dort einen 403 statt zurück zu seiner
                     Ausgangsliste zu kommen (Erik, Design-Feedback 04.09.2026). --}}
                <flux:button href="{{ route('cups.overall-ranking.index') }}" variant="filled" icon="arrow-left"
                             size="sm">
                    Zurück
                </flux:button>

                <div class="ml-auto flex items-center flex-wrap gap-2">
                    <flux:button href="{{ route('cups.overall-ranking.pdf', $cup) }}" variant="filled"
                                 icon="printer" size="sm" class="text-purple-500!" target="_blank">
                        PDF / Drucken
                    </flux:button>
                    @if(auth()->user()?->is_admin)
                        <form method="POST" action="{{ route('cups.overall-ranking.calculate', $cup) }}"
                              x-data="{ submit() { if (confirm('Gesamtwertung neu berechnen? Der bisherige Snapshot wird ersetzt.')) this.$el.submit() } }"
                              @submit.prevent="submit()">
                            @csrf
                            <flux:button type="submit" variant="primary" icon="arrow-path" size="sm">
                                Neu berechnen
                            </flux:button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        @if($isStale)
            <div
                class="mb-4 p-4 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800 rounded-xl text-sm text-amber-700 dark:text-amber-400 flex items-start gap-2">
                <flux:icon name="exclamation-triangle" class="w-4 h-4 mt-0.5 shrink-0"/>
                <span>{{ $staleReason }} Bitte neu berechnen.</span>
            </div>
        @endif

        @if(session('success'))
            <div
                class="mb-4 p-4 bg-green-50 dark:bg-green-950/20 border border-green-200 dark:border-green-800 rounded-xl text-sm text-green-700 dark:text-green-400">
                {{ session('success') }}
            </div>
        @endif

        {{--
            Filter statt Tabs (Design-Feedback Erik, 15.09.2026: "auf Tabs aufteilen ... oder
            einen Filter ... was am besten geeignet ist"): Bei bis zu einigen Dutzend Kategorien
            (Geschlecht × Sportklassengruppe × Altersgruppe, siehe OverallRankingService::brackets())
            wären Tabs unhandlich (breite, evtl. umbrechende Tab-Leiste) und skalieren nicht mit der
            Anzahl der Sportklassengruppen/Altersgruppen. Ein Filter passt außerdem zum Rest der App
            (WPS-Ranglisten, Vereinswertung) statt eines hier sonst nirgends verwendeten Musters.
            Rein client-seitig mit Alpine, kein Livewire nötig: Alle Kategorien sind bereits
            serverseitig gerendert, der Filter blendet nur per x-show ein/aus - kein Reload, keine
            zusätzliche Server-Anfrage.
        --}}
        @php
            $genderOptions = $brackets->pluck('gender')->unique()->values();
            $groupOptions = $brackets->pluck('group')->unique('id')->sortBy('sort_order')->values();
            $ageGroupOptions = $brackets->pluck('ageGroup')->filter()->unique('id')->sortBy('sort_order')->values();
            $hasAgeGroupless = $brackets->contains(fn (array $b) => $b['ageGroup'] === null);
            $bracketMeta = $brackets->map(fn (array $b) => [
                'gender' => $b['gender'] ?? 'null',
                'group' => (string) $b['group']->id,
                'ageGroup' => $b['ageGroup']?->id !== null ? (string) $b['ageGroup']->id : 'null',
            ])->values();
        @endphp

        <div x-data="{
                genderFilter: 'ALL', groupFilter: 'ALL', ageGroupFilter: 'ALL',
                brackets: @js($bracketMeta),
                matches(b) {
                    return (this.genderFilter === 'ALL' || this.genderFilter === b.gender)
                        && (this.groupFilter === 'ALL' || this.groupFilter === b.group)
                        && (this.ageGroupFilter === 'ALL' || this.ageGroupFilter === b.ageGroup);
                },
                get visibleCount() { return this.brackets.filter(b => this.matches(b)).length; },
             }">
            @if($brackets->count() > 1)
                <div class="flex flex-wrap items-end gap-4 mb-4">
                    <flux:field class="w-48">
                        <flux:label>Geschlecht</flux:label>
                        <flux:select variant="listbox" x-model="genderFilter">
                            <flux:select.option value="ALL">Alle</flux:select.option>
                            @foreach($genderOptions as $gender)
                                <flux:select.option value="{{ $gender ?? 'null' }}">
                                    {{ $gender === null ? 'Damen & Herren' : ($gender === 'F' ? 'Damen' : 'Herren') }}
                                </flux:select.option>
                            @endforeach
                        </flux:select>
                    </flux:field>

                    <flux:field class="w-72">
                        <flux:label>Sportklassengruppe</flux:label>
                        <flux:select variant="listbox" x-model="groupFilter">
                            <flux:select.option value="ALL">Alle</flux:select.option>
                            @foreach($groupOptions as $group)
                                <flux:select.option value="{{ $group->id }}">{{ $group->name_de }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </flux:field>

                    @if($ageGroupOptions->isNotEmpty())
                        <flux:field class="w-48">
                            <flux:label>Altersgruppe</flux:label>
                            <flux:select variant="listbox" x-model="ageGroupFilter">
                                <flux:select.option value="ALL">Alle</flux:select.option>
                                @foreach($ageGroupOptions as $ageGroup)
                                    <flux:select.option
                                        value="{{ $ageGroup->id }}">{{ $ageGroup->name_de }}</flux:select.option>
                                @endforeach
                                @if($hasAgeGroupless)
                                    <flux:select.option value="null">ohne Altersgruppe</flux:select.option>
                                @endif
                            </flux:select>
                        </flux:field>
                    @endif
                </div>
            @endif

            @if($meets->isNotEmpty())
                <p class="text-xs text-zinc-400 mb-4">
                    <span class="text-emerald-700 dark:text-emerald-400 font-semibold">Grün/fett</span> = zählt zu den
                    besten {{ $cup->best_of_count }} Runden. Format je Runde: Punkte/Sportklasse.
                </p>
            @endif

            @forelse($brackets as $bracket)
                {{-- Filterwerte direkt als Objekt statt Index-Lookup in "brackets[N]": Ein Blade-Echo
                     mitten in einer JS-Array-Index-Klammer ("brackets[{{ $i }}]") lässt PhpStorms
                     JS-Parser über die eingebettete "{{"/"}}"-Klammerung stolpern ("Expression
                     expected"/"'with' statement", Zeile dieses <div>s). @php($x) + @js($x) vor dem
                     Tag vermeidet das UND umgeht CLAUDE.mds @json-Komma-Falle (siehe dort) — kein
                     Array-Literal mit eigenen Kommas direkt im @js()-Aufruf. --}}
                @php($bracketPayload = [
                    'gender' => $bracket['gender'] ?? 'null',
                    'group' => (string) $bracket['group']->id,
                    'ageGroup' => $bracket['ageGroup']?->id !== null ? (string) $bracket['ageGroup']->id : 'null',
                ])
                <div x-show="matches(@js($bracketPayload))"
                     class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden mb-4">
                    <div
                        class="px-4 py-3 border-b border-zinc-100 dark:border-zinc-700 flex items-center justify-between">
                        <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">
                            {{ $bracket['gender'] === null ? 'Damen & Herren' : ($bracket['gender'] === 'F' ? 'Damen' : 'Herren') }}
                            — {{ $bracket['group']->name_de }}
                            @if($bracket['ageGroup'])
                                — {{ $bracket['ageGroup']->name_de }}
                            @endif
                        </h2>
                        <span class="text-xs text-zinc-400">{{ $bracket['results']->count() }} Athlet(en)</span>
                    </div>

                    <div class="overflow-x-auto">
                        <flux:table
                            class="table-fixed w-full min-w-180 [&_td:first-child]:ps-4 [&_th:first-child]:ps-4 [&_td:last-child]:pe-4 [&_th:last-child]:pe-4">
                            <flux:table.columns>
                                <flux:table.column class="w-12">Rang</flux:table.column>
                                <flux:table.column class="w-56">Athlet</flux:table.column>
                                <flux:table.column class="w-48">Verein</flux:table.column>
                                @foreach($meets as $index => $meet)
                                    <flux:table.column class="w-20">
                                    <span title="{{ $meet->name }} ({{ $meet->start_date->format('d.m.Y') }})">
                                        R.{{ $index + 1 }}
                                    </span>
                                    </flux:table.column>
                                @endforeach
                                <flux:table.column class="w-28">Gesamtpunkte</flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @foreach($bracket['results'] as $row)
                                    <flux:table.row>
                                        <flux:table.cell class="font-medium">{{ $row->rank }}</flux:table.cell>
                                        <flux:table.cell>
                                            <a href="{{ route('athletes.show', $row->athlete) }}"
                                               class="hover:underline">
                                                {{ $row->athlete->last_name }}, {{ $row->athlete->first_name }}
                                            </a>
                                        </flux:table.cell>
                                        <flux:table.cell>{{ $row->club?->display_name }}</flux:table.cell>
                                        @foreach($row->rounds as $round)
                                            <flux:table.cell class="font-mono text-xs">
                                            <span @class([
                                                'text-emerald-700 dark:text-emerald-400 font-semibold' => $round['counted'],
                                                'text-zinc-400' => ! $round['counted'],
                                            ])>
                                                {{ $round['points'] ?? '—' }}{{ $round['sport_class'] ? '/'.$round['sport_class'] : '' }}
                                            </span>
                                            </flux:table.cell>
                                        @endforeach
                                        <flux:table.cell
                                            class="font-mono font-semibold">{{ $row->total_points }}</flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </div>
                </div>
            @empty
                <div
                    class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-8 text-center">
                    <p class="text-sm text-zinc-400">
                        Für diesen Cup wurde noch keine Gesamtwertung berechnet.
                    </p>
                </div>
            @endforelse

            @if($brackets->count() > 1)
                <div x-show="visibleCount === 0" x-cloak
                     class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-8 text-center">
                    <p class="text-sm text-zinc-400">Keine Kategorien für diese Auswahl.</p>
                </div>
            @endif
        </div>
    </div>
@endsection
