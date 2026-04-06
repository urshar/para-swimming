@extends('layouts.app')

@section('title', 'Rekordlisten')

@section('content')

    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Rekordlisten</h1>
        <flux:button href="{{ route('records.create') }}" variant="primary" icon="plus">Rekord eintragen</flux:button>
    </div>

    {{--
        Hauptkategorie-Tabs
        Kategorien: International | National | Regional
        Der aktive Tab wird über $category gesteuert (vom Controller übergeben).
    --}}
    <div class="flex gap-2 mb-4 flex-wrap">
        @foreach([
            'international' => 'International',
            'national'      => 'National',
            'regional'      => 'Regional',
        ] as $cat => $label)
            <a href="{{ route('records.index', ['category' => $cat]) }}"
               class="px-4 py-2 rounded-lg text-sm font-medium transition-colors
               {{ $category === $cat
                   ? 'bg-blue-600 text-white'
                   : 'bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-400 hover:border-blue-400' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>

    {{-- Einzel / Staffel Toggle --}}
    <div class="flex gap-2 mb-4">
        @foreach(['' => 'Alle', 'single' => 'Einzel', 'relay' => 'Staffeln'] as $val => $label)
            <a href="{{ route('records.index', array_merge(request()->query(), ['relay' => $val, 'page' => null])) }}"
               class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors
               {{ $relayFilter === $val
                   ? 'bg-zinc-800 dark:bg-zinc-200 text-white dark:text-zinc-900'
                   : 'bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-400 hover:border-zinc-400' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>

    {{-- Filter-Zeile: Untertyp-Dropdown + Sport-Klasse + Geschlecht + Bahn --}}
    <form method="GET" class="flex flex-wrap gap-3 mb-6">
        <input type="hidden" name="category" value="{{ $category }}">
        <input type="hidden" name="relay" value="{{ $relayFilter }}">

        {{-- Untertyp je nach Kategorie --}}
        @if($category === 'international')
            <flux:select name="type" class="w-44">
                @foreach(['WR' => 'Weltrekorde', 'ER' => 'Europarekorde', 'OR' => 'Olympische Rekorde'] as $type => $label)
                    <option value="{{ $type }}" @selected(request('type', 'WR') === $type)>{{ $label }}</option>
                @endforeach
            </flux:select>
        @elseif($category === 'national')
            <flux:select name="type" class="w-44">
                <option value="AUT" @selected(request('type', 'AUT') === 'AUT')>Österreich (gesamt)</option>
                <option value="AUT.JR" @selected(request('type') === 'AUT.JR')>Österreich Jugend</option>
            </flux:select>
        @else
            {{-- Regional: Verband-Dropdown + optional Jugend-Toggle --}}
            <flux:select name="type" class="w-56">
                @foreach($regionalTypes as $type => $label)
                    <option value="{{ $type }}" @selected(request('type', 'AUT.WBSV') === $type)>{{ $label }}</option>
                @endforeach
            </flux:select>
        @endif

        <flux:input name="sport_class" value="{{ request('sport_class') }}" placeholder="Klasse z.B. S4" class="w-28"/>

        <flux:select name="gender" class="w-36">
            <option value="">Alle</option>
            <option value="M" @selected(request('gender') === 'M')>Herren</option>
            <option value="F" @selected(request('gender') === 'F')>Damen</option>
        </flux:select>

        <flux:select name="course" class="w-36">
            <option value="">Alle Bahnen</option>
            <option value="LCM" @selected(request('course') === 'LCM')>LCM (50m)</option>
            <option value="SCM" @selected(request('course') === 'SCM')>SCM (25m)</option>
        </flux:select>

        <flux:button type="submit" icon="funnel">Filtern</flux:button>
    </form>

    {{-- Aktiver Typ als Badge --}}
    <div class="flex items-center gap-2 mb-4">
        <flux:badge color="blue" size="sm">{{ $recordTypeLabel }}</flux:badge>
        @if(request('sport_class'))
            <flux:badge color="zinc" size="sm">{{ request('sport_class') }}</flux:badge>
        @endif
        @if(request('gender'))
            <flux:badge color="{{ request('gender') === 'M' ? 'blue' : 'pink' }}" size="sm">
                {{ request('gender') === 'M' ? 'Herren' : 'Damen' }}
            </flux:badge>
        @endif
        @if(request('course'))
            <flux:badge color="zinc" size="sm">{{ request('course') }}</flux:badge>
        @endif
    </div>

    <flux:table class="[&_td:first-child]:ps-4 [&_th:first-child]:ps-4 [&_td:last-child]:pe-4 [&_th:last-child]:pe-4">
        <flux:table.columns>
            <flux:table.column>Klasse</flux:table.column>
            <flux:table.column>Geschlecht</flux:table.column>
            <flux:table.column>Disziplin</flux:table.column>
            <flux:table.column>Bahn</flux:table.column>
            <flux:table.column>Zeit</flux:table.column>
            <flux:table.column>Athlet / Team</flux:table.column>
            <flux:table.column>Verein</flux:table.column>
            <flux:table.column>Datum</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse($records as $record)
                <flux:table.row>
                    <flux:table.cell>
                        <flux:badge size="sm" color="blue" class="font-mono">{{ $record->sport_class }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" color="{{ $record->gender === 'M' ? 'blue' : 'pink' }}">
                            {{ $record->gender === 'M' ? 'Herren' : 'Damen' }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell class="font-medium text-sm">
                        @if($record->relay_count > 1)
                            <span class="text-zinc-400">{{ $record->relay_count }}x</span>
                        @endif
                        {{ $record->distance }}m {{ $record->strokeType?->name_de }}
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" color="zinc">{{ $record->course }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell class="font-mono font-bold text-zinc-900 dark:text-zinc-100">
                        {{ $record->formatted_swim_time }}
                    </flux:table.cell>
                    <flux:table.cell class="text-sm text-zinc-600 dark:text-zinc-400">
                        @if($record->relay_count > 1)
                            {{-- Staffel: Team-Mitglieder --}}
                            @if($record->relayTeam->isNotEmpty())
                                <div class="space-y-0.5">
                                    @foreach($record->relayTeam as $member)
                                        <div class="text-xs">
                                            <span class="text-zinc-400 font-mono w-4 inline-block">{{ $member->position }}.</span>
                                            @if($member->athlete_id)
                                                <a href="{{ route('athletes.show', $member->athlete_id) }}"
                                                   class="hover:text-blue-600 transition-colors">
                                                    {{ $member->display_name }}
                                                </a>
                                            @else
                                                {{ $member->display_name }}
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <span class="text-zinc-400 italic text-xs">Staffel</span>
                            @endif
                        @elseif($record->athlete)
                            <a href="{{ route('athletes.show', $record->athlete) }}"
                               class="hover:text-blue-600 transition-colors">
                                {{ $record->athlete->display_name }}
                            </a>
                            <span class="text-zinc-400">({{ $record->athlete->nation?->code }})</span>
                        @else
                            <span class="text-zinc-400">–</span>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="text-sm text-zinc-500">
                        {{-- Verein zum Zeitpunkt des Rekords (kann vom aktuellen Verein abweichen) --}}
                        {{ $record->club?->name ?? $record->club?->short_name
                            ?? $record->athlete?->club?->short_name
                            ?? $record->athlete?->club?->name ?? '–' }}
                    </flux:table.cell>
                    <flux:table.cell class="text-sm text-zinc-500">
                        {{ $record->set_date?->format('d.m.Y') ?? '–' }}
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex items-center gap-1 justify-end">
                            <flux:button href="{{ route('records.show', $record) }}" size="sm" variant="ghost"
                                         icon="eye"/>
                            <flux:button href="{{ route('records.edit', $record) }}" size="sm" variant="ghost"
                                         icon="pencil"/>
                            <form method="POST" action="{{ route('records.destroy', $record) }}"
                                  x-data @submit.prevent="if(confirm('Rekord löschen?')) $el.submit()">
                                @csrf @method('DELETE')
                                <flux:button type="submit" size="sm" variant="ghost" icon="trash" class="text-red-500"/>
                            </form>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="9" class="text-center py-12 text-zinc-400">
                        Keine Rekorde gefunden.
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <div class="mt-4">{{ $records->links() }}</div>

@endsection
