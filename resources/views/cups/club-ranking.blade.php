@extends('layouts.app')

@section('title', 'Vereinswertung — '.$cup->name)

@section('content')
    <div class="max-w-5xl">
        @livewire('cup-club-ranking', ['cup' => $cup])
    </div>
@endsection
