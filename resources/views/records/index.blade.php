@extends('layouts.app')

@section('title', 'Rekordlisten')

@section('content')

    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Rekordlisten</h1>
        <flux:button href="{{ route('records.create') }}" variant="primary" icon="plus">Rekord eintragen</flux:button>
    </div>

    {{--
        Filter — eine Zeile, linksbündig. Jedes Feld löst bei Änderung sofort eine neue Suche aus
        (kein Filtern-Button mehr nötig) — dafür x-model + $watch statt
        onchange="this.form.submit()": das interne "change"-Event von flux:select (Custom Element
        <ui-select>) feuert mit bubbles:false, ein @change direkt draufgesetzt kam im Test nicht
        zuverlässig an (siehe qualifying-time-lists/qualifications.blade.php, gleiches Muster).
        x-model übernimmt dabei auch die Vorbelegung — :selected() auf den Optionen wird dadurch
        überflüssig (und war bei "type" ohnehin nötig durch eine eigene Fehlerquelle, siehe unten).
    --}}
    <form method="GET"
          class="flex flex-wrap items-center gap-3 mb-6"
          x-data="{
              category: @js($category),
              relayFilter: @js($relayFilter),
              baseType: @js($baseType),
              ageGroup: @js($ageGroup),
              sportClass: @js($sportClass),
              gender: @js($gender),
              course: @js($course),
              submitForm() { this.$el.submit(); },
          }"
          x-init="
              $watch('category', () => submitForm());
              $watch('relayFilter', () => submitForm());
              $watch('baseType', () => submitForm());
              $watch('ageGroup', () => submitForm());
              $watch('sportClass', () => submitForm());
              $watch('gender', () => submitForm());
              $watch('course', () => submitForm());
          ">
        <flux:select variant="listbox" name="category" x-model="category" class="w-40">
            <flux:select.option value="international">International</flux:select.option>
            <flux:select.option value="national">National</flux:select.option>
            <flux:select.option value="regional">Regional</flux:select.option>
        </flux:select>

        <flux:select variant="listbox" name="relay" x-model="relayFilter" class="w-36">
            <flux:select.option value="ALL">Alle</flux:select.option>
            <flux:select.option value="single">Einzel</flux:select.option>
            <flux:select.option value="relay">Staffeln</flux:select.option>
        </flux:select>

        {{--
            Region je Kategorie: International WR/ER/OR, National nur "Österreich" (ein
            Eintrag), Regional die Landesverbände als "Kürzel Bundesland" (Club::REGIONAL_
            ASSOCIATION_STATES). Ob Jugend- oder offene Rekorde gemeint sind, entscheidet das
            eigene Dropdown daneben. x-model="baseType" übernimmt die Vorbelegung mit dem vom
            Controller bereits auf einen gültigen Wert korrigierten Wert — anders als ein Vergleich
            gegen request('type', …) bleibt das auch beim Wechsel zwischen National/Regional
            korrekt, wo der ROHE Query-Parameter zunächst noch der alte, ungültige Wert ist (z.B.
            "AUT" nach dem Wechsel auf Regional).
        --}}
        <flux:select variant="listbox" name="type" x-model="baseType" class="w-64">
            @foreach($baseTypes as $type => $label)
                <flux:select.option value="{{ $type }}">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>

        {{--
            Jugend/Offen/Alle — nur für National/Regional, International kennt keine
            Jugendrekorde. "Alle" bewusst NICHT value="" — Flux' <flux:select> liest ein leeres
            value nicht zuverlässig (der schon dokumentierte Combobox-Bug), das führte dazu,
            dass age_group beim Absenden gar nicht erst im Request ankam und serverseitig
            immer auf den OPEN-Default zurückfiel.
        --}}
        @if($category !== 'international')
            <flux:select variant="listbox" name="age_group" x-model="ageGroup" class="w-32">
                <flux:select.option value="ALL">Alle</flux:select.option>
                <flux:select.option value="JR">Jugend</flux:select.option>
                <flux:select.option value="OPEN">Offen</flux:select.option>
            </flux:select>
        @endif

        {{-- Sportklasse: Optionen + Default hängen vom Einzel/Staffel-Filter ab (RecordController). --}}
        <flux:select variant="listbox" name="sport_class" x-model="sportClass" placeholder="Alle Klassen" clearable class="w-56">
            @foreach($sportClassOptions as $value => $label)
                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select variant="listbox" name="gender" x-model="gender" placeholder="Alle" clearable class="w-36">
            <flux:select.option value="M">Herren</flux:select.option>
            <flux:select.option value="F">Damen</flux:select.option>
        </flux:select>

        <flux:select variant="listbox" name="course" x-model="course" placeholder="Alle Bahnen" clearable class="w-44">
            <flux:select.option value="LCM">LCM (50m)</flux:select.option>
            <flux:select.option value="SCM">SCM (25m)</flux:select.option>
        </flux:select>
    </form>

    {{-- Aktiver Typ als Badge --}}
    <div class="flex items-center gap-2 mb-4">
        <flux:badge color="blue" size="sm">{{ $recordTypeLabel }}</flux:badge>
        @if($category !== 'international' && $ageGroup !== 'ALL')
            <flux:badge color="zinc" size="sm">{{ $ageGroup === 'JR' ? 'Jugend' : 'Offen' }}</flux:badge>
        @endif
        @if($sportClass)
            <flux:badge color="zinc" size="sm">{{ $sportClass }}</flux:badge>
        @endif
        @if($gender)
            <flux:badge color="{{ $gender === 'M' ? 'blue' : 'pink' }}" size="sm">
                {{ $gender === 'M' ? 'Herren' : 'Damen' }}
            </flux:badge>
        @endif
        @if($course)
            <flux:badge color="zinc" size="sm">{{ $course }}</flux:badge>
        @endif
    </div>

    {{--
        Klasse/Geschlecht/Bahn-Spalten: redundant, wenn genau darauf schon gefiltert ist (jede
        Zeile hätte ohnehin denselben Wert, siehe Badge-Zeile oben) — dann ausgeblendet.
        Jugend/Offen-Spalte: nur eingeblendet, wenn "Alle" gewählt ist — sonst mischen sich sonst
        nicht mehr unterscheidbare Jugend- und offene Rekorde in derselben Liste.
    --}}
    @php
        $showAgeGroupColumn = $category !== 'international' && $ageGroup === 'ALL';
        $columnCount = 7; // Disziplin, Zeit, Athlet/Team, Verein, Ort, Datum, Aktionen — immer sichtbar
        $columnCount += $sportClass ? 0 : 1;
        $columnCount += $gender ? 0 : 1;
        $columnCount += $course ? 0 : 1;
        $columnCount += $showAgeGroupColumn ? 1 : 0;
    @endphp
    <flux:table class="[&_td:first-child]:ps-4 [&_th:first-child]:ps-4 [&_td:last-child]:pe-4 [&_th:last-child]:pe-4">
        <flux:table.columns>
            @unless($sportClass)
                <flux:table.column>Klasse</flux:table.column>
            @endunless
            @unless($gender)
                <flux:table.column>Geschlecht</flux:table.column>
            @endunless
            @if($showAgeGroupColumn)
                <flux:table.column>Jugend/Offen</flux:table.column>
            @endif
            <flux:table.column>Disziplin</flux:table.column>
            @unless($course)
                <flux:table.column>Bahn</flux:table.column>
            @endunless
            <flux:table.column>Zeit</flux:table.column>
            <flux:table.column>Athlet / Team</flux:table.column>
            <flux:table.column>Verein</flux:table.column>
            <flux:table.column>Ort</flux:table.column>
            <flux:table.column>Datum</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse($records as $record)
                <flux:table.row>
                    @unless($sportClass)
                        <flux:table.cell>
                            <flux:badge size="sm" color="blue" class="font-mono">{{ $record->sport_class }}</flux:badge>
                        </flux:table.cell>
                    @endunless
                    @unless($gender)
                        <flux:table.cell>
                            <flux:badge size="sm" color="{{ $record->gender === 'M' ? 'blue' : 'pink' }}">
                                {{ $record->gender === 'M' ? 'Herren' : 'Damen' }}
                            </flux:badge>
                        </flux:table.cell>
                    @endunless
                    @if($showAgeGroupColumn)
                        @php $isJunior = str_ends_with($record->record_type, '.JR'); @endphp
                        <flux:table.cell>
                            <flux:badge size="sm" color="{{ $isJunior ? 'amber' : 'zinc' }}">
                                {{ $isJunior ? 'Jugend' : 'Offen' }}
                            </flux:badge>
                        </flux:table.cell>
                    @endif
                    <flux:table.cell class="font-medium text-sm">
                        @if($record->relay_count > 1)
                            <span class="text-zinc-400">{{ $record->relay_count }}x</span>
                        @endif
                        {{ $record->distance }}m {{ $record->strokeType?->name_de }}
                    </flux:table.cell>
                    @unless($course)
                        <flux:table.cell>
                            <flux:badge size="sm" color="zinc">{{ $record->course }}</flux:badge>
                        </flux:table.cell>
                    @endunless
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
                                            @if($member->birth_date)
                                                <span class="text-zinc-400">({{ $member->birth_date->format('Y') }})</span>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <span class="text-zinc-400 italic text-xs">Staffel</span>
                            @endif
                        @elseif($record->athlete)
                            @if($record->athlete->nation)
                                <x-flag code="{{ $record->athlete->nation->code }}" class="me-1 inline-block h-3 w-4 align-[-1px]"/>
                            @endif
                            <a href="{{ route('athletes.show', $record->athlete) }}"
                               class="hover:text-blue-600 transition-colors">
                                {{ $record->athlete->display_name }}
                            </a>
                            <span class="text-zinc-400">({{ $record->athlete->birth_date?->format('Y') ?? '–' }})</span>
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
                    <flux:table.cell class="text-sm text-zinc-500 whitespace-nowrap">
                        @if($record->meet_city)
                            @if($record->meetNation)
                                <x-flag code="{{ $record->meetNation->code }}" class="me-1 inline-block h-3 w-4 align-[-1px]"/>
                            @endif
                            {{ $record->meet_city }}
                        @else
                            <span class="text-zinc-400">–</span>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="text-sm text-zinc-500">
                        {{ $record->set_date?->format('d.m.Y') ?? '–' }}
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex items-center gap-1 justify-end">
                            <flux:button href="{{ route('records.show', $record) }}" size="sm" variant="ghost"
                                         icon="eye"/>
                            <flux:button href="{{ route('records.edit', $record) }}" size="sm" variant="ghost"
                                         icon="pencil" class="text-amber-500!"/>
                            <form method="POST" action="{{ route('records.destroy', $record) }}"
                                  x-data="{ submit() { if (confirm('Rekord löschen?')) this.$el.submit() } }"
                                  @submit.prevent="submit()">
                                @csrf @method('DELETE')
                                <flux:button type="submit" size="sm" variant="ghost" icon="trash" class="text-red-500!"/>
                            </form>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="{{ $columnCount }}" class="text-center py-12 text-zinc-400">
                        Keine Rekorde gefunden.
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <div class="mt-4">{{ $records->links() }}</div>

@endsection
