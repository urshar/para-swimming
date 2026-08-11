@extends('layouts.app')

@section('title', 'WPS-Ranglisten')

@section('content')
    <div class="max-w-7xl">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100 mb-2">WPS-Ranglisten</h1>
        <p class="mb-6 text-sm text-zinc-500 dark:text-zinc-400">
            Leistungen nach WPS-Punkten, über Sportklassen und Bewerbe hinweg vergleichbar. Die
            Punkte stammen aus der am jeweiligen Ergebnis gespeicherten Punkteversion und werden
            hier nicht neu berechnet.
        </p>

        @livewire('wps-rankings')
    </div>
@endsection
