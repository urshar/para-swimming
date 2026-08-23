@extends('layouts.public')

@section('title', __('public.meets.index.title'))

@section('content')
    <h1 class="text-2xl font-semibold">{{ __('public.meets.index.title') }}</h1>

    <section class="mt-8">
        <h2 class="mb-4 text-lg font-semibold">{{ __('public.meets.index.upcoming_heading') }}</h2>
        @if ($upcoming->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('public.meets.index.none_upcoming') }}</p>
        @else
            @include('public.meets._table', ['meets' => $upcoming])
        @endif
    </section>

    <section class="mt-12">
        <h2 class="mb-4 text-lg font-semibold">{{ __('public.meets.index.past_heading') }}</h2>
        @if ($recentPast->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('public.meets.index.none_past') }}</p>
        @else
            @include('public.meets._table', ['meets' => $recentPast])
        @endif

        <p class="mt-4">
            <a href="{{ route('public.meets.archive', ['locale' => app()->getLocale()]) }}"
               class="text-sm font-semibold text-blue-600 hover:text-blue-500 dark:text-blue-400">
                {{ __('public.meets.index.archive_link') }} &rarr;
            </a>
        </p>
    </section>
@endsection
