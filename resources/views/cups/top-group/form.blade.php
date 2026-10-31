@extends('layouts.app')

@section('title', 'Top-Gruppe von Hand einstufen — '.$cup->name)

@section('content')
    @php
        // Vorbelegung: eine bestehende Hand-Einstufung, sonst das Gegenteil der berechneten (darum geht es beim Setzen).
        $defaultTopGroup = $classification?->manual_is_top_group ?? ! ($classification?->calculated_is_top_group ?? false);
        $selectedTopGroup = (string) old('is_top_group', $defaultTopGroup ? '1' : '0');
        $selectedAthleteId = (string) old('athlete_id', '');
    @endphp

    <div class="max-w-2xl">
        <div class="mb-6">
            <div class="flex items-center gap-2">
                <flux:button href="{{ route('cups.top-group.index', $cup) }}" variant="primary" icon="arrow-left"
                             size="sm" title="Zurück" aria-label="Zurück"/>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Top-Gruppe von Hand einstufen</h1>
            </div>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                {{ $cup->name }}@if($athlete) · {{ $athlete->display_name }}@endif
            </p>
        </div>

        @if($classification)
            <div
                class="mb-4 p-4 bg-zinc-50 dark:bg-zinc-900/40 border border-zinc-200 dark:border-zinc-700 rounded-xl text-sm text-zinc-600 dark:text-zinc-300">
                Berechnet: <strong>{{ $classification->calculated_is_top_group ? 'Top-Gruppe' : 'nicht Top' }}</strong>
                ({{ $classification->reason?->label() ?? 'noch nicht berechnet' }}).
                Die Hand-Einstufung gilt stattdessen und bleibt bei jeder Neuberechnung erhalten; im Folgejahr zählt
                sie als Vorjahresstatus.
            </div>
        @endif

        <form method="POST"
              action="{{ $athlete ? route('cups.top-group.update', [$cup, $athlete]) : route('cups.top-group.store', $cup) }}"
              class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-6">
            @csrf
            @if($athlete)
                @method('PUT')
            @endif

            @unless($athlete)
                <flux:field>
                    <flux:label>Athlet</flux:label>
                    <flux:select variant="listbox" searchable name="athlete_id" placeholder="Suchen …" required>
                        @foreach($athletes as $candidate)
                            <flux:select.option value="{{ $candidate->id }}"
                                                :selected="$selectedAthleteId === (string) $candidate->id">
                                {{ $candidate->display_name }}
                                · {{ $candidate->birth_date?->format('Y') ?? 'ohne Jahrgang' }}
                                @if($candidate->club)
                                    · {{ $candidate->club->display_name }}
                                @endif
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:description>Nur Athleten, die für diesen Cup noch nicht eingestuft sind.</flux:description>
                    <flux:error name="athlete_id"/>
                </flux:field>
            @endunless

            <flux:field>
                <flux:label>Einstufung</flux:label>
                <flux:select variant="listbox" name="is_top_group" required>
                    <flux:select.option value="1" :selected="$selectedTopGroup === '1'">Top-Gruppe</flux:select.option>
                    <flux:select.option value="0" :selected="$selectedTopGroup === '0'">nicht in der Top-Gruppe</flux:select.option>
                </flux:select>
                <flux:error name="is_top_group"/>
            </flux:field>

            <flux:field>
                <flux:label>Begründung</flux:label>
                <flux:textarea name="manual_note" rows="3" required>{{ old('manual_note', $classification?->manual_note) }}</flux:textarea>
                <flux:error name="manual_note"/>
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:button href="{{ route('cups.top-group.index', $cup) }}" variant="ghost">Abbrechen</flux:button>
                <flux:button type="submit" variant="primary">Speichern</flux:button>
            </div>
        </form>

        @if($classification?->isManual())
            <form method="POST" action="{{ route('cups.top-group.destroy', [$cup, $athlete]) }}" class="mt-4 flex justify-end"
                  onsubmit="return confirm('Hand-Einstufung zurücknehmen? Es gilt dann wieder die berechnete Einstufung.');">
                @csrf
                @method('DELETE')
                <flux:button type="submit" variant="ghost" icon="arrow-uturn-left" class="text-red-500!">
                    Hand-Einstufung zurücknehmen
                </flux:button>
            </form>
        @endif
    </div>
@endsection
