@php use App\Enums\Module;use App\Models\Coach;use App\Support\ListUrl; @endphp

@extends('layouts.app')

@section('title', $club->name)

@section('content')

    <div class="mb-6">
        <div class="flex items-center gap-2">
            <flux:button href="{{ ListUrl::to('clubs') }}" variant="primary" icon="arrow-left" size="sm"
                         title="Zurück" aria-label="Zurück"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $club->name }}</h1>
        </div>
        <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
            {{ $club->code ? $club->code . ' · ' : '' }}{{ $club->nation?->name_de }}
            @if($club->type !== 'CLUB')
                · {{ $club->type }}
            @endif
        </p>

        <div class="flex items-center flex-wrap justify-end gap-2 mt-4">
            @can('update', $club)
            <flux:button href="{{ route('clubs.edit', $club) }}" variant="filled" icon="pencil" size="sm"
                         class="text-amber-500!">
                Bearbeiten
            </flux:button>
            @endcan
        </div>
    </div>

    {{-- Kontaktdaten nur für Admins und den eigenen Verein (Spec club-permissions P7-4). --}}
    @can('viewContact', $club)
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-5 mb-6">
            <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-3">Kontakt</h2>
            <dl class="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-2 text-sm">
                <dt class="text-zinc-500 dark:text-zinc-400">Kontaktperson</dt>
                <dd>{{ $club->contact_name ?: '–' }}</dd>
                <dt class="text-zinc-500 dark:text-zinc-400">E-Mail</dt>
                <dd>
                    @if($club->contact_email)
                        <a href="mailto:{{ $club->contact_email }}" class="text-blue-600 dark:text-blue-400 hover:underline">{{ $club->contact_email }}</a>
                    @else
                        –
                    @endif
                </dd>
                <dt class="text-zinc-500 dark:text-zinc-400">Telefon</dt>
                <dd><x-phone-link :phone="$club->contact_phone"/></dd>
                <dt class="text-zinc-500 dark:text-zinc-400">Adresse</dt>
                <dd>
                    {{ collect([$club->address_street, trim($club->address_zip.' '.$club->address_city), $club->address_country])->filter()->implode(', ') ?: '–' }}
                </dd>
            </dl>
        </div>
    @endcan

    <div class="grid grid-cols-3 gap-4 mb-6">
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 text-center">
            <div class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $club->athletes_count }}</div>
            <div class="text-sm text-zinc-500 dark:text-zinc-400">Athleten</div>
        </div>
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 text-center">
            <div class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $club->entries_count }}</div>
            <div class="text-sm text-zinc-500 dark:text-zinc-400">Meldungen</div>
        </div>
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 text-center">
            <div class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $club->results_count }}</div>
            <div class="text-sm text-zinc-500 dark:text-zinc-400">Ergebnisse</div>
        </div>
    </div>

    {{-- Reiter Athleten / Trainer (Spec club-permissions P10b-2) als Links, damit der Reiter in der URL steht und
         Trainer-Formular und -Detailseite dorthin zurückführen. Den Trainer-Reiter gibt es nur mit Leserecht. --}}
    <div class="flex flex-wrap items-center justify-between gap-2 mb-3 border-b border-zinc-200 dark:border-zinc-700">
        <flux:navbar class="-mb-px" aria-label="Bereiche des Vereins">
            <flux:navbar.item :href="route('clubs.show', $club)" :current="$tab === 'athleten'">
                Athleten ({{ $club->athletes_count }})
            </flux:navbar.item>
            @if($coaches !== null)
                <flux:navbar.item :href="route('clubs.show', [$club, 'reiter' => 'trainer'])" :current="$tab === 'trainer'">
                    Trainer ({{ $coaches->count() }})
                </flux:navbar.item>
            @endif
        </flux:navbar>

        @if($tab === 'athleten' && auth()->user()?->canWriteAll(Module::Athletes))
            <flux:button href="{{ route('athletes.create') }}?club_id={{ $club->id }}" variant="ghost" icon="plus"
                         size="sm">
                Athlet anlegen
            </flux:button>
        @elseif($tab === 'trainer')
            @can('createForClub', [Coach::class, $club->id])
                <flux:button href="{{ route('coaches.create', ['club_id' => $club->id]) }}" variant="ghost" icon="plus" size="sm">
                    Trainer anlegen
                </flux:button>
            @endcan
        @endif
    </div>

    @if($tab === 'trainer')
        {{-- Trainer des Vereins (Spec club-permissions §4.6, P8-5). --}}
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden p-4 [--flux-bleed:1rem]">
        <flux:table bleed>
            <flux:table.columns>
                <flux:table.column>Trainer</flux:table.column>
                <flux:table.column>Stufe</flux:table.column>
                <flux:table.column>Telefon</flux:table.column>
                <flux:table.column>Status</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse($coaches as $coach)
                    <flux:table.row>
                        <flux:table.cell>
                            <a href="{{ route('coaches.show', $coach) }}"
                               class="font-medium text-zinc-900 dark:text-zinc-100 hover:text-blue-600 transition-colors">
                                {{ $coach->display_name }}
                            </a>
                        </flux:table.cell>
                        <flux:table.cell class="text-sm">{{ $coach->level->label() }}</flux:table.cell>
                        <flux:table.cell class="text-sm"><x-phone-link :phone="$coach->phone"/></flux:table.cell>
                        <flux:table.cell>
                            @if($coach->is_active)
                                <flux:badge size="sm" color="green">aktiv</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc">inaktiv</flux:badge>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4" class="text-center py-8 text-zinc-400">Keine Trainer erfasst.</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
        </div>
    @elseif($athletes === null)
        <flux:callout icon="eye-slash" color="zinc">
            <flux:callout.text>Die Athleten dieses Vereins sind für Sie nicht sichtbar.</flux:callout.text>
        </flux:callout>
    @else
    <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden p-4 [--flux-bleed:1rem]">
    <flux:table bleed>
        <flux:table.columns>
            <flux:table.column>Athlet</flux:table.column>
            <flux:table.column>Geschlecht</flux:table.column>
            <flux:table.column>Sport-Klassen</flux:table.column>
            <flux:table.column>Geburtsdatum</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse($athletes as $athlete)
                <flux:table.row>
                    <flux:table.cell>
                        <a href="{{ route('athletes.show', $athlete) }}"
                           class="font-medium text-zinc-900 dark:text-zinc-100 hover:text-blue-600 transition-colors">
                            {{ $athlete->display_name }}
                        </a>
                    </flux:table.cell>
                    <flux:table.cell class="text-sm text-zinc-500">
                        {{ match($athlete->gender) { 'M' => 'Herr', 'F' => 'Dame', default => 'Nicht binär' } }}
                    </flux:table.cell>
                    <flux:table.cell class="text-sm text-zinc-500 font-mono">
                        {{ $athlete->sport_classes_display ?: '–' }}
                    </flux:table.cell>
                    <flux:table.cell class="text-sm text-zinc-500">
                        {{ $athlete->birth_date?->format('d.m.Y') ?? '–' }}
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:button href="{{ route('athletes.show', $athlete) }}" size="sm" variant="ghost"
                                     icon="eye"/>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="text-center py-8 text-zinc-400">
                        Noch keine Athleten in diesem Verein.
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
    </div>

    <div class="mt-4">{{ $athletes->links() }}</div>
    @endif

    @if($history !== null)
        <div class="mt-6">
            @include('partials.audit-history', ['history' => $history])
        </div>
    @endif

@endsection
