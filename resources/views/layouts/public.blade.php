{{--
    layouts/public — Grundgerüst für den öffentlichen Bereich (Phase 1, Spec public-frontend §3).

    Bewusst schlicht und von Hand geschrieben, KEIN Tailkit-Markup: Solange die Lizenzfrage aus
    §3.1.1 offen ist, bleibt jede aus einem Snippet entstandene View ungetrackt (§3.1.2). Dieses
    Gerüst entsteht nicht aus einem Snippet — es existiert, damit Routing und Tests auf jedem
    Checkout laufen, auch ohne lokal zugelieferte Snippets. Die eigentliche, gestaltete Fassung
    entsteht aus den sechs Grundbausteinen aus §3.1.3 und bleibt bis zur Klärung lokal: Sobald
    Tailkit-Markup hier einzieht, wird diese Datei per `git rm --cached` untracked und in die
    .gitignore aufgenommen, wie es §3.1.2 für abgeleitete Views vorsieht.
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
<body class="min-h-screen bg-white text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">

    <a href="#content"
       class="sr-only focus:not-sr-only focus:fixed focus:inset-s-4 focus:top-4 focus:z-50 focus:rounded focus:bg-white focus:px-4 focus:py-2 focus:text-zinc-900 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-600 dark:focus:bg-zinc-900 dark:focus:text-zinc-100">
        {{ __('public.skip_to_content') }}
    </a>

    <header class="border-b border-zinc-200 dark:border-zinc-800">
        <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-4">
            <a href="{{ route('public.home', ['locale' => app()->getLocale()]) }}" class="font-semibold">
                {{ config('app.name', 'Para Swimming') }}
            </a>

            <div x-data="theme()" role="group" aria-label="{{ __('public.theme.label') }}" class="flex gap-1">
                <button type="button" x-on:click="set('light')" :aria-pressed="mode === 'light'"
                        class="rounded px-2 py-1 text-sm focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-600">
                    {{ __('public.theme.light') }}
                </button>
                <button type="button" x-on:click="set('dark')" :aria-pressed="mode === 'dark'"
                        class="rounded px-2 py-1 text-sm focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-600">
                    {{ __('public.theme.dark') }}
                </button>
                <button type="button" x-on:click="set('system')" :aria-pressed="mode === 'system'"
                        class="rounded px-2 py-1 text-sm focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-600">
                    {{ __('public.theme.system') }}
                </button>
            </div>
        </div>
    </header>

    <main id="content" class="mx-auto max-w-5xl px-4 py-8">
        @yield('content')
    </main>

    <footer class="border-t border-zinc-200 px-4 py-6 text-sm text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
        <div class="mx-auto max-w-5xl">
            &copy; {{ now()->year }} {{ config('app.name', 'Para Swimming') }}
        </div>
    </footer>

</body>
</html>
