@extends('layouts.app')

@section('title', 'Jahresvergleich')

@section('content')
    {{-- Titel, Jahr- und Span-Auswahl stehen in der Livewire-Komponente selbst,
         damit sie reaktiv auf die Auswahl reagieren können. --}}
    @livewire('year-comparison')
@endsection
