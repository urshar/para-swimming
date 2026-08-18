@extends('layouts.app')

@section('title', 'WPS-Vereinsauswertung')

@section('content')
    <div class="max-w-6xl">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100 mb-2">WPS-Vereinsauswertung</h1>
        <p class="mb-6 text-sm text-zinc-500 dark:text-zinc-400">
            Leistungsstärke der Vereine nach WPS-Punkten, mit wählbarer Rechenweise.
        </p>

        @livewire('wps-club-ranking')
    </div>
@endsection
