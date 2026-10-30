{{-- Namensänderung beantragen (Spec club-permissions §4.4/§4.5): Vereine ändern Namen nicht direkt. --}}
@extends('layouts.app')

@section('title', 'Namensänderung beantragen')

@section('content')
    <div class="max-w-2xl">
        <div class="flex items-center gap-2 mb-6">
            <flux:button href="{{ route('athletes.show', $athlete) }}" variant="primary" icon="arrow-left" size="sm"
                         title="Zurück" aria-label="Zurück"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Namensänderung beantragen</h1>
        </div>

        <form method="POST" action="{{ route('change-requests.name.store', $athlete) }}"
              class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4">
            @csrf
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                Bisher: <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $athlete->display_name }}</span>.
                Der Verband entscheidet über den Antrag; Sie erhalten eine E-Mail.
            </p>
            <flux:error name="athlete"/>

            <div class="grid grid-cols-3 gap-4">
                <flux:field>
                    <flux:label>Namenspräfix</flux:label>
                    <flux:input name="name_prefix" value="{{ old('name_prefix', $athlete->name_prefix) }}"/>
                    <flux:error name="name_prefix"/>
                </flux:field>
                <flux:field>
                    <flux:label>Vorname<span class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
                    <flux:input name="first_name" value="{{ old('first_name', $athlete->first_name) }}" required/>
                    <flux:error name="first_name"/>
                </flux:field>
                <flux:field>
                    <flux:label>Nachname<span class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
                    <flux:input name="last_name" value="{{ old('last_name', $athlete->last_name) }}" required/>
                    <flux:error name="last_name"/>
                </flux:field>
            </div>

            <flux:field>
                <flux:label>Begründung<span class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
                <flux:textarea name="reason" rows="3" required
                               placeholder="z. B. Heirat, Tippfehler bei der Erfassung …">{{ old('reason') }}</flux:textarea>
                <flux:error name="reason"/>
            </flux:field>

            <div class="flex gap-3">
                <flux:button type="submit" variant="primary">Antrag stellen</flux:button>
                <flux:button href="{{ route('athletes.show', $athlete) }}" variant="ghost">Abbrechen</flux:button>
            </div>
        </form>
    </div>
@endsection
