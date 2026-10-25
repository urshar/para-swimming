@php use App\Models\Club; @endphp

@extends('layouts.app')

@section('title', 'Vereine korrigieren – ' . $meet->name)

@section('content')
    @php
        $formConfig = [
            'athleteId' => (string) old('athlete_id', ''),
            'clubId' => (string) old('club_id', ''),
        ];
    @endphp
    <div class="max-w-5xl" x-data='resultClubsForm(@json($formConfig))'>

        {{-- ── Kopf ──────────────────────────────────────────────────────────── --}}
        <div class="mb-6">
            <div class="flex items-center gap-2">
                <flux:button href="{{ $backUrl }}" variant="primary" icon="arrow-left" size="sm"
                             title="Zurück" aria-label="Zurück"/>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Vereine korrigieren</h1>
            </div>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">{{ $meet->name }}</p>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-2">
                Setzt den Verein aller Einzelergebnisse eines Athleten in diesem Wettkampf. Die Rekorde aus den Ergebnissen
                bekommen den neuen Verein, und die Landesrekorde werden neu geprüft, wenn der Wettkampf schon rekordgeprüft
                ist. Staffelergebnisse bleiben unverändert.
            </p>
        </div>

        {{-- ── Verband → Heimverein ──────────────────────────────────────────── --}}
        @if($federationCount > 0)
            <div class="mb-6 p-4 rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/20
                        flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-amber-800 dark:text-amber-300">
                    {{ $federationCount }} {{ $federationCount === 1 ? 'Ergebnis steht' : 'Ergebnisse stehen' }} unter einem
                    Verband (Nationalteam oder Landesauswahl), der Athlet hat aber einen eigenen Heimverein.
                </p>
                <form method="POST" action="{{ route('meets.result-clubs.home-clubs', $meet) }}"
                      x-data="{ submit() { if (confirm('Diese Ergebnisse auf den Heimverein des Athleten setzen?')) this.$el.submit() } }"
                      @submit.prevent="submit()">
                    @csrf
                    <flux:button type="submit" variant="primary" size="sm" icon="arrow-path">
                        Auf Heimverein setzen
                    </flux:button>
                </form>
            </div>
        @endif

        {{-- ── Verein für einen Athleten setzen ──────────────────────────────── --}}
        <div class="mb-6 bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 p-4" x-ref="form">
            <form method="POST" action="{{ route('meets.result-clubs.update', $meet) }}"
                  class="grid grid-cols-1 sm:grid-cols-[1fr_1fr_auto] gap-3 items-end">
                @csrf
                @method('PUT')
                <flux:field>
                    <flux:label>Athlet</flux:label>
                    <flux:select variant="listbox" searchable name="athlete_id" x-model="athleteId" required>
                        @foreach($rows as $row)
                            <flux:select.option value="{{ $row['athlete']->id }}"
                                                :selected="old('athlete_id') == $row['athlete']->id">
                                {{ $row['athlete']->display_name }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="athlete_id"/>
                </flux:field>
                <flux:field>
                    <flux:label>Neuer Verein</flux:label>
                    <flux:select variant="listbox" searchable name="club_id" x-model="clubId" required>
                        @foreach($clubs as $club)
                            <flux:select.option value="{{ $club->id }}" :selected="old('club_id') == $club->id">
                                {{ $club->name }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="club_id"/>
                </flux:field>
                <flux:button type="submit" variant="primary">Übernehmen</flux:button>
            </form>
        </div>

        {{-- ── Athleten des Wettkampfs ───────────────────────────────────────── --}}
        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 p-4 [--flux-bleed:1rem]">
            @if($rows->isEmpty())
                <p class="text-sm text-zinc-500 dark:text-zinc-400">Zu diesem Wettkampf gibt es keine Einzelergebnisse.</p>
            @else
                <flux:table bleed class="[&_td:first-child]:ps-4">
                    <flux:table.columns>
                        <flux:table.column>Athlet</flux:table.column>
                        <flux:table.column>Heimverein</flux:table.column>
                        <flux:table.column>Verein in den Ergebnissen</flux:table.column>
                        <flux:table.column class="w-20">Ergebnisse</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach($rows as $row)
                            <flux:table.row>
                                <flux:table.cell class="font-medium text-zinc-900 dark:text-white">
                                    <a href="{{ route('athletes.show', $row['athlete']) }}"
                                       class="hover:text-blue-600 dark:hover:text-blue-400">
                                        {{ $row['athlete']->display_name }}
                                    </a>
                                </flux:table.cell>
                                <flux:table.cell class="text-sm text-zinc-500 dark:text-zinc-400">
                                    {{ $row['home']?->name ?? '–' }}
                                </flux:table.cell>
                                <flux:table.cell class="text-sm">
                                    @foreach($row['clubs'] as $entry)
                                        <div class="{{ $entry['club']?->type === Club::TYPE_VERBAND && $row['underFederation'] ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-500 dark:text-zinc-400' }}">
                                            {{ $entry['club']?->name ?? '–' }}
                                            @if($row['clubs']->count() > 1)
                                                <span class="text-xs">({{ $entry['count'] }})</span>
                                            @endif
                                        </div>
                                    @endforeach
                                </flux:table.cell>
                                <flux:table.cell class="text-sm text-zinc-500 dark:text-zinc-400 tabular-nums">
                                    {{ $row['count'] }}
                                </flux:table.cell>
                                <flux:table.cell class="text-end whitespace-nowrap">
                                    @if($row['underFederation'])
                                        <form method="POST" action="{{ route('meets.result-clubs.update', $meet) }}" class="inline">
                                            @csrf
                                            @method('PUT')
                                            <input type="hidden" name="athlete_id" value="{{ $row['athlete']->id }}">
                                            <input type="hidden" name="club_id" value="{{ $row['home']->id }}">
                                            <flux:button type="submit" size="xs" variant="filled">Heimverein übernehmen</flux:button>
                                        </form>
                                    @endif
                                    <flux:button type="button" size="xs" variant="ghost"
                                                 x-on:click="pick({{ $row['athlete']->id }}, {{ $row['home']?->id ?? 'null' }})">
                                        Anderer Verein
                                    </flux:button>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </div>
    </div>
@endsection
