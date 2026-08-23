{{--
    public/imprint/index — Impressum, Entwurf mit Platzhaltern (Offenlegung nach § 5 ECG),
    Phase 9 Nachtrag.

    Kein echter Inhalt: Vereinsname, Anschrift, ZVR-Zahl und vertretungsbefugte Person(en) sind
    Pflichtangaben, die eine KI nicht erfinden darf (Rückmeldung: "Entwurf mit Platzhaltern, klar
    als Entwurf markiert"). x-draft-notice oben + x-placeholder-field je Lücke im Text — siehe
    docs/open-points.md für den Status.
--}}
@extends('layouts.public')

@section('title', __('public.imprint.title'))
@section('robots', 'noindex, nofollow')

@section('content')
    <h1 class="text-2xl font-semibold">{{ __('public.imprint.heading') }}</h1>

    <div class="mt-6">
        <x-draft-notice/>
    </div>

    <div class="max-w-2xl space-y-4 text-sm text-gray-700 dark:text-gray-300">
        <p>
            <span class="font-semibold">{{ __('public.imprint.operator') }}:</span>
            <x-placeholder-field>{{ __('public.imprint.placeholder.club_name') }}</x-placeholder-field>
        </p>
        <p>
            <span class="font-semibold">{{ __('public.imprint.address') }}:</span>
            <x-placeholder-field>{{ __('public.imprint.placeholder.address') }}</x-placeholder-field>
        </p>
        <p>
            <span class="font-semibold">{{ __('public.imprint.register_number') }}:</span>
            <x-placeholder-field>{{ __('public.imprint.placeholder.zvr') }}</x-placeholder-field>
        </p>
        <p>
            <span class="font-semibold">{{ __('public.imprint.representative') }}:</span>
            <x-placeholder-field>{{ __('public.imprint.placeholder.representative') }}</x-placeholder-field>
        </p>
        <p>
            <span class="font-semibold">{{ __('public.imprint.contact') }}:</span>
            <a href="mailto:schwimmen@obsv.at" class="text-blue-600 hover:text-blue-500 dark:text-blue-400">schwimmen@obsv.at</a>
        </p>
        <p>
            <span class="font-semibold">{{ __('public.imprint.purpose') }}:</span>
            <x-placeholder-field>{{ __('public.imprint.placeholder.purpose') }}</x-placeholder-field>
        </p>
    </div>
@endsection
