@php use App\Http\Controllers\MeetScoringGroupRestrictionsController; use App\Models\Club; use App\Models\ScoringGroup; @endphp

@extends('layouts.app')

@section('title', 'Wertungsgruppen einschränken – ' . $meet->name)

@section('content')
    @php
        $currentMode = old('regional_mode', ScoringGroup::REGION_ONLY);
        $formConfig = [
            'prefix' => (string) old('prefix', ''),
            'mode' => (string) $currentMode,
            'associations' => ScoringGroup::parseCodes(old('regional_associations')),
            'levels' => ScoringGroup::parseLevels(old('levels')),
            'names' => $names->map(fn (array $row): array => ['name' => $row['name'], 'count' => $row['count']])->all(),
        ];
    @endphp
    <div class="max-w-5xl" x-data='scoringGroupRestrictionsForm(@json($formConfig))'>

        {{-- ── Kopf ──────────────────────────────────────────────────────────── --}}
        <div class="mb-6">
            <div class="flex items-center gap-2">
                <flux:button href="{{ $backUrl }}" variant="primary" icon="arrow-left" size="sm"
                             title="Zurück" aria-label="Zurück"/>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Wertungsgruppen einschränken</h1>
            </div>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">{{ $meet->name }}</p>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-2">
                Setzt Landesverband und Level an allen Wertungsgruppen dieser Veranstaltung, deren Name so beginnt, z.&nbsp;B.
                "LM Wien" → nur WBSV, "Gäste" → alle außer WBSV. Maßgeblich ist der Verein im Ergebnis bzw. das Level des
                Athleten zum Wettkampf. Bestehende Angaben der getroffenen Gruppen werden ersetzt (ohne Auswahl aufgehoben),
                danach werden die Plätze neu berechnet. Staffelgruppen bekommen kein Level.
            </p>
        </div>

        {{-- ── Einschränkung setzen ──────────────────────────────────────────── --}}
        @if($names->isNotEmpty())
            <div class="mb-6 bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 p-4">
                <form method="POST" action="{{ route('meets.scoring-group-restrictions.update', $meet) }}" class="space-y-4">
                    @csrf
                    @method('PUT')
                    {{-- Die Checkbox-Gruppen binden nur an Alpine; abgeschickt werden die Listen kommagetrennt. --}}
                    <input type="hidden" name="regional_associations" :value="associations.join(',')">
                    <input type="hidden" name="levels" :value="levels.join(',')">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <flux:field>
                            <flux:label>Name beginnt mit</flux:label>
                            <flux:input name="prefix" x-model="prefix" placeholder="LM Wien" required/>
                            <flux:description class="mt-1!">
                                <span x-text="matchingCount() === 1 ? '1 Wertungsgruppe' : matchingCount() + ' Wertungsgruppen'"></span>
                                betroffen
                            </flux:description>
                            <flux:error name="prefix"/>
                        </flux:field>
                        <flux:field>
                            <flux:label>Landesverband</flux:label>
                            <flux:select variant="listbox" name="regional_mode" x-model="mode">
                                <flux:select.option value="{{ MeetScoringGroupRestrictionsController::MODE_NONE }}"
                                                    :selected="$currentMode === MeetScoringGroupRestrictionsController::MODE_NONE">
                                    keine Einschränkung
                                </flux:select.option>
                                @foreach(ScoringGroup::REGION_MODES as $mode => $modeLabel)
                                    <flux:select.option value="{{ $mode }}" :selected="$currentMode === $mode">{{ $modeLabel }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:error name="regional_mode"/>
                        </flux:field>
                    </div>

                    <div x-show="mode !== '{{ MeetScoringGroupRestrictionsController::MODE_NONE }}'">
                        <flux:checkbox.group variant="pills" label="Landesverbände" x-model="associations">
                            @foreach(Club::REGIONAL_ASSOCIATION_STATES as $code => $state)
                                <flux:checkbox class="rounded-md!" value="{{ $code }}" label="{{ $code }}" title="{{ $state }}"/>
                            @endforeach
                        </flux:checkbox.group>
                        <flux:error name="regional_associations"/>
                    </div>

                    @if($levelOptions !== [])
                        <div>
                            <flux:checkbox.group variant="pills" label="Level" x-model="levels"
                                                 description="Ohne Auswahl keine Einschränkung nach Level.">
                                @foreach($levelOptions as $level)
                                    <flux:checkbox class="rounded-md!" value="{{ $level }}" label="{{ $level }}"/>
                                @endforeach
                            </flux:checkbox.group>
                            <flux:error name="levels"/>
                        </div>
                    @endif

                    <flux:button type="submit" variant="primary">Übernehmen</flux:button>
                </form>
            </div>
        @endif

        {{-- ── Wertungsgruppen der Veranstaltung ────────────────────────────── --}}
        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 p-4 [--flux-bleed:1rem]">
            @if($names->isEmpty())
                <p class="text-sm text-zinc-500 dark:text-zinc-400">Diese Veranstaltung hat keine Wertungsgruppen.</p>
            @else
                <flux:table bleed class="[&_td:first-child]:ps-4">
                    <flux:table.columns>
                        <flux:table.column>Wertungsgruppe</flux:table.column>
                        <flux:table.column class="w-24">Bewerbe</flux:table.column>
                        <flux:table.column>Einschränkung</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach($names as $row)
                            <flux:table.row x-bind:class="matches(names[{{ $loop->index }}].name) ? 'bg-blue-50 dark:bg-blue-950/30' : ''">
                                <flux:table.cell class="font-medium text-zinc-900 dark:text-white">{{ $row['name'] }}</flux:table.cell>
                                <flux:table.cell class="text-sm text-zinc-500 dark:text-zinc-400 tabular-nums">{{ $row['count'] }}</flux:table.cell>
                                <flux:table.cell class="text-sm text-zinc-500 dark:text-zinc-400">{{ implode(' / ', $row['restrictions']) }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </div>
    </div>
@endsection
