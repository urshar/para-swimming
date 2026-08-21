@extends('layouts.public')

@section('title', $meet->name)

@section('content')
    <p class="mb-4">
        <a href="{{ route('public.meets.index', ['locale' => app()->getLocale()]) }}"
           class="text-sm font-semibold text-blue-600 hover:text-blue-500 dark:text-blue-400">
            &larr; {{ __('public.meets.show.back_link') }}
        </a>
    </p>

    <h1 class="text-2xl font-semibold">{{ $meet->name }}</h1>
    <p class="mt-1 flex items-center gap-2 text-gray-600 dark:text-gray-400">
        <span>{{ $meet->date_range }} · {{ $meet->city }}</span>
        <x-flag code="{{ $meet->nation?->code }}"
                label="{{ app()->getLocale() === 'de' ? $meet->nation?->name_de : $meet->nation?->name_en }}"/>
    </p>

    <dl class="mt-6 grid grid-cols-1 gap-6 sm:grid-cols-2">
        <div>
            <dt class="text-sm font-semibold text-gray-500 dark:text-gray-400">
                {{ __('public.meets.show.entries_deadline') }}
            </dt>
            <dd class="mt-1">
                @if ($meet->hasDeadline())
                    {{ $meet->entries_deadline->format('d.m.Y') }}
                @else
                    <span class="text-gray-400 dark:text-gray-500">{{ __('public.meets.show.no_deadline') }}</span>
                @endif
            </dd>
        </div>

        @if ($meet->livetiming_url)
            <div>
                <dt class="text-sm font-semibold text-gray-500 dark:text-gray-400">
                    {{ __('public.meets.show.livetiming') }}
                </dt>
                <dd class="mt-1">
                    {{-- Extern, deshalb rel="noopener" und im Linktext als extern gekennzeichnet
                         statt nur über ein Symbol (Spec §5.1, accessibility.md). --}}
                    <a href="{{ $meet->livetiming_url }}" target="_blank" rel="noopener"
                       class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400">
                        {{ __('public.meets.show.livetiming') }}
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            ({{ __('public.meets.show.livetiming_hint') }})
                        </span>
                    </a>
                </dd>
            </div>
        @endif
    </dl>

    <section class="mt-8">
        <h2 class="mb-4 text-lg font-semibold">{{ __('public.meets.show.documents_heading') }}</h2>
        @if ($documents->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('public.meets.show.no_documents') }}</p>
        @else
            <ul class="flex flex-col gap-2">
                @foreach ($documents as $group)
                    <li>
                        <a href="{{ route('public.documents.download', ['locale' => app()->getLocale(), 'document' => $group->document]) }}"
                           class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400">
                            {{ __('public.documents.category.'.$group->document->category) }}: {{ $group->document->title }}
                            @if ($group->document->formatLabel() || $group->document->sizeLabel())
                                ({{ collect([$group->document->formatLabel(), $group->document->sizeLabel()])->filter()->implode(', ') }})
                            @endif
                        </a>
                        @if ($group->alternate?->locale)
                            <span class="ml-2 text-sm text-gray-500 dark:text-gray-400">
                                {{ __('public.meets.show.also_available_in') }}
                                <a href="{{ route('public.documents.download', ['locale' => app()->getLocale(), 'document' => $group->alternate]) }}"
                                   class="text-blue-600 hover:text-blue-500 dark:text-blue-400">
                                    {{ __('public.languages.'.$group->alternate->locale) }}
                                </a>
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endsection
