{{--
    public/records/index — Rekordliste (Spec public-frontend §5.2, Phase 5).

    Nur österreichische Rekorde (national + je Landesverband), international (WR/ER/OR) ist
    außerhalb des öffentlichen Umfangs. Nur record_status = APPROVED — siehe
    App\Services\Public\PublicRecordService. Athlet- und Teamnamen sind bewusst unverlinkt
    (Spec §2.3), wie schon bei den Ergebnissen (Phase 4).

    Je Schwimmart eine Tabelle statt einer einzigen langen Liste (Rückmeldung: alphabetisch
    sortiert war unübersichtlich) — Reihenfolge Frei/Rücken/Brust/Fly/Lagen kommt bereits sortiert
    aus PublicRecordService::groupByStroke(). Innerhalb einer Schwimmart keine Pagination: ein
    Rekordbrett ist ein Nachschlagewerk, keine Feed-Liste (Planungsentscheidung Phase 5). Der
    Sportklassen-Filter ist ein <select> mit den in der gewählten Rekordebene tatsächlich
    vorkommenden Klassen statt Freitext.
--}}
@extends('layouts.public')

@section('title', __('public.records.title'))
@section('description', __('public.records.meta_description'))

@section('content')
    <h1 class="text-2xl font-semibold">{{ __('public.records.heading') }}</h1>

    <form method="GET" class="mt-6 flex flex-wrap items-end gap-4" aria-label="{{ __('public.records.filter.heading') }}">
        <div class="space-y-1">
            <label for="association" class="inline-block text-sm font-medium">{{ __('public.records.filter.level') }}</label>
            <div class="relative">
                <select id="association" name="association"
                        class="block w-56 appearance-none rounded-lg border border-gray-200 py-2 pr-10 pl-3 text-sm leading-6 focus:border-blue-500 focus:ring-3 focus:ring-blue-500/50 dark:border-gray-600 dark:bg-gray-800">
                    <option value="" @selected($filter->association === '')>{{ __('public.records.filter.level_national') }}</option>
                    @foreach ($associations as $code => $name)
                        <option value="{{ $code }}" @selected($filter->association === $code)>{{ $code }} &ndash; {{ $name }}</option>
                    @endforeach
                </select>
                <x-select-chevron/>
            </div>
        </div>

        <div class="space-y-1">
            <label for="sport_class" class="inline-block text-sm font-medium">{{ __('public.records.filter.sport_class') }}</label>
            <div class="relative">
                <select id="sport_class" name="sport_class"
                        class="block w-32 appearance-none rounded-lg border border-gray-200 py-2 pr-10 pl-3 text-sm leading-6 focus:border-blue-500 focus:ring-3 focus:ring-blue-500/50 dark:border-gray-600 dark:bg-gray-800">
                    <option value="" @selected($filter->sportClass === '')>{{ __('public.records.filter.sport_class_all') }}</option>
                    @foreach ($sportClasses as $class)
                        <option value="{{ $class }}" @selected($filter->sportClass === $class)>{{ $class }}</option>
                    @endforeach
                </select>
                <x-select-chevron/>
            </div>
        </div>

        <div class="space-y-1">
            <label for="gender" class="inline-block text-sm font-medium">{{ __('public.records.filter.gender') }}</label>
            <div class="relative">
                <select id="gender" name="gender"
                        class="block w-32 appearance-none rounded-lg border border-gray-200 py-2 pr-10 pl-3 text-sm leading-6 focus:border-blue-500 focus:ring-3 focus:ring-blue-500/50 dark:border-gray-600 dark:bg-gray-800">
                    <option value="" @selected($filter->gender === '')>{{ __('public.records.filter.gender_all') }}</option>
                    <option value="M" @selected($filter->gender === 'M')>{{ __('public.records.gender.M') }}</option>
                    <option value="F" @selected($filter->gender === 'F')>{{ __('public.records.gender.F') }}</option>
                </select>
                <x-select-chevron/>
            </div>
        </div>

        <div class="space-y-1">
            <label for="course" class="inline-block text-sm font-medium">{{ __('public.records.filter.course') }}</label>
            <div class="relative">
                <select id="course" name="course"
                        class="block w-36 appearance-none rounded-lg border border-gray-200 py-2 pr-10 pl-3 text-sm leading-6 focus:border-blue-500 focus:ring-3 focus:ring-blue-500/50 dark:border-gray-600 dark:bg-gray-800">
                    <option value="" @selected($filter->course === '')>{{ __('public.records.filter.course_all') }}</option>
                    <option value="LCM" @selected($filter->course === 'LCM')>LCM (50m)</option>
                    <option value="SCM" @selected($filter->course === 'SCM')>SCM (25m)</option>
                </select>
                <x-select-chevron/>
            </div>
        </div>

        <div class="flex items-center gap-2 pb-2">
            <input type="checkbox" id="youth" name="youth" value="1" @checked($filter->youth)
                   class="rounded border-gray-300 text-blue-600 focus:ring-3 focus:ring-blue-500/50 dark:border-gray-600 dark:bg-gray-800">
            <label for="youth" class="text-sm font-medium">{{ __('public.records.filter.youth') }}</label>
        </div>

        {{-- ml-auto: an den rechten Rand der Zeile statt nur "letztes Element" — sonst bleibt bei
             viel Platz in der Zeile eine sichtbare Lücke bis zum tatsächlichen rechten Rand
             (dieselbe Rückmeldung wie bei der Startberechtigung, siehe public/qualifying-times/index). --}}
        <button type="submit"
                class="ml-auto inline-flex items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500">
            {{ __('public.records.filter.submit') }}
        </button>
    </form>

    <div class="mt-4 flex flex-wrap gap-x-6 gap-y-1 text-sm text-gray-600 dark:text-gray-400">
        <a href="{{ route('public.records.export', array_merge(['locale' => app()->getLocale(), 'format' => 'lxf'], $filter->toQuery())) }}"
           class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400">
            {{ __('public.records.downloads.lenex') }}
        </a>
        <span class="text-xs text-gray-500 dark:text-gray-500">({{ __('public.records.downloads.lenex_hint') }})</span>
        <a href="{{ route('public.records.export', array_merge(['locale' => app()->getLocale(), 'format' => 'pdf'], $filter->toQuery())) }}"
           class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400">
            {{ __('public.records.downloads.pdf') }}
        </a>
    </div>

    @if ($groups->isEmpty())
        <p class="mt-8 text-sm text-gray-500 dark:text-gray-400">{{ __('public.records.empty') }}</p>
    @else
        <div class="mt-8 flex flex-col gap-10">
            @foreach ($groups as $group)
                @php
                    $strokeName = app()->getLocale() === 'de'
                        ? $group->stroke?->name_de
                        : ($group->stroke?->name_en ?? $group->stroke?->name_de);
                @endphp
                <section>
                    <h2 class="mb-3 text-lg font-semibold">{{ $strokeName ?? '—' }}</h2>

                    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700" tabindex="0"
                         aria-label="{{ $strokeName }}">
                        <table class="min-w-full text-sm">
                            <caption class="sr-only">{{ $strokeName }}</caption>
                            <thead>
                                <tr>
                                    <th scope="col" class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                        {{ __('public.records.columns.distance') }}
                                    </th>
                                    <th scope="col" class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                        {{ __('public.records.columns.sport_class') }}
                                    </th>
                                    <th scope="col" class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                        {{ __('public.records.columns.gender') }}
                                    </th>
                                    <th scope="col" class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                        {{ __('public.records.columns.course') }}
                                    </th>
                                    <th scope="col" class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                        {{ __('public.records.columns.time') }}
                                    </th>
                                    <th scope="col" class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                        {{ __('public.records.columns.athlete') }}
                                    </th>
                                    <th scope="col" class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                        {{ __('public.records.columns.club') }}
                                    </th>
                                    <th scope="col" class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                        {{ __('public.records.columns.location') }}
                                    </th>
                                    <th scope="col" class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                        {{ __('public.records.columns.date') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($group->records as $record)
                                    <tr class="even:bg-gray-50 dark:even:bg-gray-900/50">
                                        <td class="p-3 whitespace-nowrap">
                                            @if ($record->is_relay)
                                                {{ $record->relay_count }}&times;
                                            @endif
                                            {{ $record->distance }}m
                                        </td>
                                        <td class="p-3 font-medium whitespace-nowrap">{{ $record->sport_class }}</td>
                                        <td class="p-3 whitespace-nowrap">{{ __('public.records.gender.'.$record->gender) }}</td>
                                        <td class="p-3 whitespace-nowrap">{{ $record->course }}</td>
                                        <td class="p-3 whitespace-nowrap">{{ $record->formatted_swim_time }}</td>
                                        <td class="p-3">
                                            @if ($record->is_relay)
                                                {{ $record->relayTeam->map->display_name->implode(', ') }}
                                            @else
                                                {{ $record->athlete?->full_name ?? '—' }}
                                            @endif
                                        </td>
                                        <td class="p-3">{{ $record->record_club_name ?? '—' }}</td>
                                        <td class="p-3 whitespace-nowrap">
                                            @if ($record->meet_city)
                                                @if ($record->meetNation)
                                                    <x-flag code="{{ $record->meetNation->code }}" class="me-1 inline-block h-3 w-4 align-[-1px]" />
                                                @endif
                                                {{ $record->meet_city }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="p-3 whitespace-nowrap">{{ $record->set_date?->format('d.m.Y') ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endforeach
        </div>
    @endif
@endsection
