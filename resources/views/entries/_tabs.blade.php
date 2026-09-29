{{-- Umschalter Einzel | Staffel fürs Meldungen-Cockpit. In beide Cockpit-Views
     (entries/index, relay-entries/index) eingebunden; aktiver Tab über routeIs. --}}
@php
    $tabBase = 'px-4 py-2 text-sm font-medium border-b-2 -mb-px transition';
    $tabActive = 'border-blue-500 text-blue-600 dark:text-blue-400';
    $tabIdle = 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200';
@endphp
<div class="mb-4 flex gap-1 border-b border-zinc-200 dark:border-zinc-800">
    <a href="{{ route('entries.index') }}"
       class="{{ $tabBase }} {{ request()->routeIs('entries.*') ? $tabActive : $tabIdle }}">
        Einzel
    </a>
    <a href="{{ route('relay-entries.index') }}"
       class="{{ $tabBase }} {{ request()->routeIs('relay-entries.*') ? $tabActive : $tabIdle }}">
        Staffel
    </a>
</div>
