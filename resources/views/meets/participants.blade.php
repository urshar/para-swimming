@php
    use App\Http\Controllers\MeetParticipantsController;
@endphp

@extends('layouts.app')

@section('title', 'Teilnehmer – ' . $meet->name)

@section('content')
    @php
        $isClubs = $view === MeetParticipantsController::VIEW_CLUBS;
    @endphp

    {{-- ── Kopf ──────────────────────────────────────────────────────────────── --}}
    <div class="mb-6">
        <div class="flex items-center gap-2">
            <flux:button href="{{ route('meets.show', $meet) }}" variant="primary" icon="arrow-left"
                         size="sm" title="Zurück" aria-label="Zurück zur Veranstaltung"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Teilnehmer</h1>
        </div>
        <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">{{ $meet->name }}</p>
    </div>

    {{-- Ansicht als Links: Zustand steht in der URL. --}}
    <nav class="flex flex-wrap gap-1 mb-4" aria-label="Ansicht">
        <flux:button size="sm" :variant="$isClubs ? 'ghost' : 'filled'"
                     href="{{ route('meets.participants', $meet) }}"
                     :aria-current="$isClubs ? null : 'page'">Athleten ({{ $athleteCount }})
        </flux:button>
        <flux:button size="sm" :variant="$isClubs ? 'filled' : 'ghost'"
                     href="{{ route('meets.participants', [$meet, 'ansicht' => MeetParticipantsController::VIEW_CLUBS]) }}"
                     :aria-current="$isClubs ? 'page' : null">Vereine ({{ $clubRows->count() }})
        </flux:button>
    </nav>

    @if($isClubs)
        <div
            class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden p-4 [--flux-bleed:1rem]">
            @if($clubRows->isEmpty())
                <p class="text-sm text-zinc-500 dark:text-zinc-400 py-6 text-center">Keine teilnehmenden Vereine.</p>
            @else
                <flux:table bleed class="[&_td:first-child]:ps-4">
                    <flux:table.columns>
                        <flux:table.column>Verein</flux:table.column>
                        <flux:table.column align="end">Athleten</flux:table.column>
                        <flux:table.column align="end">Einzelmeldungen</flux:table.column>
                        <flux:table.column align="end">Staffelmeldungen</flux:table.column>
                        <flux:table.column align="end">Ergebnisse</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach($clubRows as $clubRow)
                            <flux:table.row>
                                <flux:table.cell class="font-medium whitespace-normal">
                                    <a href="{{ route('meets.participants', [$meet, 'club_id' => $clubRow['club']->id]) }}"
                                       class="text-zinc-900 dark:text-zinc-100 hover:underline"
                                       title="Athleten dieses Vereins anzeigen">{{ $clubRow['club']->display_name }}</a>
                                </flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $clubRow['athletes'] }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $clubRow['entries'] }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $clubRow['relays'] }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $clubRow['results'] }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </div>
    @else
        <form method="GET" action="{{ route('meets.participants', $meet) }}"
              class="flex flex-wrap items-end gap-3 mb-4">
            <flux:select variant="listbox" name="club_id" placeholder="Alle Vereine" clearable searchable
                         class="w-72" aria-label="Verein">
                @foreach($clubs as $club)
                    <flux:select.option value="{{ $club->id }}" :selected="$clubFilter === $club->id">
                        {{ $club->display_name }}
                    </flux:select.option>
                @endforeach
            </flux:select>
            <flux:input name="suche" value="{{ $search }}" placeholder="Name suchen" icon="magnifying-glass"
                        class="w-60" aria-label="Name suchen"/>
            <flux:button type="submit" size="sm" variant="primary">Filtern</flux:button>
            @if($clubFilter !== null || $search !== '')
                <flux:button href="{{ route('meets.participants', $meet) }}" size="sm" variant="ghost">
                    Zurücksetzen
                </flux:button>
            @endif
        </form>

        <div
            class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden p-4 [--flux-bleed:1rem]">
            @if($rows->isEmpty())
                <p class="text-sm text-zinc-500 dark:text-zinc-400 py-6 text-center">Keine Teilnehmer gefunden.</p>
            @else
                <flux:table bleed class="[&_td:first-child]:ps-4">
                    <flux:table.columns>
                        <flux:table.column>Name</flux:table.column>
                        <flux:table.column>Jahrgang</flux:table.column>
                        <flux:table.column>Verein</flux:table.column>
                        <flux:table.column>Sportklasse</flux:table.column>
                        <flux:table.column align="end">Einzel</flux:table.column>
                        <flux:table.column align="end">Staffeln</flux:table.column>
                        <flux:table.column align="end">Ergebnisse</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach($rows as $row)
                            <flux:table.row>
                                <flux:table.cell class="font-medium whitespace-normal">
                                    <a href="{{ route('athletes.show', $row['athlete']) }}"
                                       class="text-zinc-900 dark:text-zinc-100 hover:underline">{{ $row['athlete']->display_name }}</a>
                                </flux:table.cell>
                                <flux:table.cell class="tabular-nums">{{ $row['athlete']->birth_date?->format('Y') ?? '–' }}</flux:table.cell>
                                <flux:table.cell class="whitespace-normal">{{ $row['club']?->display_name ?? '–' }}</flux:table.cell>
                                <flux:table.cell>{{ $row['athlete']->sport_classes_display ?: '–' }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $row['entries'] }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $row['relays'] }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $row['results'] }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
                @if($rows->count() < $athleteCount)
                    <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-3">{{ $rows->count() }} von {{ $athleteCount }}
                        Teilnehmern.</p>
                @endif
            @endif
        </div>
    @endif
@endsection
