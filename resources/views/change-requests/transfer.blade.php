{{-- Ummeldung beantragen (Spec club-permissions §4.5, N2, P6-2): Der aufnehmende Verein sieht fremde Athleten nicht
     und gibt deshalb genaue Angaben ein; das System zeigt keine Daten des gefundenen Athleten an. --}}
@extends('layouts.app')

@section('title', 'Ummeldung beantragen')

@section('content')
    <div class="max-w-2xl">
        <div class="flex items-center gap-2 mb-6">
            <flux:button href="{{ route('change-requests.index') }}" variant="primary" icon="arrow-left" size="sm"
                         title="Zurück" aria-label="Zurück"/>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Ummeldung beantragen</h1>
        </div>

        <form method="POST" action="{{ route('change-requests.transfer.store') }}"
              class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4">
            @csrf
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                Für einen Athleten, der bisher bei einem anderen Verein gemeldet ist. Bitte Namen und Geburtsdatum genau
                so angeben, wie sie beim Verband erfasst sind. Der Verband entscheidet über den Antrag; Sie erhalten eine
                E-Mail.
            </p>

            @error('athlete')
                <flux:callout icon="exclamation-triangle" color="amber">
                    <flux:callout.text>{{ $message }}</flux:callout.text>
                </flux:callout>
            @enderror

            <div class="grid grid-cols-3 gap-4">
                <flux:field>
                    <flux:label>Vorname<span class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
                    <flux:input name="first_name" value="{{ old('first_name') }}" required/>
                    <flux:error name="first_name"/>
                </flux:field>
                <flux:field>
                    <flux:label>Nachname<span class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
                    <flux:input name="last_name" value="{{ old('last_name') }}" required/>
                    <flux:error name="last_name"/>
                </flux:field>
                <flux:field>
                    <flux:label>Geburtsdatum<span class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
                    <flux:date-picker type="input" locale="de-AT" selectable-header name="birth_date"
                                      value="{{ old('birth_date') }}" required/>
                    <flux:error name="birth_date"/>
                </flux:field>
            </div>

            <flux:field class="max-w-xs">
                <flux:label>Gewünschter Vereinseintritt<span class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
                <flux:date-picker type="input" locale="de-AT" selectable-header name="joined_at"
                                  value="{{ old('joined_at', today()->format('Y-m-d')) }}" required/>
                <flux:error name="joined_at"/>
            </flux:field>

            <flux:field>
                <flux:label>Begründung<span class="text-red-500 dark:text-red-400 ms-1">*</span></flux:label>
                <flux:textarea name="reason" rows="3" required
                               placeholder="z. B. Wohnortwechsel, Vereinswechsel zum Saisonbeginn …">{{ old('reason') }}</flux:textarea>
                <flux:error name="reason"/>
            </flux:field>

            <div class="flex gap-3">
                <flux:button type="submit" variant="primary">Antrag stellen</flux:button>
                <flux:button href="{{ route('change-requests.index') }}" variant="ghost">Abbrechen</flux:button>
            </div>
        </form>
    </div>
@endsection
