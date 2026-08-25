{{--
    public/accessibility-statement/index — Erklärung zur Barrierefreiheit (Spec public-frontend
    §6, Phase 9; docs/accessibility.md §Erklärung zur Barrierefreiheit).

    Zeigt bewusst nur die Kontaktmöglichkeit für Rückmeldungen (Rückmeldung: "Kontakt ist
    schwimmen@obsv.at, die anderen 2 Punkte lassen wir mal weg"). Konformitätsstand und
    Schlichtungsverfahren fehlen absichtlich — siehe docs/open-points.md, damit das nicht
    stillschweigend vergessen wird. Kein Platzhaltertext für diese beiden Abschnitte: eine
    unbelegte Konformitätsaussage wäre falsch, ein leerer Abschnitt sähe nach vergessenem Inhalt
    aus statt nach bewusster Auslassung — die Seite bleibt bis zur Entscheidung entsprechend kurz.
--}}
@extends('layouts.public')

@section('title', __('public.accessibility_statement.title'))
@section('description', __('public.accessibility_statement.intro'))

@section('content')
    <h1 class="text-2xl font-semibold">{{ __('public.accessibility_statement.heading') }}</h1>
    <p class="mt-2 max-w-2xl text-sm text-gray-600 dark:text-gray-400">{{ __('public.accessibility_statement.intro') }}</p>

    <section class="mt-8 max-w-2xl">
        <h2 class="mb-2 text-lg font-semibold">{{ __('public.accessibility_statement.contact_heading') }}</h2>
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ __('public.accessibility_statement.contact_text') }}
            <a href="mailto:{{ __('public.accessibility_statement.contact_email') }}"
               class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400">
                {{ __('public.accessibility_statement.contact_email') }}
            </a>
        </p>
    </section>
@endsection
