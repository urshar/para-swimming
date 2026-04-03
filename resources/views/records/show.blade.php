@extends('layouts.app')

@section('title', $record->record_type . ' – ' . $record->sport_class . ' ' . $record->distance . 'm')

@section('content')

    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <flux:button href="{{ route('records.index', ['type' => $record->record_type]) }}" variant="ghost"
                         icon="arrow-left" size="sm"/>
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                        {{ $record->record_type }} · {{ $record->sport_class }} · {{ $record->distance }}
                        m {{ $record->strokeType?->name_de }}
                    </h1>
                    <flux:badge color="{{ $record->gender === 'M' ? 'blue' : 'pink' }}">
                        {{ $record->gender === 'M' ? 'Herren' : 'Damen' }}
                    </flux:badge>
                    <flux:badge color="zinc">{{ $record->course }}</flux:badge>
                </div>
                <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                    Aktueller Rekord seit {{ $record->set_date?->format('d.m.Y') }}
                </p>
            </div>
        </div>
        <flux:button href="{{ route('records.edit', $record) }}" variant="ghost" icon="pencil" size="sm">
            Bearbeiten
        </flux:button>
        <form method="POST" action="{{ route('records.destroy', $record) }}"
              x-data
              @submit.prevent="
                  const msg = $el.dataset.hasPredecessor === '1'
                      ? 'Rekord löschen? Der Vorgänger-Rekord wird automatisch wiederhergestellt.'
                      : 'Rekord unwiderruflich löschen? Es gibt keinen Vorgänger-Rekord.';
                  if(confirm(msg)) $el.submit()
              "
              data-has-predecessor="{{ $record->supersedes_id ? '1' : '0' }}">
            @csrf @method('DELETE')
            <flux:button type="submit" variant="ghost" icon="trash" size="sm" class="text-red-500">
                Löschen
            </flux:button>
        </form>
    </div>

    <div class="grid grid-cols-3 gap-6 mb-6">

        {{-- Aktueller Rekord --}}
        <div class="col-span-2 bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
            <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-4">Aktueller Rekord</h2>

            <div class="text-4xl font-mono font-bold text-blue-600 dark:text-blue-400 mb-4">
                {{ $record->formatted_swim_time }}
            </div>

            <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Athlet</dt>
                    <dd class="font-medium mt-0.5">
                        @if($record->athlete)
                            <a href="{{ route('athletes.show', $record->athlete) }}"
                               class="hover:text-blue-600 transition-colors">
                                {{ $record->athlete->full_name }}
                            </a>
                            <span class="text-zinc-400 text-xs ml-1">({{ $record->athlete->nation?->code }})</span>
                        @else
                            <span class="text-zinc-400">–</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Verein</dt>
                    <dd class="font-medium mt-0.5">
                        {{ $record->athlete?->club?->short_name ?? $record->athlete?->club?->name ?? '–' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Datum</dt>
                    <dd class="font-medium mt-0.5">{{ $record->set_date?->format('d.m.Y') ?? '–' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Wettkampf</dt>
                    <dd class="font-medium mt-0.5">{{ $record->meet_name ?? '–' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Ort</dt>
                    <dd class="font-medium mt-0.5">{{ $record->meet_city ?? '–' }}</dd>
                </div>
                @if($record->comment)
                    <div class="col-span-2">
                        <dt class="text-zinc-500 dark:text-zinc-400">Anmerkung</dt>
                        <dd class="font-medium mt-0.5">{{ $record->comment }}</dd>
                    </div>
                @endif
            </dl>

            {{-- Splits --}}
            @if($record->splits->isNotEmpty())
                <div class="mt-5 pt-4 border-t border-zinc-100 dark:border-zinc-700">
                    <h3 class="text-sm font-semibold text-zinc-600 dark:text-zinc-400 mb-3">Splitzeiten</h3>
                    <div class="flex flex-wrap gap-3">
                        @foreach($record->splits as $split)
                            <div class="bg-zinc-50 dark:bg-zinc-700 rounded-lg px-3 py-2 text-center">
                                <div class="text-xs text-zinc-400 mb-0.5">{{ $split->distance }}m</div>
                                <div class="font-mono font-semibold text-sm text-zinc-900 dark:text-zinc-100">
                                    {{ $split->formatted_split_time }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        {{-- Status --}}
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-5">
            <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-4">Status</h2>
            <flux:badge color="{{ match($record->record_status) {
                'APPROVED' => 'emerald',
                'PENDING'  => 'amber',
                'INVALID'  => 'red',
                default    => 'zinc',
            } }}" class="mb-3">
                {{ $record->record_status }}
            </flux:badge>

            @if($record->result)
                <div class="mt-3 text-sm">
                    <div class="text-zinc-500 dark:text-zinc-400 mb-1">Ergebnis-Referenz</div>
                    <a href="{{ route('results.show', $record->result) }}" class="text-blue-600 hover:underline">
                        Zum Ergebnis →
                    </a>
                </div>
            @endif
        </div>
    </div>

    {{-- Historie --}}
    @if($history->count() > 1)
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100 mb-3">Rekord-Historie</h2>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>Zeit</flux:table.column>
                <flux:table.column>Athlet</flux:table.column>
                <flux:table.column>Wettkampf</flux:table.column>
                <flux:table.column>Datum</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach($history->sortByDesc('set_date') as $histRecord)
                    <flux:table.row class="{{ $histRecord->is_current ? 'bg-blue-50 dark:bg-blue-950/20' : '' }}">
                        <flux:table.cell
                            class="font-mono font-bold {{ $histRecord->is_current ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-500' }}">
                            {{ $histRecord->formatted_swim_time }}
                            @if($histRecord->is_current)
                                <flux:badge size="sm" color="blue" class="ml-2">Aktuell</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="text-sm">
                            {{ $histRecord->athlete?->display_name ?? '–' }}
                            <span class="text-zinc-400 text-xs ml-1">({{ $histRecord->athlete?->nation?->code }})</span>
                        </flux:table.cell>
                        <flux:table.cell
                            class="text-sm text-zinc-500">{{ $histRecord->meet_name ?? '–' }}</flux:table.cell>
                        <flux:table.cell
                            class="text-sm text-zinc-500">{{ $histRecord->set_date?->format('d.m.Y') ?? '–' }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm"
                                        color="{{ str_contains($histRecord->record_status, 'HISTORY') ? 'zinc' : 'emerald' }}">
                                {{ $histRecord->record_status }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if(!$histRecord->is_current)
                                <div class="flex items-center gap-1 justify-end">
                                    <form method="POST" action="{{ route('records.restore', $histRecord) }}"
                                          x-data
                                          @submit.prevent="if(confirm('Diesen Rekord als aktuellen Rekord wiederherstellen?')) $el.submit()">
                                        @csrf
                                        <flux:button type="submit" size="sm" variant="ghost" icon="arrow-path"
                                                     class="text-emerald-500" title="Wiederherstellen"/>
                                    </form>
                                    <form method="POST" action="{{ route('records.destroy', $histRecord) }}"
                                          x-data
                                          @submit.prevent="if(confirm('Historischen Rekord löschen?')) $el.submit()">
                                        @csrf @method('DELETE')
                                        <flux:button type="submit" size="sm" variant="ghost" icon="trash"
                                                     class="text-red-500" title="Löschen"/>
                                    </form>
                                </div>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

@endsection
