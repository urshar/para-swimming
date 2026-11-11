@php
    use App\Models\SwimRecord;
@endphp

@extends('layouts.app')

@section('title', 'Prüfliste Rekorde – ausstehend')

@section('content')
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <div class="flex items-center gap-2">
            <flux:button href="{{ $backUrl }}" variant="primary" icon="arrow-left" size="sm"
                         title="Zurück" aria-label="Zurück zu den Rekorden"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Prüfliste Rekorde</h1>
        </div>
    </div>

    @include('records._review-tabs')

    <ul class="text-sm text-zinc-500 dark:text-zinc-400 mb-4 max-w-3xl space-y-1 list-disc ps-5">
        <li>
            Ausstehende Rekorde zählen noch nicht zur Rekord-Historie. Der Rekord-Check legt sie an bei einem Start
            <strong>außer Konkurrenz</strong>, bei einem Rekord aus einer <strong>Zwischenzeit</strong> und bei Staffeln,
            deren <strong>Vereinszugehörigkeit</strong> am Starttag nicht belegt ist. "Sonstige" sind von Hand oder per
            Import als ausstehend eingetragen.
        </li>
        <li>
            "Bestätigen" ordnet den Rekord an seinem Datum in die Rekord-Historie ein (spätere, nicht schnellere Rekorde
            werden ungültig) und setzt bei Bedarf die Landesrekorde. "Ungültig" verwirft ihn, ohne die Historie zu ändern.
        </li>
    </ul>

    {{-- Filter als Links wie bei den Import-Befunden: Zustand steht in der URL. --}}
    <div class="flex flex-wrap items-center gap-x-6 gap-y-2 mb-4">
        <nav class="flex flex-wrap gap-1" aria-label="Grund">
            @foreach($reasons as $value => $label)
                <flux:button size="sm" :variant="$reason === $value ? 'filled' : 'ghost'"
                             href="{{ route('records.import-review.pending', ['reason' => $value, 'kind' => $kind]) }}"
                             :aria-current="$reason === $value ? 'page' : null">{{ $label }}</flux:button>
            @endforeach
        </nav>
        <nav class="flex flex-wrap gap-1" aria-label="Einzel oder Staffel">
            @foreach($kinds as $value => $label)
                <flux:button size="sm" :variant="$kind === $value ? 'filled' : 'ghost'"
                             href="{{ route('records.import-review.pending', ['reason' => $reason, 'kind' => $value]) }}"
                             :aria-current="$kind === $value ? 'page' : null">{{ $label }}</flux:button>
            @endforeach
        </nav>
    </div>

    <div
        class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden p-4 [--flux-bleed:1rem]">
        @if($records->isEmpty())
            <p class="text-sm text-zinc-500 dark:text-zinc-400 py-6 text-center">Keine ausstehenden Rekorde.</p>
        @else
            <flux:table bleed>
                <flux:table.columns>
                    <flux:table.column>Rekord</flux:table.column>
                    <flux:table.column>Zeit</flux:table.column>
                    <flux:table.column>Athlet / Staffel</flux:table.column>
                    <flux:table.column>Wettkampf</flux:table.column>
                    <flux:table.column>Grund</flux:table.column>
                    <flux:table.column class="text-right">Aktionen</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach($records as $record)
                        @php
                            $reasonKey = $record->pending_reason;
                            $reasonColor = match ($reasonKey) {
                                SwimRecord::PENDING_REASON_EXHIBITION => 'amber',
                                SwimRecord::PENDING_REASON_SPLIT => 'violet',
                                SwimRecord::PENDING_REASON_CLUB => 'cyan',
                                default => 'zinc',
                            };
                        @endphp
                        <flux:table.row>
                            <flux:table.cell class="whitespace-normal">
                                <div class="flex flex-wrap items-center gap-1">
                                    <flux:badge size="sm" color="zinc">{{ $record->record_type }}</flux:badge>
                                    <a href="{{ route('records.show', $record) }}"
                                       class="font-medium text-zinc-900 dark:text-zinc-100 hover:underline">{{ $record->discipline_label }}</a>
                                </div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
                                    {{ $record->sport_class }}
                                    · <span aria-hidden="true">{{ SwimRecord::genderSymbol($record->gender) }}</span>
                                    <span class="sr-only">{{ match ($record->gender) { 'F' => 'Damen', 'X' => 'Mixed', default => 'Herren' } }}</span>
                                    · {{ $record->course }}
                                </div>
                            </flux:table.cell>
                            <flux:table.cell class="font-mono font-bold text-zinc-900 dark:text-zinc-100">
                                {{ $record->formatted_swim_time }}
                            </flux:table.cell>
                            <flux:table.cell class="text-sm text-zinc-600 dark:text-zinc-400 whitespace-normal">
                                @if($record->relay_count > 1)
                                    <div class="font-medium">{{ $record->record_club_name ?? $record->relayResult?->club?->display_name ?? 'Staffel' }}</div>
                                    @if($record->relayTeam->isNotEmpty())
                                        <div class="text-xs">
                                            {{ $record->relayTeam->pluck('display_name')->implode(', ') }}
                                        </div>
                                    @endif
                                @elseif($record->athlete)
                                    @if($record->athlete->nation)
                                        <x-flag code="{{ $record->athlete->nation->code }}"
                                                class="me-1 inline-block h-3 w-4 align-[-1px]"/>
                                    @endif
                                    <x-athlete-link :athlete="$record->athlete"
                                                    class="hover:text-blue-600 transition-colors">{{ $record->athlete->display_name }}</x-athlete-link>
                                    @if($record->record_club_name)
                                        <div class="text-xs">{{ $record->record_club_name }}</div>
                                    @endif
                                @else
                                    <span class="text-zinc-400">–</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="text-sm text-zinc-500 dark:text-zinc-400 whitespace-normal">
                                {{ $record->set_date?->format('d.m.Y') ?? '–' }}
                                @if($record->meet_name || $record->meet_city)
                                    <div class="text-xs">{{ collect([$record->meet_name, $record->meet_city])->filter()->implode(', ') }}</div>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="whitespace-normal">
                                <flux:badge size="sm" :color="$reasonColor">{{ $reasons[$reasonKey] }}</flux:badge>
                                @if($record->comment)
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">{{ $record->comment }}</div>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <form method="POST" action="{{ route('records.status.update', $record) }}">
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="record_status" value="APPROVED">
                                        <flux:button type="submit" size="xs" variant="primary" icon="check">
                                            Bestätigen
                                        </flux:button>
                                    </form>
                                    <form method="POST" action="{{ route('records.status.update', $record) }}"
                                          x-data="{ submit() { if (confirm('Rekord als ungültig verwerfen?')) this.$el.submit() } }"
                                          @submit.prevent="submit()">
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="record_status" value="INVALID">
                                        <flux:button type="submit" size="xs" variant="ghost">Ungültig</flux:button>
                                    </form>
                                    <flux:button href="{{ route('records.edit', $record) }}" size="xs" variant="ghost"
                                                 icon="pencil" class="text-amber-500!" title="Bearbeiten"
                                                 aria-label="Rekord bearbeiten"/>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>

    <div class="mt-4">
        {{ $records->links() }}
    </div>
@endsection
