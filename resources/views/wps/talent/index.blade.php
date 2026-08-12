@extends('layouts.app')

@section('title', 'Förderauswertung')

@section('content')
    <div class="max-w-7xl">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100 mb-2">Förderauswertung</h1>
        <p class="mb-6 text-sm text-zinc-500 dark:text-zinc-400">
            Welche Athletinnen und Athleten haben Potenzial? Gemessen wird der Abstand zur
            Punktzahl einer Meisterschaftsnorm. Dies ist <strong>keine</strong>
            Qualifikationsübersicht: Über die Startberechtigung sagt sie nichts aus, dafür ist eine
            reale Langbahnzeit im Qualifikationszeitraum erforderlich.
        </p>

        @livewire('wps-talent-report')
    </div>
@endsection
