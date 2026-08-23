{{--
    public/meets/results — Ergebnisse einer Veranstaltung (Spec public-frontend §5.2, Phase 4).

    noindex/nofollow (Spec §2.3 Pkt. 4): Ergebnisse sind bewusst nur pro Veranstaltung abrufbar,
    nicht personen-/vereinsübergreifend durchsuchbar — siehe robots.txt (Disallow für diese
    Route) und app/Services/Public/PublicResultService.php.

    Name und Verein sind bewusst unverlinkt: Es gibt keine öffentliche Athlet*innen- oder
    Vereinsseite, ein Link würde auf eine interne, login-pflichtige Route führen.
--}}
@extends('layouts.public')

@section('title', $meet->name.' – '.__('public.meets.results.title'))
@section('robots', 'noindex, nofollow')

@section('content')
    <p class="mb-4">
        <a href="{{ route('public.meets.show', ['locale' => app()->getLocale(), 'meet' => $meet]) }}"
           class="text-sm font-semibold text-blue-600 hover:text-blue-500 dark:text-blue-400">
            &larr; {{ __('public.meets.results.back_link') }}
        </a>
    </p>

    <h1 class="text-2xl font-semibold">{{ $meet->name }}</h1>
    <h2 class="mt-1 text-lg text-gray-600 dark:text-gray-400">{{ __('public.meets.results.heading') }}</h2>

    @if ($groups->isEmpty())
        <p class="mt-6 text-sm text-gray-500 dark:text-gray-400">{{ __('public.meets.results.empty') }}</p>
    @else
        <div class="mt-8 flex flex-col gap-10">
            @foreach ($groups as $group)
                <section>
                    <h3 class="mb-3 text-lg font-semibold">{{ $group->event->display_name }}</h3>

                    @foreach ($group->classes as $sportClass => $results)
                        @php
                            $hasPoints = $results->contains(fn ($r) => $r->points !== null);
                            $hasWpsPoints = $results->contains(fn ($r) => $r->wps_points !== null);
                        @endphp
                        <div class="mb-6 last:mb-0">
                            <h4 class="mb-2 text-sm font-semibold text-gray-600 dark:text-gray-400">
                                {{ $sportClass === ''
                                    ? __('public.meets.results.class_heading_none')
                                    : __('public.meets.results.class_heading', ['class' => $sportClass]) }}
                            </h4>

                            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700" tabindex="0"
                                 aria-label="{{ $group->event->display_name }}">
                                <table class="min-w-full text-sm">
                                    <caption class="sr-only">{{ $group->event->display_name }}</caption>
                                    <thead>
                                        <tr>
                                            <th scope="col" class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                                {{ __('public.meets.results.columns.place') }}
                                            </th>
                                            <th scope="col" class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                                {{ __('public.meets.results.columns.name') }}
                                            </th>
                                            <th scope="col" class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                                {{ __('public.meets.results.columns.club') }}
                                            </th>
                                            <th scope="col" class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                                {{ __('public.meets.results.columns.birth_year') }}
                                            </th>
                                            <th scope="col" class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                                {{ __('public.meets.results.columns.sport_class') }}
                                            </th>
                                            <th scope="col" class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                                {{ __('public.meets.results.columns.time') }}
                                            </th>
                                            @if ($hasPoints)
                                                <th scope="col" class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                                    {{ __('public.meets.results.columns.points') }}
                                                </th>
                                            @endif
                                            @if ($hasWpsPoints)
                                                <th scope="col" class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                                    {{ __('public.meets.results.columns.wps_points') }}
                                                </th>
                                            @endif
                                            <th scope="col" class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                                {{ __('public.meets.results.columns.record') }}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($results as $result)
                                            <tr class="even:bg-gray-50 dark:even:bg-gray-900/50">
                                                <td class="p-3 whitespace-nowrap">{{ $result->place ?? '—' }}</td>
                                                <td class="p-3">{{ $result->athlete?->full_name }}</td>
                                                <td class="p-3">{{ $result->club?->display_name }}</td>
                                                <td class="p-3 whitespace-nowrap">{{ $result->athlete?->birth_date?->year }}</td>
                                                <td class="p-3">{{ $result->sport_class ?? '—' }}</td>
                                                <td class="p-3 whitespace-nowrap">
                                                    {{-- Zeit hat Vorrang vor dem Status (spiegelt Result::getFormattedSwimTimeAttribute()):
                                                         z.B. EXH-Ergebnisse haben trotzdem eine reelle Zeit und bleiben damit sichtbar,
                                                         nur wenn keine Zeit erfasst ist (DNS/DNF/DSQ/...) zeigen wir den Status. --}}
                                                    @if ($result->swim_time)
                                                        {{ $result->formatted_swim_time }}
                                                    @elseif ($result->status)
                                                        {{ __('public.meets.results.status.'.$result->status) }}
                                                    @else
                                                        —
                                                    @endif
                                                </td>
                                                @if ($hasPoints)
                                                    <td class="p-3 whitespace-nowrap">{{ $result->points ?? '—' }}</td>
                                                @endif
                                                @if ($hasWpsPoints)
                                                    <td class="p-3 whitespace-nowrap">{{ $result->wps_points ?? '—' }}</td>
                                                @endif
                                                <td class="p-3 whitespace-nowrap">
                                                    @if ($result->hasRecords())
                                                        {{-- Ausgeschriebene Bezeichnung statt Kürzel-Badge mit title=: title= wird von
                                                             Screenreadern nicht zuverlässig vorgelesen (wie beim Flaggen-Fix, siehe
                                                             components/flag.blade.php). --}}
                                                        <ul class="flex flex-col gap-0.5 text-xs font-semibold text-gray-700 dark:text-gray-300">
                                                            @if ($result->is_world_record)
                                                                <li>{{ __('public.meets.results.records.world') }}</li>
                                                            @endif
                                                            @if ($result->is_european_record)
                                                                <li>{{ __('public.meets.results.records.european') }}</li>
                                                            @endif
                                                            @if ($result->is_national_record)
                                                                <li>{{ __('public.meets.results.records.national') }}</li>
                                                            @endif
                                                            @if ($result->is_junior_record)
                                                                <li>{{ __('public.meets.results.records.junior') }}</li>
                                                            @endif
                                                            @if ($result->is_regional_record)
                                                                <li>{{ __('public.meets.results.records.regional') }}</li>
                                                            @endif
                                                            @if ($result->is_regional_junior_record)
                                                                <li>{{ __('public.meets.results.records.regional_junior') }}</li>
                                                            @endif
                                                        </ul>
                                                    @else
                                                        <span class="text-gray-400 dark:text-gray-500">—</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endforeach
                </section>
            @endforeach
        </div>
    @endif
@endsection
