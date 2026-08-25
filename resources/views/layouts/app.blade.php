<!DOCTYPE html>
{{-- Dark/Light wird komplett über Flux' eigenes $flux.appearance-System gesteuert (Alpine-Magic aus dem
     Flux-JS-Bundle, das per @fluxScripts sowieso geladen wird — vendor/livewire/flux/dist/flux.min.js:
     Alpine.magic('flux', ...), persistiert unter localStorage['flux.appearance']). Vorher gab es hier
     zusätzlich ein eigenes Alpine-x-data auf <html> mit einem eigenen localStorage-Key ("theme") — zwei
     parallele Systeme, die sich gegenseitig überschrieben haben: Settings → Appearance nutzt intern
     bereits $flux.appearance (resources/views/pages/settings/⚡appearance.blade.php), wodurch ein Besuch
     dieser Seite den hier gesetzten Modus wieder zurückgesetzt hat. Kein eigenes x-data mehr nötig, Flux
     hängt die "dark"-Klasse selbst direkt an <html> (siehe @fluxAppearance unten). --}}
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- $title wird von Livewire-Full-Page-Komponenten mit #[Title(...)] gesetzt,
         @section('title') von den klassischen Controller-Views. --}}
    <title>{{ config('app.name', 'Para Swimming') }} – @yield('title', $title ?? 'Dashboard')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- @fluxAppearance statt des vorherigen @fluxStyles: @fluxStyles ist in der installierten Flux-Version
         gar keine registrierte Blade-Direktive mehr (nur @fluxScripts und @fluxAppearance, siehe
         vendor/livewire/flux/src/AssetManager.php::registerAssetDirective()) und wurde deshalb bisher
         wörtlich als Text "@fluxStyles" ausgegeben, sichtbar ganz oben im Body. @fluxAppearance ist das
         früh ausgeführte Inline-Script, das die dark-Klasse noch vor dem ersten Paint setzt (FOUC-frei). --}}
    @fluxAppearance
</head>
<body class="min-h-screen bg-zinc-50 dark:bg-zinc-950 font-sans antialiased">

{{-- Kopfzeile über der vollen Breite (Logo, Dark/Light-Umschalter, Benutzermenü) — Reihenfolge im DOM
     (header vor sidebar) ist wichtig: Flux legt das Grid-Layout darüber, welches Element vor welchem
     kommt (vendor/livewire/flux/dist/flux.css, "*:has(>[data-flux-main])"); header vor sidebar ergibt
     eine volle Kopfzeile über Sidebar UND Content statt einer nur neben der Sidebar. --}}
{{-- Kein container-Prop: das würde den Inhalt zentrieren/auf max-w-7xl begrenzen und dadurch nicht mit der
     Sidebar-Spalte fluchten. flux:header bringt selbst "px-6 lg:px-8" fix mit — mit "!"-Suffix (Tailwind-v4-
     important, echtes !important) auf px-0! übersteuert, weil eine von außen übergebene Klasse sonst gegen
     die Komponentenklasse in Tailwinds kompilierter Reihenfolge verliert (siehe den flux:input-Breitenbefund
     in docs/specs/admin-ui-rework.md). Die Innenabstände übernehmen die beiden Abschnitte unten selbst. --}}
<flux:header sticky class="px-0! bg-white dark:bg-zinc-900 border-b border-zinc-200 dark:border-zinc-800">

    {{-- Logo-Abschnitt exakt so breit wie die Sidebar (deren Breite kommt aus flux:sidebar's eigenem "w-64",
         siehe vendor/livewire/flux/stubs/resources/views/flux/sidebar/index.blade.php). Der Hell/Dunkel-
         Umschalter zieht direkt danach ein — dadurch fluchtet er zwangsläufig mit dem Beginn des
         Hauptinhalts, unabhängig von der tatsächlichen Sidebar-Breite. Nur ab lg: fix breit, weil die
         Sidebar auf Mobile keine eigene Spalte im Grid ist (off-canvas), sondern "fixed" positioniert wird. --}}
    <div class="w-auto lg:w-64 shrink-0 flex items-center gap-2 px-4 py-2">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" tooltip="Menü öffnen"/>

        <a href="{{ route('meets.index') }}" class="flex items-center gap-3 shrink-0">
            <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-blue-600 shrink-0">
                <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                     stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
            </div>
            <div class="leading-tight">
                <div class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Para Swimming</div>
                <div class="text-xs text-zinc-500 dark:text-zinc-400">NatDB</div>
            </div>
        </a>
    </div>

    {{-- Rest der Kopfzeile trägt seinen eigenen Innenabstand (der Logo-Abschnitt oben hat seinen bereits
         selbst), füllt die restliche Breite (flex-1), damit flux:spacer die Elemente weiter unten
         auseinanderschiebt. --}}
    <div class="flex items-center gap-2 flex-1 px-4 py-2">

        {{-- Dark/Light-Umschalter, gebunden an $flux.dark (Flux-eigene Alpine-Magic: berechneter Boolean aus
             $flux.appearance, löst "system" via matchMedia auf; Setter schreibt explizit "dark"/"light").
             Dieselbe Zustandsquelle wie die Settings → Appearance-Seite, kein eigenes x-data nötig.
             Direkt nach dem Logo-Abschnitt (der so breit wie die Sidebar ist) — dadurch auf Höhe des
             Hauptinhalts, nicht ganz rechts beim Benutzermenü. --}}
        <flux:button variant="ghost" size="sm" icon="moon" x-show="!$flux.dark" @click="$flux.dark = true"
                     tooltip="Dunkel-Modus"/>
        <flux:button variant="ghost" size="sm" icon="sun" x-show="$flux.dark" @click="$flux.dark = false"
                     tooltip="Hell-Modus"/>

        <flux:spacer/>

        {{-- Benutzermenü: Profil + Abmelden. Der Baustein existierte bereits als
             components/desktop-user-menu.blade.php, hing aber nur im ungenutzten Starter-Kit-Rest
             layouts/app/header.blade.php und war dadurch im echten Layout nie sichtbar. Hier direkt
             eingebaut (kompaktere flux:profile statt flux:sidebar.profile, die für die Sidebar-Breite statt
             für eine horizontale Kopfzeile gedacht ist). --}}
        <flux:dropdown position="bottom" align="end">
            <flux:profile :name="auth()->user()->name" :initials="auth()->user()->initials()"/>

            <flux:menu>
                <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                    <flux:avatar :name="auth()->user()->name" :initials="auth()->user()->initials()"/>
                    <div class="grid flex-1 text-start text-sm leading-tight">
                        <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                        <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                    </div>
                </div>
                <flux:menu.separator/>
                <flux:menu.item :href="route('profile.edit')" icon="cog">Einstellungen</flux:menu.item>
                <form method="POST" action="{{ route('logout') }}" class="w-full">
                    @csrf
                    <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle"
                                     class="w-full cursor-pointer">
                        Abmelden
                    </flux:menu.item>
                </form>
            </flux:menu>
        </flux:dropdown>

    </div>

</flux:header>

<flux:sidebar sticky stashable class="bg-white dark:bg-zinc-900 border-r border-zinc-200 dark:border-zinc-800">

    <flux:sidebar.toggle class="lg:hidden" icon="x-mark"/>

    <flux:navlist>

        <flux:navlist.group heading="Wettkämpfe" expandable
                             :expanded="request()->routeIs('meets.*') || request()->routeIs('entries.*') || request()->routeIs('results.*')">
            <flux:navlist.item icon="trophy" href="{{ route('meets.index') }}"
                               :current="request()->routeIs('meets.*')">
                Wettkämpfe
            </flux:navlist.item>
            <flux:navlist.item icon="list-bullet" href="{{ route('entries.index') }}"
                               :current="request()->routeIs('entries.*')">
                Meldungen
            </flux:navlist.item>
            <flux:navlist.item icon="chart-bar" href="{{ route('results.index') }}"
                               :current="request()->routeIs('results.*')">
                Ergebnisse
            </flux:navlist.item>
        </flux:navlist.group>

        <flux:navlist.group heading="Cup Wertung" expandable
                             :expanded="request()->routeIs('cups.overall-ranking.*') || request()->routeIs('cups.club-ranking.*')">
            <flux:navlist.item icon="trophy" href="{{ route('cups.overall-ranking.index') }}"
                               :current="request()->routeIs('cups.overall-ranking.*')">
                Gesamtwertung
            </flux:navlist.item>
            <flux:navlist.item icon="user-group" href="{{ route('cups.club-ranking.index') }}"
                               :current="request()->routeIs('cups.club-ranking.*')">
                Vereinswertung
            </flux:navlist.item>
        </flux:navlist.group>

        @if(auth()->user()?->is_admin)
            <flux:navlist.group heading="Statistik" expandable :expanded="request()->routeIs('statistics.*')">
                <flux:navlist.item icon="chart-bar" href="{{ route('statistics.index') }}"
                                   :current="request()->routeIs('statistics.*')">
                    Statistik
                </flux:navlist.item>
            </flux:navlist.group>
        @endif

        @auth
            @if(auth()->user()->club_id || auth()->user()->is_admin)
                <flux:navlist.group heading="Vereinsmeldungen" expandable
                                     :expanded="request()->routeIs('club-entries.*')">
                    <flux:navlist.item icon="pencil-square" href="{{ route('club-entries.pick-meet') }}"
                                       :current="request()->routeIs('club-entries.*') && !request()->routeIs('club-entries.relay.*')">
                        Einzelmeldungen
                    </flux:navlist.item>
                    <flux:navlist.item icon="user-group" href="{{ route('club-entries.relay.pick-meet') }}"
                                       :current="request()->routeIs('club-entries.relay.*')">
                        Staffelmeldungen
                    </flux:navlist.item>
                </flux:navlist.group>
            @endif
        @endauth

        <flux:navlist.group heading="Stammdaten" expandable
                             :expanded="request()->routeIs('athletes.*') || request()->routeIs('clubs.*') || request()->routeIs('nations.*') || request()->routeIs('classifiers.*')">
            <flux:navlist.item icon="user-group" href="{{ route('athletes.index') }}"
                               :current="request()->routeIs('athletes.*')">
                Athleten
            </flux:navlist.item>
            <flux:navlist.item icon="building-office" href="{{ route('clubs.index') }}"
                               :current="request()->routeIs('clubs.*')">
                Vereine
            </flux:navlist.item>
            <flux:navlist.item icon="flag" href="{{ route('nations.index') }}"
                               :current="request()->routeIs('nations.*')">
                Nationen
            </flux:navlist.item>
            <flux:navlist.item icon="identification" href="{{ route('classifiers.index') }}"
                               :current="request()->routeIs('classifiers.*')">
                Klassifizierer
            </flux:navlist.item>
        </flux:navlist.group>

        <flux:navlist.group heading="Rekorde" expandable :expanded="request()->routeIs('records.*')">
            <flux:navlist.item icon="star" href="{{ route('records.index') }}"
                               :current="request()->routeIs('records.index') || request()->routeIs('records.show') || request()->routeIs('records.create') || request()->routeIs('records.edit')">
                Rekorde
            </flux:navlist.item>
            <flux:navlist.item icon="arrow-up-tray" href="{{ route('records.import') }}"
                               :current="request()->routeIs('records.import*')">
                Rekorde importieren
            </flux:navlist.item>
            <flux:navlist.item icon="arrow-down-tray" href="{{ route('records.export') }}"
                               :current="request()->routeIs('records.export*')">
                Rekorde exportieren
            </flux:navlist.item>
        </flux:navlist.group>

        <flux:navlist.group heading="Richtzeiten ÖSTM & ÖM" expandable
                             :expanded="request()->routeIs('qualifying-time-lists.*') || request()->routeIs('qualifying-excluded-disciplines.*')">
            <flux:navlist.item icon="flag" href="{{ route('qualifying-time-lists.index') }}"
                               :current="request()->routeIs('qualifying-time-lists.*')">
                Richtzeitenlisten
            </flux:navlist.item>
            @if(auth()->user()?->is_admin)
                <flux:navlist.item icon="no-symbol" href="{{ route('qualifying-excluded-disciplines.index') }}"
                                   :current="request()->routeIs('qualifying-excluded-disciplines.*')">
                    Ausgeschlossene Bewerbe
                </flux:navlist.item>
            @endif
        </flux:navlist.group>

        <flux:navlist.group heading="Auswertungen" expandable
                             :expanded="request()->routeIs('wps.rankings') || request()->routeIs('wps.talent-report') || request()->routeIs('wps.clubs')">
            <flux:navlist.item icon="chart-bar" href="{{ route('wps.rankings') }}"
                               :current="request()->routeIs('wps.rankings')">
                WPS-Ranglisten
            </flux:navlist.item>
            <flux:navlist.item icon="academic-cap" href="{{ route('wps.talent-report') }}"
                               :current="request()->routeIs('wps.talent-report')">
                Förderauswertung
            </flux:navlist.item>
            <flux:navlist.item icon="building-office" href="{{ route('wps.clubs') }}"
                               :current="request()->routeIs('wps.clubs')">
                Vereinsauswertung
            </flux:navlist.item>
        </flux:navlist.group>

        <flux:navlist.group heading="Meisterschaften" expandable :expanded="request()->routeIs('championships.*')">
            <flux:navlist.item icon="trophy" href="{{ route('championships.index') }}"
                               :current="request()->routeIs('championships.*')">
                Qualifikationsnormen
            </flux:navlist.item>
        </flux:navlist.group>

        @if(auth()->user()?->is_admin)
            <flux:navlist.group heading="WPS Punkte" expandable
                                 :expanded="request()->routeIs('wps.versions.*') || request()->routeIs('wps.import*') || request()->routeIs('wps.factors.*')">
                <flux:navlist.item icon="calculator" href="{{ route('wps.versions.index') }}"
                                   :current="request()->routeIs('wps.versions.*')">
                    Point Scores
                </flux:navlist.item>
                <flux:navlist.item icon="arrow-up-tray" href="{{ route('wps.import') }}"
                                   :current="request()->routeIs('wps.import*')">
                    Importieren
                </flux:navlist.item>
                <flux:navlist.item icon="arrows-right-left" href="{{ route('wps.factors.index') }}"
                                   :current="request()->routeIs('wps.factors.*')">
                    Kurzbahn-Umrechnung
                </flux:navlist.item>
            </flux:navlist.group>
        @endif

        <flux:navlist.group heading="LENEX" expandable
                             :expanded="request()->routeIs('lenex.import*') || request()->routeIs('lenex.export*')">
            <flux:navlist.item icon="arrow-up-tray" href="{{ route('lenex.import') }}"
                               :current="request()->routeIs('lenex.import*')">
                Import
            </flux:navlist.item>
            <flux:navlist.item icon="arrow-down-tray" href="{{ route('lenex.export') }}"
                               :current="request()->routeIs('lenex.export*')">
                Export
            </flux:navlist.item>
        </flux:navlist.group>

        @if(auth()->user()?->is_admin)
            <flux:navlist.group heading="Basiswerte" expandable
                                 :expanded="request()->routeIs('base-times.versions.*') || request()->routeIs('base-times.categories.*') || request()->routeIs('base-times.import*')">
                <flux:navlist.item icon="calculator" href="{{ route('base-times.versions.index') }}"
                                   :current="request()->routeIs('base-times.versions.*') || request()->routeIs('base-times.categories.*')">
                    Basiswerte
                </flux:navlist.item>
                <flux:navlist.item icon="arrow-up-tray" href="{{ route('base-times.import') }}"
                                   :current="request()->routeIs('base-times.import*')">
                    Basiswerte importieren
                </flux:navlist.item>
            </flux:navlist.group>

            <flux:navlist.group heading="ÖBSV Cup Wertung" expandable
                                 :expanded="request()->routeIs('cups.*') || request()->routeIs('kader-types.*') || request()->routeIs('age-groups.*') || request()->routeIs('sport-class-groups.*')">
                <flux:navlist.item icon="trophy" href="{{ route('cups.index') }}"
                                   :current="request()->routeIs('cups.*')">
                    Cup-Konfiguration
                </flux:navlist.item>
                <flux:navlist.item icon="star" href="{{ route('kader-types.index') }}"
                                   :current="request()->routeIs('kader-types.*')">
                    Kaderarten
                </flux:navlist.item>
                <flux:navlist.item icon="users" href="{{ route('age-groups.index') }}"
                                   :current="request()->routeIs('age-groups.*')">
                    Altersgruppen
                </flux:navlist.item>
                <flux:navlist.item icon="squares-2x2" href="{{ route('sport-class-groups.index') }}"
                                   :current="request()->routeIs('sport-class-groups.*')">
                    Sportklassengruppen
                </flux:navlist.item>
            </flux:navlist.group>

            {{-- Regelmente & Formulare ohne Veranstaltungsbezug (Admin\DocumentController,
                 documentable = null, Spec public-frontend §6/Phase 8) — die Route existierte
                 bereits seit Phase 3, hatte aber nie einen Menüeintrag (Rückmeldung: "im Admin
                 Bereich gibt es dazu nichts"). Veranstaltungsdokumente hängen dagegen am
                 jeweiligen Meet-Formular (meets/show), brauchen keinen eigenen Menüpunkt. --}}
            <flux:navlist.group heading="Regelmente & Formulare" expandable
                                 :expanded="request()->routeIs('admin.documents.*')">
                <flux:navlist.item icon="document-text" href="{{ route('admin.documents.index') }}"
                                   :current="request()->routeIs('admin.documents.*')">
                    Dokumente
                </flux:navlist.item>
            </flux:navlist.group>
        @endif

    </flux:navlist>

</flux:sidebar>

<flux:main class="p-6">

    {{-- Zwei Einbindungswege in dasselbe Layout:

         1. Klassische Controller-Views nutzen @extends('layouts.app') + @section('content')
            und landen in @yield('content').

         2. Livewire-Full-Page-Komponenten (Route::livewire, resources/views/pages/…)
            rendern ihre Ausgabe in $slot. Ohne die folgende Zeile wird sie stillschweigend
            verworfen: die Seite liefert HTTP 200 mit vollständigem Layout, aber leerem
            Inhaltsbereich. Genau das betraf die Einstellungsseiten.

         $slot ?? '' ist notwendig, weil die Variable bei Weg 1 nicht existiert. --}}
    {{ $slot ?? '' }}

    @yield('content')

</flux:main>

@fluxScripts
</body>
</html>
