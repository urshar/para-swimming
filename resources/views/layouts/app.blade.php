<!DOCTYPE html>
<html
    lang="de"
    x-data="{ darkMode: localStorage.getItem('theme') !== 'light' }"
    x-init="
        if (!localStorage.getItem('theme')) localStorage.setItem('theme', 'dark');
        $watch('darkMode', v => {
            localStorage.setItem('theme', v ? 'dark' : 'light');
            document.documentElement.classList.toggle('dark', v);
        });
        document.documentElement.classList.toggle('dark', darkMode);
    "
    :class="{ 'dark': darkMode }"
>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Para Swimming') }} – @yield('title', 'Dashboard')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxStyles
</head>
<body class="min-h-screen bg-zinc-50 dark:bg-zinc-950 font-sans antialiased">

<flux:sidebar sticky stashable class="bg-white dark:bg-zinc-900 border-r border-zinc-200 dark:border-zinc-800">

    <flux:sidebar.toggle class="lg:hidden" icon="x-mark"/>

    {{-- Logo --}}
    <a href="{{ route('meets.index') }}" class="flex items-center gap-3 px-2 py-4 mb-2">
        <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-blue-600 shrink-0">
            <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
            </svg>
        </div>
        <div class="leading-tight">
            <div class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Para Swimming</div>
            <div class="text-xs text-zinc-500 dark:text-zinc-400">NatDB</div>
        </div>
    </a>

    <flux:navlist>

        <flux:navlist.group heading="Wettkämpfe">
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

        {{-- Vereinsmeldungen — nur für Club-User und Admins --}}
        @auth
            @if(auth()->user()->club_id || auth()->user()->is_admin)
                <flux:navlist.group heading="Mein Verein">
                    <flux:navlist.item icon="pencil-square" href="{{ route('meets.index') }}"
                                       :current="request()->routeIs('club-entries.*')">
                        Meldungen erfassen
                    </flux:navlist.item>
                </flux:navlist.group>
            @endif
        @endauth

        <flux:navlist.group heading="Stammdaten">
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

        <flux:navlist.group heading="Rekorde">
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

        <flux:navlist.group heading="LENEX">
            <flux:navlist.item icon="arrow-up-tray" href="{{ route('lenex.import') }}"
                               :current="request()->routeIs('lenex.import*')">
                Import
            </flux:navlist.item>
            <flux:navlist.item icon="arrow-down-tray" href="{{ route('lenex.export') }}"
                               :current="request()->routeIs('lenex.export*')">
                Export
            </flux:navlist.item>
        </flux:navlist.group>

        {{-- Administration — nur für Admins sichtbar --}}
        @auth
            @if(auth()->user()->is_admin)
                <flux:navlist.group heading="Administration">
                    <flux:navlist.item icon="users" href="{{ route('admin.users.index') }}"
                                       :current="request()->routeIs('admin.users.*')">
                        Benutzer
                    </flux:navlist.item>
                </flux:navlist.group>
            @endif
        @endauth

    </flux:navlist>

    <flux:spacer/>

    {{-- Dark Mode Toggle --}}
    <div class="px-2 pb-1 border-t border-zinc-200 dark:border-zinc-800 pt-3">
        <button
            @click="darkMode = !darkMode"
            class="flex items-center gap-2 w-full px-3 py-2 text-sm rounded-lg text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors"
        >
            <svg x-show="!darkMode" class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                 stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
            </svg>
            <svg x-show="darkMode" class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                 stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
            </svg>
            <span x-text="darkMode ? 'Hell-Modus' : 'Dunkel-Modus'"></span>
        </button>
    </div>

    {{-- User-Bereich --}}
    <div class="px-2 pb-3 border-zinc-200 dark:border-zinc-800">

        @auth
            <div class="px-3 py-2 rounded-lg bg-zinc-50 dark:bg-zinc-800/60">
                <div class="flex items-center gap-2 mb-2">
                    <div class="w-7 h-7 rounded-full bg-blue-100 dark:bg-blue-900/50 flex items-center justify-center shrink-0">
                        <span class="text-xs font-semibold text-blue-700 dark:text-blue-300">
                            {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                        </span>
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs font-medium text-zinc-900 dark:text-zinc-100 truncate">
                            {{ auth()->user()->name }}
                        </p>
                        <p class="text-xs text-zinc-400 truncate">
                            @if(auth()->user()->is_admin)
                                Administrator
                            @elseif(auth()->user()->club)
                                {{ auth()->user()->club->short_name ?? auth()->user()->club->name }}
                            @else
                                Kein Verein
                            @endif
                        </p>
                    </div>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                            class="flex items-center gap-2 w-full px-2 py-1.5 text-xs rounded-md text-zinc-500 dark:text-zinc-400 hover:bg-zinc-200 dark:hover:bg-zinc-700 hover:text-zinc-700 dark:hover:text-zinc-200 transition-colors">
                        <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                        </svg>
                        Abmelden
                    </button>
                </form>
            </div>
        @else
            <a href="{{ route('login') }}"
               class="flex items-center gap-2 w-full px-3 py-2 text-sm rounded-lg text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800 hover:text-zinc-900 dark:hover:text-zinc-100 transition-colors">
                <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/>
                </svg>
                Anmelden
            </a>
        @endauth

    </div>

</flux:sidebar>

<flux:main class="p-6">

    @if(session('success'))
        <flux:callout variant="success" icon="check-circle" class="mb-6">
            {{ session('success') }}
        </flux:callout>
    @endif

    @if($errors->any())
        <flux:callout variant="danger" icon="exclamation-circle" class="mb-6">
            <ul class="list-disc list-inside space-y-1 text-sm">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </flux:callout>
    @endif

    @yield('content')

</flux:main>

@fluxScripts
</body>
</html>
