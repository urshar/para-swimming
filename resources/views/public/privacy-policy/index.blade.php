{{--
    public/privacy-policy/index — Datenschutzerklärung, Entwurf mit Platzhaltern
    (Informationspflichten nach Art. 13 DSGVO), Phase 9 Nachtrag.

    Nicht alles ist Platzhalter: die technischen Fakten (Cookie/localStorage) sind im Code
    verifiziert (routes/public.php, resources/js/theme.js) und hier bereits ausformuliert, ebenso
    die gesetzlich vorgegebenen, generischen Abschnitte (Betroffenenrechte, Beschwerderecht bei
    der Datenschutzbehörde — deren Adresse ist eine öffentliche, überprüfbare Tatsache, keine
    Vereinsangabe). Platzhalter nur dort, wo es tatsächlich eine Entscheidung/Angabe des Vereins
    braucht: Verantwortlicher, Rechtsgrundlage für die öffentliche Veröffentlichung von
    Athletennamen/Ergebnissen/Vereinszugehörigkeit, Hosting-Anbieter.
--}}
@extends('layouts.public')

@section('title', __('public.privacy_policy.title'))
@section('robots', 'noindex, nofollow')

@section('content')
    <h1 class="text-2xl font-semibold">{{ __('public.privacy_policy.heading') }}</h1>

    <div class="mt-6">
        <x-draft-notice/>
    </div>

    <div class="max-w-2xl space-y-8 text-sm text-gray-700 dark:text-gray-300">
        <section>
            <h2 class="mb-2 text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('public.privacy_policy.controller.heading') }}</h2>
            <p>
                <x-placeholder-field>{{ __('public.imprint.placeholder.club_name') }}</x-placeholder-field>,
                <x-placeholder-field>{{ __('public.imprint.placeholder.address') }}</x-placeholder-field>.
                {{ __('public.privacy_policy.controller.contact') }}
                <a href="mailto:schwimmen@obsv.at" class="text-blue-600 hover:text-blue-500 dark:text-blue-400">schwimmen@obsv.at</a>.
            </p>
        </section>

        <section>
            <h2 class="mb-2 text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('public.privacy_policy.data.heading') }}</h2>
            <p>{{ __('public.privacy_policy.data.competition_intro') }}</p>
            <p class="mt-2">
                {{ __('public.privacy_policy.data.legal_basis') }}
                <x-placeholder-field>{{ __('public.privacy_policy.placeholder.legal_basis') }}</x-placeholder-field>
            </p>
            <p class="mt-2">{{ __('public.privacy_policy.data.technical_intro') }}</p>
            <ul class="mt-2 list-disc space-y-1 ps-5">
                <li>{{ __('public.privacy_policy.data.cookie_locale') }}</li>
                <li>{{ __('public.privacy_policy.data.storage_theme') }}</li>
            </ul>
        </section>

        <section>
            <h2 class="mb-2 text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('public.privacy_policy.recipients.heading') }}</h2>
            <p>
                {{ __('public.privacy_policy.recipients.text') }}
                <x-placeholder-field>{{ __('public.privacy_policy.placeholder.hosting_provider') }}</x-placeholder-field>
            </p>
        </section>

        <section>
            <h2 class="mb-2 text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('public.privacy_policy.rights.heading') }}</h2>
            <p>{{ __('public.privacy_policy.rights.text') }}</p>
        </section>

        <section>
            <h2 class="mb-2 text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('public.privacy_policy.complaint.heading') }}</h2>
            <p>{{ __('public.privacy_policy.complaint.text') }}</p>
        </section>
    </div>
@endsection
