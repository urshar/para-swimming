{{--
    layouts/public — Seitenrahmen des öffentlichen Bereichs (Spec public-frontend §3).

    Kopf- und Fußzeile sind aus Tailkit-Bausteinen abgeleitet (§3.1.2), angepasst und geprüft
    gegen accessibility.md — insbesondere die mobile Navigation, die im Rohzustand als Dialog
    ausgezeichnet war, aber weder Fokusfalle noch Fokusrückgabe mitbrachte (siehe
    resources/js/mobile-nav.js).

    Kopfzeile: docs/snippets/header-simple.html (m-s-main-headers-01 "Simple"). Die schwerere
    header-with-submenu.html (m-s-main-headers-04, Mega-Menü) liegt weiterhin ungenutzt daneben —
    das ist ein mehrspaltiges Flyout für viele Gruppen, hier reicht ein einzelnes schlankes
    Untermenü. Mit Phase 6 (drei Punktesystem-Seiten) war die Kopfzeile zu lang geworden
    (Rückmeldung); "Punkte" fasst Punktetabelle + beide Rechner in einem eigenen, per Hand
    gebauten Untermenü zusammen (resources/js/nav-dropdown.js) — die anderen drei Ziele
    (Start/Veranstaltungen/Rekorde) bleiben einzeln, da sie zu keiner gemeinsamen Gruppe gehören.

    Fußzeile: docs/snippets/footer.html (m-s-footers-01 "Simple"), auf die Copyright-Zeile
    reduziert — die im Original enthaltenen Nav-Links (About/Terms/Privacy) haben keine
    Entsprechung im Verband und wurden nicht durch erfundene Ziele ersetzt.
--}}
@php
    use App\Http\Middleware\SetLocale;
    use Illuminate\Support\Facades\Route;
@endphp
    <!DOCTYPE html>
<html lang="{{ app()->getLocale() === 'de' ? 'de-AT' : 'en' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- Vor dem ersten Rendern, damit die helle Darstellung nicht kurz aufblitzt (§3.4). Muss
         mit resources/js/theme.js synchron bleiben.

         Fallback auf "flux.appearance": Login/Registrierung & Admin-Bereich laufen über Flux'
         eigenes Hell/Dunkel-System (siehe @fluxAppearance dort) mit eigenem localStorage-Key.
         Ohne diesen Fallback wirkte ein hier gewählter Modus beim Wechsel auf die Login-Seite
         verloren (sie kennt nur "flux.appearance", nicht "theme") und sprang auf die
         Systemeinstellung zurück. theme.js schreibt beim Setzen daher in beide Keys. --}}
    <script>
        (function () {
            const mode = localStorage.getItem('theme') || localStorage.getItem('flux.appearance') || 'system';
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            const isDark = mode === 'dark' || (mode === 'system' && prefersDark);
            document.documentElement.classList.toggle('dark', isDark);
        })();
    </script>

    <title>{{ config('app.name', 'Para Swimming') }} – @yield('title')</title>

    {{-- Fallback über yieldContent() statt @hasSection/@yield-Paar (Phase 9, §Meta-Tags): jede
         Seite bekommt eine Beschreibung, auch ohne eigenes @section('description', ...) — die
         meisten Seiten setzen trotzdem eine eigene (oft dieselbe Zeichenkette wie ihr
         Intro-Absatz), aber ein generischer Fallback ist besser als eine leere/fehlende
         Meta-Description auf Seiten, die (noch) keine eigene gesetzt haben. --}}
    <meta name="description" content="{{ $__env->yieldContent('description') ?: __('public.meta.default_description') }}">

    @hasSection('robots')
        <meta name="robots" content="@yield('robots')">
    @endif

    @php
        $publicRouteName = Route::currentRouteName();
        $publicRouteParams = request()->route()?->parameters() ?? [];
    @endphp
    @if ($publicRouteName)
        @foreach (SetLocale::SUPPORTED as $altLocale)
            <link rel="alternate" hreflang="{{ $altLocale }}"
                  href="{{ route($publicRouteName, [...$publicRouteParams, 'locale' => $altLocale]) }}">
        @endforeach
    @endif

    @vite(['resources/css/public.css', 'resources/js/public.js'])
</head>
<body class="min-h-screen bg-gray-50 text-gray-900 antialiased dark:bg-gray-950 dark:text-gray-100">

<a href="#content"
   class="sr-only focus:not-sr-only focus:fixed focus:inset-s-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:text-gray-900 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-600 dark:focus:bg-gray-900 dark:focus:text-gray-100">
    {{ __('public.skip_to_content') }}
</a>

{{-- Tailkit: m-s-main-headers-01 "Simple" — auf die echten Nav-Ziele reduziert, um den
     Umschalter für die Darstellung erweitert (kein Tailkit-Baustein dafür, §3.1.3 Punkt 6). --}}
{{-- hide()/show()/trap() kommen aus resources/js/mobile-nav.js (Alpine.data), für die
     Editor-Analyse über die x-data-Grenze hinweg nicht auflösbar. --}}
<!--suppress JSValidateTypes -->
<header class="relative border-b border-gray-300 bg-white dark:border-gray-800 dark:bg-gray-900" x-data="mobileNav()">
    <div class="container mx-auto flex items-center justify-between px-4 py-4 lg:px-8 xl:max-w-7xl">
        <a href="{{ route('public.home', ['locale' => app()->getLocale()]) }}"
           class="text-lg font-bold tracking-wide text-gray-900 hover:text-gray-600 dark:text-gray-100 dark:hover:text-gray-300">
            {{ config('app.name', 'Para Swimming') }}
        </a>

        <div class="flex items-center gap-4">
            <nav aria-label="{{ __('public.nav.label') }}" class="hidden items-center gap-6 lg:flex">
                <a href="{{ route('public.home', ['locale' => app()->getLocale()]) }}"
                   @if (request()->routeIs('public.home')) aria-current="page" @endif
                   class="text-sm font-semibold text-gray-900 hover:text-blue-600 dark:text-gray-100 dark:hover:text-blue-400 aria-[current]:text-blue-600 dark:aria-[current]:text-blue-400">
                    {{ __('public.nav.home') }}
                </a>
                <a href="{{ route('public.meets.index', ['locale' => app()->getLocale()]) }}"
                   @if (request()->routeIs('public.meets.*')) aria-current="page" @endif
                   class="text-sm font-semibold text-gray-900 hover:text-blue-600 dark:text-gray-100 dark:hover:text-blue-400 aria-[current]:text-blue-600 dark:aria-[current]:text-blue-400">
                    {{ __('public.nav.meets') }}
                </a>
                <a href="{{ route('public.records.index', ['locale' => app()->getLocale()]) }}"
                   @if (request()->routeIs('public.records.*')) aria-current="page" @endif
                   class="text-sm font-semibold text-gray-900 hover:text-blue-600 dark:text-gray-100 dark:hover:text-blue-400 aria-[current]:text-blue-600 dark:aria-[current]:text-blue-400">
                    {{ __('public.nav.records') }}
                </a>

                {{-- Untermenü "Punkte": Punktetabelle + zwei Rechner (Rückmeldung: die Kopfzeile
                     wurde mit jedem Punktesystem-Ziel länger). WAI-ARIA-Disclosure statt
                     role="menu" — Navigationsziele, keine Befehle. Ohne JS ist der Auslöser ein
                     normaler Link auf die Punktetabelle und das Panel steht offen im Fließtext
                     (resources/js/nav-dropdown.js). --}}
                <div class="relative" x-data="navDropdown()" x-on:keydown.escape.window="close()">
                    <a href="{{ route('public.base-times.index', ['locale' => app()->getLocale()]) }}"
                       x-ref="trigger"
                       x-on:click.prevent="toggle()"
                       x-on:keydown="onTriggerKeydown($event)"
                       x-bind:aria-expanded="open.toString()"
                       aria-haspopup="true"
                       @if (request()->routeIs('public.base-times.*', 'public.point-calculator.*', 'public.wps-point-calculator.*')) aria-current="page"
                       @endif
                       class="inline-flex items-center gap-1 text-sm font-semibold text-gray-900 hover:text-blue-600 dark:text-gray-100 dark:hover:text-blue-400 aria-[current]:text-blue-600 dark:aria-[current]:text-blue-400">
                        {{ __('public.nav.points') }}
                        <svg x-bind:class="open ? 'rotate-180' : ''" class="h-3 w-3 transition-transform"
                             viewBox="0 0 12 12" fill="none" aria-hidden="true">
                            <path d="M2.5 4.5L6 8l3.5-3.5" stroke="currentColor" stroke-width="1.5"
                                  stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </a>
                    <div x-ref="panel"
                         x-show="open"
                         x-on:click.outside="close()"
                         x-on:keydown="onPanelKeydown($event)"
                         aria-label="{{ __('public.nav.points') }}"
                         class="absolute inset-s-0 z-10 mt-2 w-56 rounded-lg border border-gray-300 bg-white py-2 shadow-lg dark:border-gray-700 dark:bg-gray-800">
                        <a href="{{ route('public.base-times.index', ['locale' => app()->getLocale()]) }}"
                           @if (request()->routeIs('public.base-times.*')) aria-current="page" @endif
                           class="block px-4 py-2 text-sm font-medium text-gray-900 hover:bg-gray-50 hover:text-blue-600 aria-[current]:text-blue-600 dark:text-gray-100 dark:hover:bg-gray-700/50 dark:hover:text-blue-400 dark:aria-[current]:text-blue-400">
                            {{ __('public.nav.base_times') }}
                        </a>
                        <a href="{{ route('public.point-calculator.index', ['locale' => app()->getLocale()]) }}"
                           @if (request()->routeIs('public.point-calculator.*')) aria-current="page" @endif
                           class="block px-4 py-2 text-sm font-medium text-gray-900 hover:bg-gray-50 hover:text-blue-600 aria-[current]:text-blue-600 dark:text-gray-100 dark:hover:bg-gray-700/50 dark:hover:text-blue-400 dark:aria-[current]:text-blue-400">
                            {{ __('public.nav.point_calculator') }}
                        </a>
                        <a href="{{ route('public.wps-point-calculator.index', ['locale' => app()->getLocale()]) }}"
                           @if (request()->routeIs('public.wps-point-calculator.*')) aria-current="page" @endif
                           class="block px-4 py-2 text-sm font-medium text-gray-900 hover:bg-gray-50 hover:text-blue-600 aria-[current]:text-blue-600 dark:text-gray-100 dark:hover:bg-gray-700/50 dark:hover:text-blue-400 dark:aria-[current]:text-blue-400">
                            {{ __('public.nav.wps_point_calculator') }}
                        </a>
                    </div>
                </div>

                {{-- Untermenü "Ranglisten": Cup-Wertung, Startberechtigung, Jahresbestleistungen
                     (Phase 7) — dieselbe Untermenü-Komponente wie "Punkte" oben, aus demselben
                     Grund (Kopfzeile wächst mit jeder neuen Seite). --}}
                <div class="relative" x-data="navDropdown()" x-on:keydown.escape.window="close()">
                    <a href="{{ route('public.cup-ranking.index', ['locale' => app()->getLocale()]) }}"
                       x-ref="trigger"
                       x-on:click.prevent="toggle()"
                       x-on:keydown="onTriggerKeydown($event)"
                       x-bind:aria-expanded="open.toString()"
                       aria-haspopup="true"
                       @if (request()->routeIs('public.cup-ranking.*', 'public.qualifying-times.*', 'public.annual-best.*')) aria-current="page"
                       @endif
                       class="inline-flex items-center gap-1 text-sm font-semibold text-gray-900 hover:text-blue-600 dark:text-gray-100 dark:hover:text-blue-400 aria-[current]:text-blue-600 dark:aria-[current]:text-blue-400">
                        {{ __('public.nav.rankings') }}
                        <svg x-bind:class="open ? 'rotate-180' : ''" class="h-3 w-3 transition-transform"
                             viewBox="0 0 12 12" fill="none" aria-hidden="true">
                            <path d="M2.5 4.5L6 8l3.5-3.5" stroke="currentColor" stroke-width="1.5"
                                  stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </a>
                    <div x-ref="panel"
                         x-show="open"
                         x-on:click.outside="close()"
                         x-on:keydown="onPanelKeydown($event)"
                         aria-label="{{ __('public.nav.rankings') }}"
                         class="absolute inset-s-0 z-10 mt-2 w-56 rounded-lg border border-gray-300 bg-white py-2 shadow-lg dark:border-gray-700 dark:bg-gray-800">
                        <a href="{{ route('public.cup-ranking.index', ['locale' => app()->getLocale()]) }}"
                           @if (request()->routeIs('public.cup-ranking.*')) aria-current="page" @endif
                           class="block px-4 py-2 text-sm font-medium text-gray-900 hover:bg-gray-50 hover:text-blue-600 aria-[current]:text-blue-600 dark:text-gray-100 dark:hover:bg-gray-700/50 dark:hover:text-blue-400 dark:aria-[current]:text-blue-400">
                            {{ __('public.nav.cup') }}
                        </a>
                        <a href="{{ route('public.qualifying-times.index', ['locale' => app()->getLocale()]) }}"
                           @if (request()->routeIs('public.qualifying-times.*')) aria-current="page" @endif
                           class="block px-4 py-2 text-sm font-medium text-gray-900 hover:bg-gray-50 hover:text-blue-600 aria-[current]:text-blue-600 dark:text-gray-100 dark:hover:bg-gray-700/50 dark:hover:text-blue-400 dark:aria-[current]:text-blue-400">
                            {{ __('public.nav.qualifying_times') }}
                        </a>
                        <a href="{{ route('public.annual-best.index', ['locale' => app()->getLocale()]) }}"
                           @if (request()->routeIs('public.annual-best.*')) aria-current="page" @endif
                           class="block px-4 py-2 text-sm font-medium text-gray-900 hover:bg-gray-50 hover:text-blue-600 aria-[current]:text-blue-600 dark:text-gray-100 dark:hover:bg-gray-700/50 dark:hover:text-blue-400 dark:aria-[current]:text-blue-400">
                            {{ __('public.nav.annual_best') }}
                        </a>
                    </div>
                </div>

                <a href="{{ route('public.regulations.index', ['locale' => app()->getLocale()]) }}"
                   @if (request()->routeIs('public.regulations.*')) aria-current="page" @endif
                   class="text-sm font-semibold text-gray-900 hover:text-blue-600 dark:text-gray-100 dark:hover:text-blue-400 aria-[current]:text-blue-600 dark:aria-[current]:text-blue-400">
                    {{ __('public.nav.regulations') }}
                </a>
            </nav>

            {{-- Hell/Dunkel: zweigeteilter Icon-Umschalter wie im Admin-Bereich (Mond/Sonne,
                 dort über Flux' $flux.dark-Magic) — hier bewusst weiter mit der eigenen
                 Alpine-Komponente theme() umgesetzt statt mit Flux, siehe CLAUDE.md
                 ("öffentlicher Bereich nutzt Tailkit, nicht Flux"). "System" bleibt intern der
                 Startzustand vor der ersten bewussten Wahl (wie bei Flux), ist über diesen
                 Umschalter aber wie im Backend nicht mehr eigens anwählbar. --}}
            <div x-data="theme()">
                <button type="button" x-show="!isDark()" x-on:click="set('dark')"
                        aria-label="{{ __('public.theme.dark') }}" title="{{ __('public.theme.dark') }}"
                        class="rounded-lg p-2 text-gray-700 hover:bg-gray-100 hover:text-blue-600 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-600 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-blue-400">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" />
                    </svg>
                </button>
                <button type="button" x-show="isDark()" x-on:click="set('light')"
                        aria-label="{{ __('public.theme.light') }}" title="{{ __('public.theme.light') }}"
                        class="rounded-lg p-2 text-gray-700 hover:bg-gray-100 hover:text-blue-600 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-600 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-blue-400">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
                    </svg>
                </button>
            </div>

            <a href="{{ route('login') }}"
               class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-800 hover:border-gray-300 hover:text-gray-900 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-600 dark:border-gray-700 dark:bg-transparent dark:text-gray-300 dark:hover:border-gray-600 dark:hover:text-gray-200">
                {{ __('public.nav.login') }}
            </a>

            <div class="lg:hidden">
                <button x-ref="toggle" x-on:click="show()" type="button"
                        x-bind:aria-expanded="open" aria-controls="tkMobileNav"
                        class="inline-flex items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-800 hover:border-gray-300 hover:text-gray-900 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-600 dark:border-gray-700 dark:bg-transparent dark:text-gray-300 dark:hover:border-gray-600 dark:hover:text-gray-200">
                    {{ __('public.nav.open') }}
                </button>
            </div>
        </div>
    </div>

    {{-- Mobile-Navigation: Fokusfalle, Fokusrückgabe und Escape via resources/js/mobile-nav.js --}}
    <nav
        x-ref="panel"
        x-cloak
        x-show="open"
        x-on:keydown.escape.window="hide()"
        x-on:keydown.tab="trap($event)"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-50 translate-x-full"
        x-transition:enter-end="opacity-100 translate-x-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 translate-x-0"
        x-transition:leave-end="opacity-50 translate-x-full"
        id="tkMobileNav"
        aria-label="{{ __('public.nav.label') }}"
        class="fixed top-0 right-0 bottom-0 z-50 w-72 overflow-auto bg-white/95 shadow-lg lg:hidden dark:bg-gray-800/95"
        tabindex="-1"
        x-bind:aria-modal="open ? 'true' : null"
        x-bind:role="open ? 'dialog' : null"
    >
        <div class="flex items-center justify-between p-6">
                <span class="text-lg font-bold tracking-wide text-gray-900 dark:text-gray-100">
                    {{ config('app.name', 'Para Swimming') }}
                </span>
            <button x-on:click="hide()" type="button"
                    class="inline-flex items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-800 hover:border-gray-300 hover:text-gray-900 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:border-gray-600 dark:hover:text-gray-200">
                {{ __('public.nav.close') }}
            </button>
        </div>
        <div class="h-px bg-gray-200/75 dark:bg-gray-700/75"></div>
        <nav aria-label="{{ __('public.nav.label') }}" class="flex flex-col gap-2 px-6 py-5">
            <a href="{{ route('public.home', ['locale' => app()->getLocale()]) }}"
               @if (request()->routeIs('public.home')) aria-current="page" @endif
               class="py-1 text-sm font-semibold text-gray-900 hover:text-blue-600 dark:text-gray-100 dark:hover:text-blue-400">
                {{ __('public.nav.home') }}
            </a>
            <a href="{{ route('public.meets.index', ['locale' => app()->getLocale()]) }}"
               @if (request()->routeIs('public.meets.*')) aria-current="page" @endif
               class="py-1 text-sm font-semibold text-gray-900 hover:text-blue-600 dark:text-gray-100 dark:hover:text-blue-400">
                {{ __('public.nav.meets') }}
            </a>
            <a href="{{ route('public.records.index', ['locale' => app()->getLocale()]) }}"
               @if (request()->routeIs('public.records.*')) aria-current="page" @endif
               class="py-1 text-sm font-semibold text-gray-900 hover:text-blue-600 dark:text-gray-100 dark:hover:text-blue-400">
                {{ __('public.nav.records') }}
            </a>
            <a href="{{ route('public.base-times.index', ['locale' => app()->getLocale()]) }}"
               @if (request()->routeIs('public.base-times.*')) aria-current="page" @endif
               class="py-1 text-sm font-semibold text-gray-900 hover:text-blue-600 dark:text-gray-100 dark:hover:text-blue-400">
                {{ __('public.nav.base_times') }}
            </a>
            <a href="{{ route('public.point-calculator.index', ['locale' => app()->getLocale()]) }}"
               @if (request()->routeIs('public.point-calculator.*')) aria-current="page" @endif
               class="py-1 text-sm font-semibold text-gray-900 hover:text-blue-600 dark:text-gray-100 dark:hover:text-blue-400">
                {{ __('public.nav.point_calculator') }}
            </a>
            <a href="{{ route('public.wps-point-calculator.index', ['locale' => app()->getLocale()]) }}"
               @if (request()->routeIs('public.wps-point-calculator.*')) aria-current="page" @endif
               class="py-1 text-sm font-semibold text-gray-900 hover:text-blue-600 dark:text-gray-100 dark:hover:text-blue-400">
                {{ __('public.nav.wps_point_calculator') }}
            </a>
            <a href="{{ route('public.cup-ranking.index', ['locale' => app()->getLocale()]) }}"
               @if (request()->routeIs('public.cup-ranking.*')) aria-current="page" @endif
               class="py-1 text-sm font-semibold text-gray-900 hover:text-blue-600 dark:text-gray-100 dark:hover:text-blue-400">
                {{ __('public.nav.cup') }}
            </a>
            <a href="{{ route('public.qualifying-times.index', ['locale' => app()->getLocale()]) }}"
               @if (request()->routeIs('public.qualifying-times.*')) aria-current="page" @endif
               class="py-1 text-sm font-semibold text-gray-900 hover:text-blue-600 dark:text-gray-100 dark:hover:text-blue-400">
                {{ __('public.nav.qualifying_times') }}
            </a>
            <a href="{{ route('public.annual-best.index', ['locale' => app()->getLocale()]) }}"
               @if (request()->routeIs('public.annual-best.*')) aria-current="page" @endif
               class="py-1 text-sm font-semibold text-gray-900 hover:text-blue-600 dark:text-gray-100 dark:hover:text-blue-400">
                {{ __('public.nav.annual_best') }}
            </a>
            <a href="{{ route('public.regulations.index', ['locale' => app()->getLocale()]) }}"
               @if (request()->routeIs('public.regulations.*')) aria-current="page" @endif
               class="py-1 text-sm font-semibold text-gray-900 hover:text-blue-600 dark:text-gray-100 dark:hover:text-blue-400">
                {{ __('public.nav.regulations') }}
            </a>
        </nav>
    </nav>

    <div
        x-cloak
        x-show="open"
        x-on:click="hide()"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-40 bg-gray-900/20 backdrop-blur-xs lg:hidden dark:bg-gray-900/80"
    ></div>
</header>

<main id="content" class="container mx-auto px-4 py-8 lg:px-8 xl:max-w-7xl">
    @yield('content')
</main>

{{-- Tailkit: m-s-footers-01 "Simple" — auf die Copyright-Zeile reduziert, um die echten
     rechtlichen/Barrierefreiheits-Links ergänzt. Gehört in die Fußzeile statt ins Hauptmenü —
     wie auf den meisten Websites üblich ("Impressum"/"Datenschutz"/"Barrierefreiheit"), keine
     inhaltliche Nav-Sektion. Copyright links, Links rechtsbündig (Rückmeldung) — bei schmalem
     Viewport bricht die Zeile um (flex-wrap), statt sich zu überlappen. --}}
<footer class="border-t border-gray-300 bg-white dark:border-gray-800 dark:bg-gray-900">
    <div
        class="container mx-auto flex flex-wrap items-center justify-between gap-4 px-4 py-12 text-sm text-gray-500 lg:px-8 dark:text-gray-400/80 xl:max-w-7xl">
        <span><span class="font-medium">{{ config('app.name', 'Para Swimming') }}</span> &copy; {{ now()->year }}</span>
        <nav aria-label="{{ __('public.nav.legal_label') }}" class="flex flex-wrap gap-x-6 gap-y-2">
            <a href="{{ route('public.imprint.index', ['locale' => app()->getLocale()]) }}"
               class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400">
                {{ __('public.nav.imprint') }}
            </a>
            <a href="{{ route('public.privacy-policy.index', ['locale' => app()->getLocale()]) }}"
               class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400">
                {{ __('public.nav.privacy_policy') }}
            </a>
            <a href="{{ route('public.accessibility-statement.index', ['locale' => app()->getLocale()]) }}"
               class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400">
                {{ __('public.nav.accessibility_statement') }}
            </a>
        </nav>
    </div>
</footer>

</body>
</html>
