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
         mit resources/js/theme.js synchron bleiben. --}}
    <script>
        (function () {
            const mode = localStorage.getItem('theme') || 'system';
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            const isDark = mode === 'dark' || (mode === 'system' && prefersDark);
            document.documentElement.classList.toggle('dark', isDark);
        })();
    </script>

    <title>{{ config('app.name', 'Para Swimming') }} – @yield('title')</title>

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
<body class="min-h-screen bg-white text-gray-900 antialiased dark:bg-gray-950 dark:text-gray-100">

<a href="#content"
   class="sr-only focus:not-sr-only focus:fixed focus:inset-s-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:text-gray-900 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-600 dark:focus:bg-gray-900 dark:focus:text-gray-100">
    {{ __('public.skip_to_content') }}
</a>

{{-- Tailkit: m-s-main-headers-01 "Simple" — auf die echten Nav-Ziele reduziert, um den
     Umschalter für die Darstellung erweitert (kein Tailkit-Baustein dafür, §3.1.3 Punkt 6). --}}
{{-- hide()/show()/trap() kommen aus resources/js/mobile-nav.js (Alpine.data), für die
     Editor-Analyse über die x-data-Grenze hinweg nicht auflösbar. --}}
<!--suppress JSValidateTypes -->
<header class="relative border-b border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900" x-data="mobileNav()">
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
                         class="absolute inset-s-0 z-10 mt-2 w-56 rounded-lg border border-gray-200 bg-white py-2 shadow-lg dark:border-gray-700 dark:bg-gray-800">
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
                         class="absolute inset-s-0 z-10 mt-2 w-56 rounded-lg border border-gray-200 bg-white py-2 shadow-lg dark:border-gray-700 dark:bg-gray-800">
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
            </nav>

            <div role="group" aria-label="{{ __('public.theme.label') }}" class="flex gap-1" x-data="theme()">
                <button type="button" x-on:click="set('light')" :aria-pressed="mode === 'light'"
                        class="rounded-lg px-2 py-1 text-sm font-semibold text-gray-700 hover:text-blue-600 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-600 dark:text-gray-300 dark:hover:text-blue-400">
                    {{ __('public.theme.light') }}
                </button>
                <button type="button" x-on:click="set('dark')" :aria-pressed="mode === 'dark'"
                        class="rounded-lg px-2 py-1 text-sm font-semibold text-gray-700 hover:text-blue-600 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-600 dark:text-gray-300 dark:hover:text-blue-400">
                    {{ __('public.theme.dark') }}
                </button>
                <button type="button" x-on:click="set('system')" :aria-pressed="mode === 'system'"
                        class="rounded-lg px-2 py-1 text-sm font-semibold text-gray-700 hover:text-blue-600 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-600 dark:text-gray-300 dark:hover:text-blue-400">
                    {{ __('public.theme.system') }}
                </button>
            </div>

            <div class="lg:hidden">
                <button x-ref="toggle" x-on:click="show()" type="button"
                        x-bind:aria-expanded="open" aria-controls="tkMobileNav"
                        class="inline-flex items-center justify-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-800 hover:border-gray-300 hover:text-gray-900 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-600 dark:border-gray-700 dark:bg-transparent dark:text-gray-300 dark:hover:border-gray-600 dark:hover:text-gray-200">
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
                    class="inline-flex items-center justify-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-800 hover:border-gray-300 hover:text-gray-900 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:border-gray-600 dark:hover:text-gray-200">
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

{{-- Tailkit: m-s-footers-01 "Simple" — auf die Copyright-Zeile reduziert. --}}
<footer class="border-t border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
    <div
        class="container mx-auto px-4 py-12 text-center text-sm text-gray-500 lg:px-8 dark:text-gray-400/80 xl:max-w-7xl">
        <span class="font-medium">{{ config('app.name', 'Para Swimming') }}</span> &copy; {{ now()->year }}
    </div>
</footer>

</body>
</html>
