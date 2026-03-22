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

{{-- flux:sidebar und flux:main müssen direkte Kinder von body sein --}}

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
            <flux:navlist.item icon="trophy" href="{{ route('meets.index') }}" :current="request()->routeIs('meets.*')">
                Wettkämpfe
            </flux:navlist.item>
            <flux:navlist.item icon="list-bullet" href="{{ route('entries.index') }}"
                               :current="request()->routeIs('entries.*')">Meldungen
            </flux:navlist.item>
            <flux:navlist.item icon="chart-bar" href="{{ route('results.index') }}"
                               :current="request()->routeIs('results.*')">Ergebnisse
            </flux:navlist.item>
        </flux:navlist.group>

        <flux:navlist.group heading="Stammdaten">
            <flux:navlist.item icon="user-group" href="{{ route('athletes.index') }}"
                               :current="request()->routeIs('athletes.*')">Athleten
            </flux:navlist.item>
            <flux:navlist.item icon="building-office" href="{{ route('clubs.index') }}"
                               :current="request()->routeIs('clubs.*')">Vereine
            </flux:navlist.item>
            <flux:navlist.item icon="flag" href="{{ route('nations.index') }}"
                               :current="request()->routeIs('nations.*')">Nationen
            </flux:navlist.item>
        </flux:navlist.group>

        <flux:navlist.group heading="Rekorde & LENEX">
            <flux:navlist.item icon="star" href="{{ route('records.index') }}"
                               :current="request()->routeIs('records.*')">Rekorde
            </flux:navlist.item>
            <flux:navlist.item icon="arrow-up-tray" href="{{ route('lenex.import') }}"
                               :current="request()->routeIs('lenex.import*')">LENEX Import
            </flux:navlist.item>
            <flux:navlist.item icon="arrow-down-tray" href="{{ route('lenex.export') }}"
                               :current="request()->routeIs('lenex.export*')">LENEX Export
            </flux:navlist.item>
        </flux:navlist.group>

    </flux:navlist>

    <flux:spacer/>

    {{-- Dark Mode Toggle --}}
    <div class="px-2 py-3 border-t border-zinc-200 dark:border-zinc-800">
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

</flux:sidebar>

{{-- flux:main übernimmt automatisch den verbleibenden Platz neben flux:sidebar --}}
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
