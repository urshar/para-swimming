@php use App\Enums\CoachLevel;use App\Models\CoachQualification;use App\Support\ListUrl; @endphp

{{-- coaches/show.blade.php — Trainer mit Aus- und Fortbildungen (Spec club-permissions N6, P8-1/P8-2) --}}
@extends('layouts.app')

@section('title', $coach->display_name)

@section('content')

    <div class="mb-6">
        <div class="flex items-center gap-2">
            <flux:button href="{{ ListUrl::to('coaches') }}" variant="primary" icon="arrow-left" size="sm"
                         title="Zurück" aria-label="Zurück"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $coach->display_name }}</h1>
            @unless($coach->is_active)
                <flux:badge size="sm" color="zinc">inaktiv</flux:badge>
            @endunless
        </div>
        <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
            <a href="{{ route('clubs.show', $coach->club_id) }}" class="hover:underline">{{ $coach->club?->display_name }}</a>
            · {{ $coach->level->label() }}
        </p>

        <div class="flex items-center flex-wrap justify-end gap-2 mt-4">
            @can('update', $coach)
                <flux:button href="{{ route('coaches.edit', $coach) }}" variant="filled" icon="pencil" size="sm"
                             class="text-amber-500!">
                    Bearbeiten
                </flux:button>
            @endcan
            @can('delete', $coach)
                <form method="POST" action="{{ route('coaches.destroy', $coach) }}"
                      x-data="{ submit() { if (confirm('Trainer samt Aus- und Fortbildungen löschen?')) this.$el.submit() } }"
                      @submit.prevent="submit()">
                    @csrf @method('DELETE')
                    <flux:button type="submit" variant="filled" icon="trash" size="sm" class="text-red-500!">Löschen</flux:button>
                </form>
            @endcan
        </div>
    </div>

    <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-5 mb-6">
        <dl class="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-2 text-sm">
            <dt class="text-zinc-500 dark:text-zinc-400">E-Mail</dt>
            <dd>
                @if($coach->email)
                    <a href="mailto:{{ $coach->email }}" class="text-blue-600 dark:text-blue-400 hover:underline">{{ $coach->email }}</a>
                @else
                    –
                @endif
            </dd>
            <dt class="text-zinc-500 dark:text-zinc-400">Login</dt>
            <dd>{{ $coach->user?->name ?? 'nicht verknüpft' }}</dd>
            <dt class="text-zinc-500 dark:text-zinc-400">Notizen</dt>
            <dd class="whitespace-pre-line">{{ $coach->notes ?: '–' }}</dd>
        </dl>
    </div>

    <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100 mb-3">Aus- und Fortbildungen</h2>

    <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden p-4 [--flux-bleed:1rem] mb-6">
    <flux:table bleed>
        <flux:table.columns>
            <flux:table.column>Datum</flux:table.column>
            <flux:table.column>Art</flux:table.column>
            <flux:table.column>Stufe</flux:table.column>
            <flux:table.column>Bezeichnung</flux:table.column>
            <flux:table.column>Notiz</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse($coach->qualifications as $qualification)
                <flux:table.row>
                    <flux:table.cell class="text-sm">{{ $qualification->completed_on->format('d.m.Y') }}</flux:table.cell>
                    <flux:table.cell class="text-sm">{{ $qualification->kind_label }}</flux:table.cell>
                    <flux:table.cell class="text-sm">{{ $qualification->level?->label() ?? '–' }}</flux:table.cell>
                    <flux:table.cell class="text-sm">{{ $qualification->title ?: '–' }}</flux:table.cell>
                    <flux:table.cell class="text-sm text-zinc-500 whitespace-pre-line">{{ $qualification->notes ?: '–' }}</flux:table.cell>
                    <flux:table.cell>
                        @can('update', $coach)
                            <form method="POST" action="{{ route('coaches.qualifications.destroy', [$coach, $qualification]) }}"
                                  x-data="{ submit() { if (confirm('Eintrag löschen?')) this.$el.submit() } }"
                                  @submit.prevent="submit()" class="flex justify-end">
                                @csrf @method('DELETE')
                                <flux:button type="submit" size="sm" variant="ghost" icon="trash" class="text-red-500!"
                                             aria-label="Eintrag löschen"/>
                            </form>
                        @endcan
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="text-center py-8 text-zinc-400">Noch keine Einträge.</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
    </div>

    @can('update', $coach)
        @php $oldKind = old('kind', CoachQualification::KIND_EDUCATION); @endphp
        <form method="POST" action="{{ route('coaches.qualifications.store', $coach) }}"
              class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-5 space-y-4 max-w-2xl">
            @csrf
            <h3 class="font-semibold text-zinc-900 dark:text-zinc-100">Eintrag hinzufügen</h3>
            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>Art <span class="text-red-500 dark:text-red-400">*</span></flux:label>
                    <flux:select variant="listbox" name="kind" required>
                        @foreach(CoachQualification::KIND_LABELS as $value => $label)
                            <flux:select.option value="{{ $value }}" :selected="$oldKind === $value">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="kind"/>
                </flux:field>
                <flux:field>
                    <flux:label>Stufe</flux:label>
                    <flux:select variant="listbox" name="level" placeholder="Wählen…" clearable>
                        @foreach(CoachLevel::trained() as $option)
                            <flux:select.option value="{{ $option->value }}" :selected="old('level') === $option->value">{{ $option->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:description class="mt-1!">Pflicht bei Ausbildung, bei Fortbildung ohne Bedeutung.</flux:description>
                    <flux:error name="level"/>
                </flux:field>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>Bezeichnung</flux:label>
                    <flux:input name="title" value="{{ old('title') }}" placeholder="z. B. Übungsleiter Behindertensport"/>
                    <flux:error name="title"/>
                </flux:field>
                <flux:field>
                    <flux:label>Datum <span class="text-red-500 dark:text-red-400">*</span></flux:label>
                    <flux:date-picker type="input" locale="de-AT" selectable-header name="completed_on"
                                      value="{{ old('completed_on') }}" required/>
                    <flux:error name="completed_on"/>
                </flux:field>
            </div>
            <flux:field>
                <flux:label>Notiz</flux:label>
                <flux:textarea name="notes" rows="2">{{ old('notes') }}</flux:textarea>
                <flux:error name="notes"/>
            </flux:field>
            <flux:button type="submit" variant="primary" icon="plus">Eintragen</flux:button>
        </form>
    @endcan

@endsection
