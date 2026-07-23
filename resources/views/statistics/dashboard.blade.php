@php
    /** @var array<string, mixed> $stats */
    $stats = $this->statistics;
    $overview = $stats['overview'];
    $records = $stats['records'];
@endphp

<div>
    {{-- ── Filter ────────────────────────────────────────────────────────── --}}
    <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 mb-6">
        <div class="flex flex-wrap items-end gap-4">
            <div class="w-40">
                <flux:select wire:model.live="year" label="Jahr">
                    @foreach($this->availableYears as $availableYear)
                        <flux:select.option value="{{ $availableYear }}">{{ $availableYear }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="flex-1 min-w-64">
                <flux:label>Veranstaltungen</flux:label>
                @if($this->availableMeets->isEmpty())
                    <p class="text-sm text-zinc-400 mt-2">Für {{ $year }} sind keine Veranstaltungen erfasst.</p>
                @else
                    <div class="flex flex-wrap gap-x-6 gap-y-2 mt-2">
                        @foreach($this->availableMeets as $meet)
                            <flux:checkbox
                                wire:model.live="meetIds"
                                value="{{ $meet->id }}"
                                label="{{ $meet->name }} ({{ $meet->start_date?->format('d.m.Y') }})"
                            />
                        @endforeach
                    </div>
                    <p class="text-xs text-zinc-400 mt-2">
                        @if(count($meetIds) > 0)
                            {{ count($meetIds) }} von {{ $this->availableMeets->count() }} ausgewählt.
                            <button type="button" wire:click="resetMeetSelection()" class="underline hover:no-underline">
                                Auswahl aufheben
                            </button>
                        @else
                            Ohne Auswahl werden alle Veranstaltungen des Jahres ausgewertet.
                        @endif
                    </p>
                @endif
            </div>
        </div>
    </div>

    {{-- ── Kennzahlen ────────────────────────────────────────────────────── --}}
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
        @foreach([
            ['Teilnehmer', $overview['participants']],
            ['Vereine', $overview['clubs']],
            ['Veranstaltungen', $overview['meets']],
            ['Starts', $overview['starts']],
            ['Rekorde', $records['overview']['total']],
        ] as [$label, $value])
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $label }}</div>
                <div class="text-2xl font-bold text-zinc-900 dark:text-zinc-100 mt-1">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- ── Teilnehmer und Starts pro Veranstaltung ────────────────────── --}}
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden lg:col-span-2">
            <div class="px-4 py-3 border-b border-zinc-100 dark:border-zinc-700">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">Teilnehmer und Starts pro Veranstaltung</h2>
            </div>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Veranstaltung</flux:table.column>
                    <flux:table.column>Datum</flux:table.column>
                    <flux:table.column>Teilnehmer</flux:table.column>
                    <flux:table.column>Starts</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse($stats['meets'] as $row)
                        <flux:table.row>
                            <flux:table.cell class="font-medium">{{ $row['meet'] }}</flux:table.cell>
                            <flux:table.cell class="text-sm text-zinc-500 dark:text-zinc-400">
                                {{ $this->formatDate($row['start_date']) }}
                            </flux:table.cell>
                            <flux:table.cell class="font-mono">{{ $row['participants'] }}</flux:table.cell>
                            <flux:table.cell class="font-mono">{{ $row['starts'] }}</flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="4" class="text-center text-sm text-zinc-400 py-6">
                                Keine Veranstaltungen mit Starts im gewählten Zeitraum.
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>

        {{-- ── Top-Vereine ────────────────────────────────────────────────── --}}
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
            <div class="px-4 py-3 border-b border-zinc-100 dark:border-zinc-700 flex items-center justify-between">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">Top-Vereine</h2>
                <span class="text-xs text-zinc-400">nach Starts</span>
            </div>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>#</flux:table.column>
                    <flux:table.column>Verein</flux:table.column>
                    <flux:table.column>Teilnehmer</flux:table.column>
                    <flux:table.column>Starts</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse($stats['clubs']->take($topRows) as $row)
                        <flux:table.row>
                            <flux:table.cell class="font-mono text-zinc-400">{{ $row['rank'] }}</flux:table.cell>
                            <flux:table.cell class="font-medium">
                                {{ $row['club'] }}
                                @if($row['nation'] && $row['nation'] !== 'AUT')
                                    <flux:badge size="sm" color="zinc">{{ $row['nation'] }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="font-mono">{{ $row['participants'] }}</flux:table.cell>
                            <flux:table.cell class="font-mono">{{ $row['starts'] }}</flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="4" class="text-center text-sm text-zinc-400 py-6">
                                Keine Daten im gewählten Zeitraum.
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>

        {{-- ── Top-Sportler ───────────────────────────────────────────────── --}}
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
            <div class="px-4 py-3 border-b border-zinc-100 dark:border-zinc-700 flex items-center justify-between">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">Top-Sportler</h2>
                <span class="text-xs text-zinc-400">nach Teilnahmen</span>
            </div>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>#</flux:table.column>
                    <flux:table.column>Sportler</flux:table.column>
                    <flux:table.column>Teilnahmen</flux:table.column>
                    <flux:table.column>Starts</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse($stats['athletes']->take($topRows) as $row)
                        <flux:table.row>
                            <flux:table.cell class="font-mono text-zinc-400">{{ $row['rank'] }}</flux:table.cell>
                            <flux:table.cell class="font-medium">
                                {{ $row['athlete'] }}
                                @if($row['nation'] && $row['nation'] !== 'AUT')
                                    <flux:badge size="sm" color="zinc">{{ $row['nation'] }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="font-mono">{{ $row['participations'] }}</flux:table.cell>
                            <flux:table.cell class="font-mono">{{ $row['starts'] }}</flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="4" class="text-center text-sm text-zinc-400 py-6">
                                Keine Daten im gewählten Zeitraum.
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>

        {{-- ── Nationen ───────────────────────────────────────────────────── --}}
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
            <div class="px-4 py-3 border-b border-zinc-100 dark:border-zinc-700">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">Nationen</h2>
            </div>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Nation</flux:table.column>
                    <flux:table.column>Teilnehmer</flux:table.column>
                    <flux:table.column>Starts</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse($stats['nations']->take($topRows) as $row)
                        <flux:table.row>
                            <flux:table.cell class="font-medium">
                                {{ $row['nation'] }}
                                <span class="text-xs text-zinc-400">{{ $row['nation_name'] }}</span>
                            </flux:table.cell>
                            <flux:table.cell class="font-mono">{{ $row['participants'] }}</flux:table.cell>
                            <flux:table.cell class="font-mono">{{ $row['starts'] }}</flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="3" class="text-center text-sm text-zinc-400 py-6">
                                Keine Daten im gewählten Zeitraum.
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>

        {{-- ── Rekorde ────────────────────────────────────────────────────── --}}
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
            <div class="px-4 py-3 border-b border-zinc-100 dark:border-zinc-700 flex items-center justify-between">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">Rekorde</h2>
                <span class="text-xs text-zinc-400">im Zeitraum aufgestellt</span>
            </div>

            <div class="px-4 py-3 grid grid-cols-2 sm:grid-cols-4 gap-3 border-b border-zinc-100 dark:border-zinc-700">
                @foreach([
                    ['Gesamt', $records['overview']['total']],
                    ['Österreich', $records['overview']['austrian']],
                    ['Jugend', $records['overview']['austrian_junior']],
                    ['Staffel', $records['overview']['relay']],
                ] as [$label, $value])
                    <div>
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $label }}</div>
                        <div class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">{{ $value }}</div>
                    </div>
                @endforeach
            </div>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>#</flux:table.column>
                    <flux:table.column>Sportler</flux:table.column>
                    <flux:table.column>Rekorde</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse($records['by_athlete']->take($topRows) as $row)
                        <flux:table.row>
                            <flux:table.cell class="font-mono text-zinc-400">{{ $row['rank'] }}</flux:table.cell>
                            <flux:table.cell class="font-medium">{{ $row['athlete'] }}</flux:table.cell>
                            <flux:table.cell class="font-mono">{{ $row['records'] }}</flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="3" class="text-center text-sm text-zinc-400 py-6">
                                Keine Rekorde im gewählten Zeitraum.
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>

            @if($records['overview']['without_athlete'] > 0)
                <div class="px-4 py-2 text-xs text-zinc-400 border-t border-zinc-100 dark:border-zinc-700">
                    {{ $records['overview']['without_athlete'] }} Rekord(e) ohne zugeordneten Sportler
                    (bei Staffeln normal, sonst zu prüfen).
                </div>
            @endif
        </div>
    </div>
</div>
