{{--
    public/home — Startseite (Spec public-frontend §6, Phase 9).

    Drei Kacheln (Tailkit a-c-cards-02 "Simple in Grid", docs/snippets/card-grid.html — bis hierhin
    ungenutzt) statt einer inhaltsleeren Platzhalterseite: nächste Veranstaltung, neue Rekorde,
    aktuelle Ergebnisse. Jede Kachel bleibt leer/verkürzt statt zu fehlen, wenn keine Daten
    vorliegen (z. B. noch keine veröffentlichte Veranstaltung) — siehe HomeController.
--}}
@php use App\Models\Meet;use App\Models\SwimRecord;use Illuminate\Support\Collection; @endphp
@extends('layouts.public')

@php
    /**
     * @var ?Meet $nextMeet
     * @var Collection<int, SwimRecord> $recentRecords
     * @var ?Meet $latestMeetWithResults
     */
@endphp

@section('title', __('public.home.title'))
@section('description', __('public.meta.default_description'))

@section('content')
    <h1 class="text-2xl font-semibold">{{ __('public.home.heading') }}</h1>
    <p class="mt-2 max-w-2xl text-sm text-gray-600 dark:text-gray-400">{{ __('public.home.intro') }}</p>

    <div class="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-3 lg:gap-8">
        {{-- Kachel: nächste Veranstaltung --}}
        <div class="flex flex-col overflow-hidden rounded-lg border border-gray-300 dark:border-gray-700 dark:bg-gray-800">
            <div class="bg-gray-50 px-5 py-4 dark:bg-gray-700/50">
                <h2 class="font-medium">{{ __('public.home.next_meet.heading') }}</h2>
            </div>
            <div class="grow p-5 text-sm">
                @if ($nextMeet)
                    <p class="font-medium text-gray-900 dark:text-gray-100">{{ $nextMeet->name }}</p>
                    <p class="mt-1 text-gray-600 dark:text-gray-400">{{ $nextMeet->date_range }} · {{ $nextMeet->city }}</p>
                @else
                    <p class="text-gray-500 dark:text-gray-400">{{ __('public.home.next_meet.empty') }}</p>
                @endif
            </div>
            @if ($nextMeet)
                <div class="bg-gray-50 px-5 py-4 text-sm dark:bg-gray-700/50">
                    {{-- aria-label mit Veranstaltungsname: "Details ansehen" allein ist aus dem
                         Zusammenhang gerissen (z. B. in einer Screenreader-Linkliste) nicht
                         verständlich (docs/accessibility.md, Dokumentlinks-Regel sinngemäß auch
                         hier angewendet). --}}
                    <a href="{{ route('public.meets.show', ['locale' => app()->getLocale(), 'meet' => $nextMeet]) }}"
                       aria-label="{{ $nextMeet->name }}: {{ __('public.home.next_meet.link') }}"
                       class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400">
                        {{ __('public.home.next_meet.link') }}
                    </a>
                </div>
            @endif
        </div>

        {{-- Kachel: neue Rekorde --}}
        <div class="flex flex-col overflow-hidden rounded-lg border border-gray-300 dark:border-gray-700 dark:bg-gray-800">
            <div class="bg-gray-50 px-5 py-4 dark:bg-gray-700/50">
                <h2 class="font-medium">{{ __('public.home.recent_records.heading') }}</h2>
            </div>
            <div class="grow p-5 text-sm">
                @if ($recentRecords->isEmpty())
                    <p class="text-gray-500 dark:text-gray-400">{{ __('public.home.recent_records.empty') }}</p>
                @else
                    <ul class="flex flex-col gap-3">
                        @foreach ($recentRecords as $record)
                            @php
                                $strokeName = app()->getLocale() === 'de'
                                    ? $record->strokeType?->name_de
                                    : ($record->strokeType?->name_en ?? $record->strokeType?->name_de);
                            @endphp
                            <li>
                                <p class="font-medium text-gray-900 dark:text-gray-100">
                                    {{ $record->distance }}m {{ $strokeName }} {{ $record->sport_class }}
                                    ({{ __('public.records.gender.'.$record->gender) }})
                                </p>
                                <p class="text-gray-600 dark:text-gray-400">
                                    @if ($record->is_relay)
                                        {{ $record->relayTeam->map->display_name->implode(', ') }}
                                    @else
                                        {{ $record->athlete?->full_name ?? '—' }}
                                    @endif
                                    · {{ $record->formatted_swim_time }} · {{ $record->set_date?->format('d.m.Y') }}
                                </p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <div class="bg-gray-50 px-5 py-4 text-sm dark:bg-gray-700/50">
                <a href="{{ route('public.records.index', ['locale' => app()->getLocale()]) }}"
                   class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400">
                    {{ __('public.home.recent_records.link') }}
                </a>
            </div>
        </div>

        {{-- Kachel: aktuelle Ergebnisse --}}
        <div class="flex flex-col overflow-hidden rounded-lg border border-gray-300 dark:border-gray-700 dark:bg-gray-800">
            <div class="bg-gray-50 px-5 py-4 dark:bg-gray-700/50">
                <h2 class="font-medium">{{ __('public.home.recent_results.heading') }}</h2>
            </div>
            <div class="grow p-5 text-sm">
                @if ($latestMeetWithResults)
                    <p class="font-medium text-gray-900 dark:text-gray-100">{{ $latestMeetWithResults->name }}</p>
                    <p class="mt-1 text-gray-600 dark:text-gray-400">{{ $latestMeetWithResults->date_range }} · {{ $latestMeetWithResults->city }}</p>
                @else
                    <p class="text-gray-500 dark:text-gray-400">{{ __('public.home.recent_results.empty') }}</p>
                @endif
            </div>
            @if ($latestMeetWithResults)
                <div class="bg-gray-50 px-5 py-4 text-sm dark:bg-gray-700/50">
                    <a href="{{ route('public.meets.results', ['locale' => app()->getLocale(), 'meet' => $latestMeetWithResults]) }}"
                       aria-label="{{ $latestMeetWithResults->name }}: {{ __('public.home.recent_results.link') }}"
                       class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400">
                        {{ __('public.home.recent_results.link') }}
                    </a>
                </div>
            @endif
        </div>
    </div>
@endsection
