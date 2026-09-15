@extends('layouts.app')

@section('title', 'Statistik')

@section('content')
    {{-- Titel + Jahr-Auswahl stehen in der Livewire-Komponente selbst (nicht mehr hier): nur sie
         kann das gewählte Jahr reaktiv im Header rechtsbündig anzeigen (Design-Feedback Erik,
         15.09.2026). --}}
    @livewire('statistics-dashboard')
@endsection
