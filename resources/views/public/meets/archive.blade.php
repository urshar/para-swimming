@extends('layouts.public')

@section('title', __('public.meets.archive.title'))
@section('description', __('public.meets.archive.meta_description'))

@section('content')
    <p class="mb-4">
        <a href="{{ route('public.meets.index', ['locale' => app()->getLocale()]) }}"
           class="text-sm font-semibold text-blue-600 hover:text-blue-500 dark:text-blue-400">
            &larr; {{ __('public.meets.archive.back_link') }}
        </a>
    </p>

    <h1 class="text-2xl font-semibold">{{ __('public.meets.archive.heading') }}</h1>

    @if ($meetsByYear->isEmpty())
        <p class="mt-8 text-sm text-gray-500 dark:text-gray-400">{{ __('public.meets.archive.empty') }}</p>
    @else
        @foreach ($meetsByYear as $year => $meets)
            <section id="{{ $year }}" class="mt-12">
                <h2 class="mb-4 text-lg font-semibold">{{ $year }}</h2>
                @include('public.meets._table', ['meets' => $meets])
            </section>
        @endforeach
    @endif
@endsection
