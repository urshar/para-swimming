{{-- admin/change-requests/show — Antrag mit Vorher/Nachher, freigeben oder ablehnen (Spec club-permissions §4.5,
     P6-3: Antwort beim Ablehnen Pflicht, P6-4: Eintrittsdatum bei der Ummeldung änderbar). --}}
@php use App\Models\ChangeRequest; use Illuminate\Support\Carbon; @endphp

@extends('layouts.app')

@section('title', 'Antrag: '.$changeRequest->type_label)

@section('content')
    @php
        $athlete = $changeRequest->athlete;
        $isName = $changeRequest->type === ChangeRequest::TYPE_NAME;
        $values = $changeRequest->values;
    @endphp
    <div class="max-w-3xl">
        <div class="flex items-center gap-2 mb-6">
            <flux:button href="{{ route('admin.change-requests.index') }}" variant="primary" icon="arrow-left" size="sm"
                         title="Zurück" aria-label="Zurück"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $changeRequest->type_label }}</h1>
            <flux:badge size="sm" :color="$changeRequest->status_color">{{ $changeRequest->status_label }}</flux:badge>
        </div>

        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-5 mb-6">
            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                <dt class="text-zinc-500 dark:text-zinc-400">Athlet</dt>
                <dd>
                    @if($athlete)
                        <a href="{{ route('athletes.show', $athlete) }}" class="underline">{{ $athlete->display_name }}</a>
                        @if($athlete->trashed()) <flux:badge size="sm" color="red">gelöscht</flux:badge> @endif
                    @endif
                </dd>
                <dt class="text-zinc-500 dark:text-zinc-400">Beantragt von</dt>
                <dd>{{ $changeRequest->club?->display_name ?? '–' }} · {{ $changeRequest->requested_by_name ?? 'unbekannt' }}
                    · {{ $changeRequest->created_at?->format('d.m.Y H:i') }}</dd>
                @if($isName)
                    <dt class="text-zinc-500 dark:text-zinc-400">Bisher</dt>
                    <dd>{{ trim(($athlete?->name_prefix ? $athlete->name_prefix.' ' : '').$athlete?->first_name.' '.$athlete?->last_name) }}</dd>
                    <dt class="text-zinc-500 dark:text-zinc-400">Neu</dt>
                    <dd class="font-medium">{{ trim((($values['name_prefix'] ?? null) ? $values['name_prefix'].' ' : '').$values['first_name'].' '.$values['last_name']) }}</dd>
                @else
                    <dt class="text-zinc-500 dark:text-zinc-400">Bisheriger Verein</dt>
                    <dd>{{ $changeRequest->fromClub?->display_name ?? 'ohne Verein' }}
                        @if($athlete && $athlete->club_id !== $changeRequest->from_club_id)
                            (inzwischen {{ $athlete->club?->display_name ?? 'ohne Verein' }})
                        @endif
                    </dd>
                    <dt class="text-zinc-500 dark:text-zinc-400">Neuer Verein</dt>
                    <dd class="font-medium">{{ $changeRequest->club?->display_name }}</dd>
                    <dt class="text-zinc-500 dark:text-zinc-400">Eintritt</dt>
                    <dd>{{ Carbon::parse($values['joined_at'])->format('d.m.Y') }}</dd>
                @endif
                <dt class="text-zinc-500 dark:text-zinc-400">Begründung</dt>
                <dd class="whitespace-pre-line">{{ $changeRequest->reason }}</dd>
                @if($changeRequest->decided_at)
                    <dt class="text-zinc-500 dark:text-zinc-400">Entschieden</dt>
                    <dd>{{ $changeRequest->decided_by_name ?? '–' }} · {{ $changeRequest->decided_at?->format('d.m.Y H:i') ?? '–' }}</dd>
                    <dt class="text-zinc-500 dark:text-zinc-400">Antwort</dt>
                    <dd class="whitespace-pre-line">{{ $changeRequest->response ?: '–' }}</dd>
                @endif
            </dl>
        </div>

        @if($changeRequest->isOpen())
            <flux:error name="decision" class="mb-4"/>

            <div class="grid md:grid-cols-2 gap-6">
                <form method="POST" action="{{ route('admin.change-requests.approve', $changeRequest) }}"
                      class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-5 space-y-4">
                    @csrf
                    <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">Freigeben</h2>
                    @unless($isName)
                        <flux:field>
                            <flux:label>Vereinseintritt</flux:label>
                            <flux:date-picker type="input" locale="de-AT" selectable-header name="joined_at"
                                              value="{{ old('joined_at', $values['joined_at']) }}" required/>
                            <flux:error name="joined_at"/>
                        </flux:field>
                    @endunless
                    <flux:field>
                        <flux:label>Antwort an den Verein (optional)</flux:label>
                        <flux:textarea name="response" rows="3"></flux:textarea>
                    </flux:field>
                    <flux:button type="submit" variant="primary" icon="check">Freigeben</flux:button>
                </form>

                <form method="POST" action="{{ route('admin.change-requests.reject', $changeRequest) }}"
                      class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-5 space-y-4">
                    @csrf
                    <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">Ablehnen</h2>
                    <flux:field>
                        <flux:label>Antwort an den Verein<span class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
                        <flux:textarea name="response" rows="3" required>{{ old('response') }}</flux:textarea>
                        <flux:error name="response"/>
                    </flux:field>
                    <flux:button type="submit" variant="danger" icon="x-mark">Ablehnen</flux:button>
                </form>
            </div>
        @endif
    </div>
@endsection
