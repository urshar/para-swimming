@php
    use App\Models\SwimRecord;
@endphp

@extends('layouts.app')

@section('title', 'Rekorde – ' . $meet->name)

@section('content')
    {{-- ── Kopf ──────────────────────────────────────────────────────────────── --}}
    <div class="mb-6">
        <div class="flex items-center gap-2">
            <flux:button href="{{ route('meets.show', $meet) }}" variant="primary" icon="arrow-left"
                         size="sm" title="Zurück" aria-label="Zurück zur Veranstaltung"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Rekorde</h1>
        </div>
        <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">
            {{ $meet->name }}
            @if($meet->city)
                · {{ $meet->city }}
            @endif
            · {{ $meet->start_date?->format('d.m.Y') }}
        </p>
    </div>

    @if($groups->isEmpty())
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
            <p class="text-sm text-zinc-500 dark:text-zinc-400 py-6 text-center">
                Bei dieser Veranstaltung wurden keine Rekorde aufgestellt.
            </p>
        </div>
    @else
        <div class="space-y-6">
            @foreach($groups as $group)
                <section aria-labelledby="record-type-{{ $loop->index }}"
                         class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden p-4 [--flux-bleed:1rem]">
                    <h2 id="record-type-{{ $loop->index }}"
                        class="text-lg font-semibold text-zinc-900 dark:text-zinc-100 mb-2">
                        {{ $group['label'] }}
                        <span class="text-sm font-normal text-zinc-500 dark:text-zinc-400">({{ $group['records']->count() }})</span>
                    </h2>

                    <flux:table bleed class="[&_td:first-child]:ps-4">
                        <flux:table.columns>
                            <flux:table.column>Disziplin</flux:table.column>
                            <flux:table.column>Sportklasse</flux:table.column>
                            <flux:table.column>Wertung</flux:table.column>
                            <flux:table.column>Athlet / Staffel</flux:table.column>
                            <flux:table.column>Verein</flux:table.column>
                            <flux:table.column align="end">Zeit</flux:table.column>
                            <flux:table.column align="end">Bisher</flux:table.column>
                            <flux:table.column>Status</flux:table.column>
                            {{-- "relative", damit der absolut positionierte sr-only-Text im Scrollbereich der Tabelle
                                 bleibt und die Seite nicht horizontal verbreitert. --}}
                            <flux:table.column class="relative"><span class="sr-only">Aktionen</span></flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach($group['records'] as $record)
                                <flux:table.row>
                                    <flux:table.cell class="font-medium">
                                        {{ $record->discipline_label }}
                                        <span class="text-zinc-400">{{ $record->course }}</span>
                                        @if($record->from_split)
                                            <flux:badge size="sm" color="violet" class="ml-1">Zwischenzeit</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <flux:badge size="sm" color="blue" class="font-mono">{{ $record->sport_class }}</flux:badge>
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <flux:badge size="sm" color="{{ SwimRecord::genderColor($record->gender) }}">
                                            {{ SwimRecord::genderLabel($record->gender) }}
                                        </flux:badge>
                                    </flux:table.cell>
                                    <flux:table.cell class="text-sm whitespace-normal">
                                        @if($record->relay_count > 1)
                                            @forelse($record->relayTeam as $member)
                                                <div class="text-xs">
                                                    <span class="text-zinc-400 font-mono w-4 inline-block">{{ $member->position }}.</span>
                                                    @if($member->athlete_id)
                                                        <x-athlete-link :athlete="$member->athlete"
                                                                        class="hover:text-blue-600 transition-colors">{{ $member->display_name }}</x-athlete-link>
                                                    @else
                                                        {{ $member->display_name }}
                                                    @endif
                                                </div>
                                            @empty
                                                <span class="text-zinc-400 italic text-xs">Staffel</span>
                                            @endforelse
                                        @elseif($record->athlete)
                                            <x-athlete-link :athlete="$record->athlete"
                                                            class="hover:text-blue-600 transition-colors">{{ $record->athlete->display_name }}</x-athlete-link>
                                            <span class="text-zinc-400">({{ $record->athlete->birth_date?->format('Y') ?? '–' }})</span>
                                        @else
                                            <span class="text-zinc-400">–</span>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell class="text-sm text-zinc-500 whitespace-normal"
                                                     :title="$record->recordClub()?->full_name_hint">
                                        {{ $record->record_club_name ?? '–' }}
                                    </flux:table.cell>
                                    <flux:table.cell align="end"
                                                     class="font-mono font-bold tabular-nums text-zinc-900 dark:text-zinc-100">
                                        {{ $record->formatted_swim_time }}
                                    </flux:table.cell>
                                    <flux:table.cell align="end" class="font-mono tabular-nums text-sm text-zinc-500">
                                        {{ $record->supersedes?->formatted_swim_time ?? '–' }}
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <flux:badge size="sm" color="{{ $statusColors[$record->record_status] ?? 'zinc' }}">
                                            {{ $statusLabels[$record->record_status] ?? $record->record_status }}
                                        </flux:badge>
                                    </flux:table.cell>
                                    <flux:table.cell align="end">
                                        <flux:button href="{{ route('records.show', $record) }}" variant="ghost" size="sm"
                                                     icon="eye" title="Rekord anzeigen"
                                                     aria-label="Rekord {{ $record->discipline_label }} {{ $record->sport_class }} anzeigen"/>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </section>
            @endforeach
        </div>
    @endif
@endsection
