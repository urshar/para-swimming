@extends('layouts.app')

@section('title', 'Statistik')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Statistik</h1>
        <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
            Kennzahlen und Auswertungen je Jahr und Veranstaltung
        </p>
    </div>

    @livewire('statistics-dashboard')
@endsection
