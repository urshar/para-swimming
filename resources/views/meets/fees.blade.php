@php
    use App\Models\MeetFee;
    use App\Support\Money;
@endphp

@extends('layouts.app')

@section('title', 'Meldegelder – ' . $meet->name)

@section('content')
    <div class="max-w-4xl">
        <div class="mb-6">
            <div class="flex items-center gap-2">
                <flux:button href="{{ route('meets.show', $meet) }}" variant="primary" icon="arrow-left" size="sm"
                             title="Zurück" aria-label="Zurück"/>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Meldegelder</h1>
            </div>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">{{ $meet->name }}</p>
        </div>

        @if($errors->any())
            <div role="alert"
                 class="mb-4 p-4 bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-800 rounded-xl text-sm text-red-700 dark:text-red-400">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('meets.fees.update', $meet) }}" class="space-y-6">
            @csrf
            @method('PUT')

            {{-- Gebühren je Typ (LENEX FEES): Spalte "Veranstaltung" = MEET > FEES, je Abschnitt = SESSION > FEES --}}
            <section aria-labelledby="fees-by-type"
                     class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                <h2 id="fees-by-type" class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">Gebühren je Typ</h2>
                <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1 mb-4">
                    Beträge in Euro, leer = keine Gebühr. "Veranstaltung" wird einmal je Veranstaltung berechnet, eine
                    Abschnitts-Spalte einmal je Abschnitt, in dem der Verein bzw. Athlet startet.
                </p>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-zinc-500 dark:text-zinc-400">
                                <th scope="col" class="py-2 pe-4 font-medium">Typ</th>
                                <th scope="col" class="py-2 pe-4 font-medium">Veranstaltung</th>
                                @foreach($sessionNumbers as $number)
                                    <th scope="col" class="py-2 pe-4 font-medium">Abschnitt {{ $number }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($typeKeys as $key => $info)
                                <tr class="border-t border-zinc-100 dark:border-zinc-700">
                                    <th scope="row" class="py-2 pe-4 text-start font-normal text-zinc-900 dark:text-zinc-100 align-middle">
                                        {{ $info['label'] }}
                                        @if(in_array($info['type'], MeetFee::NOT_CALCULATED, true))
                                            <span class="block text-xs text-zinc-500 dark:text-zinc-400">wird noch nicht berechnet</span>
                                        @endif
                                    </th>
                                    <td class="py-2 pe-4">
                                        <flux:input name="fees[meet][{{ $key }}]" size="sm" inputmode="decimal"
                                                    :invalid="$errors->has('fees.meet.'.$key)"
                                                    value="{{ old('fees.meet.'.$key, $values['meet'][$key] ?? null) }}"
                                                    aria-label="{{ $info['label'] }} – Veranstaltung" class="w-28"/>
                                    </td>
                                    @foreach($sessionNumbers as $number)
                                        <td class="py-2 pe-4">
                                            <flux:input name="fees[session][{{ $number }}][{{ $key }}]" size="sm"
                                                        :invalid="$errors->has('fees.session.'.$number.'.'.$key)"
                                                        inputmode="decimal"
                                                        value="{{ old('fees.session.'.$number.'.'.$key, $values[(string) $number][$key] ?? null) }}"
                                                        aria-label="{{ $info['label'] }} – Abschnitt {{ $number }}" class="w-28"/>
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- Gebühr je Meldung pro Bewerb (LENEX EVENT > FEE) --}}
            <section aria-labelledby="fees-by-event"
                     class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                <h2 id="fees-by-event" class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">Gebühr je Meldung pro Bewerb</h2>
                <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1 mb-4">
                    Je Einzelstart bzw. je Staffel. Bei Staffelbewerben hat die Bewerbsgebühr Vorrang vor "Je Staffel".
                </p>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-5">
                    <flux:field>
                        <flux:label>Für alle Einzelbewerbe setzen</flux:label>
                        <flux:input name="bulk_individual" inputmode="decimal" value="{{ old('bulk_individual') }}"/>
                        <flux:description>Überschreibt beim Speichern die Werte aller Einzelbewerbe unten.</flux:description>
                    </flux:field>
                    <flux:field>
                        <flux:label>Für alle Staffelbewerbe setzen</flux:label>
                        <flux:input name="bulk_relay" inputmode="decimal" value="{{ old('bulk_relay') }}"/>
                        <flux:description>Überschreibt beim Speichern die Werte aller Staffelbewerbe unten.</flux:description>
                    </flux:field>
                </div>

                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-zinc-500 dark:text-zinc-400">
                            <th scope="col" class="py-2 pe-4 font-medium">Nr.</th>
                            <th scope="col" class="py-2 pe-4 font-medium">Bewerb</th>
                            <th scope="col" class="py-2 pe-4 font-medium">Abschnitt</th>
                            <th scope="col" class="py-2 pe-4 font-medium">Gebühr</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($events as $event)
                            <tr class="border-t border-zinc-100 dark:border-zinc-700">
                                <td class="py-2 pe-4 tabular-nums">{{ $event->event_number }}</td>
                                <th scope="row" class="py-2 pe-4 text-start font-normal text-zinc-900 dark:text-zinc-100">
                                    {{ $event->display_name }}
                                    @if($event->relay_count > 1)
                                        <flux:badge size="sm" class="ms-1">Staffel</flux:badge>
                                    @endif
                                </th>
                                <td class="py-2 pe-4 tabular-nums">{{ $event->session_number }}</td>
                                <td class="py-2 pe-4">
                                    <flux:input name="events[{{ $event->id }}]" size="sm" inputmode="decimal"
                                                :invalid="$errors->has('events.'.$event->id)"
                                                value="{{ old('events.'.$event->id, Money::toInput($event->fee_cents)) }}"
                                                aria-label="Gebühr Nr. {{ $event->event_number }} {{ $event->display_name }}"
                                                class="w-28"/>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>

            <div class="flex gap-3">
                <flux:button type="submit" variant="primary">Speichern</flux:button>
                <flux:button href="{{ route('meets.show', $meet) }}" variant="ghost">Abbrechen</flux:button>
            </div>
        </form>
    </div>
@endsection
