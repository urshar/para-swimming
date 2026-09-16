@php use App\Models\BaseTimeDiscipline;use App\Models\BaseTimeSportClass;use App\Models\BaseTimeVersion;use Illuminate\Support\Collection; @endphp
{{--
    public/point-calculator/index — Punkterechner (Spec public-frontend §5.3, Phase 6).

    Eine eigene Seite statt eines Dialogfensters (§5.3: neun Felder in einem Dialog sind für
    Tastatur-/Screenreader-Bedienung die schlechtere Form). Ein GET-Formular statt AJAX: die
    Berechnung funktioniert damit auch ohne JS über einen echten Seitenaufruf, x-data hier ist
    reine Anzeigesache (Zeit- vs. Punkte-Feld ein-/ausblenden — resources/js/point-calculator.js).
    Rechnet immer mit der aktuell gültigen Basiswert-Version, kein Versions-Feld
    (Planungsentscheidung Phase 6).
--}}
@extends('layouts.public')

@php
    /**
     * @var ?BaseTimeVersion $version
     * @var string $mode
     * @var string $course
     * @var string $gender
     * @var Collection<int, BaseTimeDiscipline> $disciplines
     * @var Collection<int, BaseTimeSportClass> $sportClasses
     */
@endphp

@section('title', __('public.point_calculator.title'))
@section('description', __('public.point_calculator.intro'))

@section('content')
    <h1 class="text-2xl font-semibold">{{ __('public.point_calculator.heading') }}</h1>
    <p class="mt-2 max-w-2xl text-sm text-gray-600 dark:text-gray-400">{{ __('public.point_calculator.intro') }}</p>

    @if (! $version)
        <p class="mt-8 text-sm text-gray-500 dark:text-gray-400">{{ __('public.point_calculator.empty') }}</p>
    @else
        <p class="mt-1 text-sm font-medium text-gray-700 dark:text-gray-300">
            {{ __('public.base_times.version_label') }}: {{ $version->display_name }}
        </p>

        <form method="GET" class="mt-6 max-w-2xl space-y-6" x-data="pointCalculator()" data-initial-mode="{{ $mode }}">
            <fieldset class="space-y-2">
                <legend class="text-sm font-semibold">{{ __('public.point_calculator.mode.heading') }}</legend>
                <div class="flex flex-wrap gap-4">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="radio" name="mode" value="time_to_points" x-model="mode"
                               class="text-blue-600 focus:ring-3 focus:ring-blue-500/50">
                        {{ __('public.point_calculator.mode.time_to_points') }}
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="radio" name="mode" value="points_to_time" x-model="mode"
                               class="text-blue-600 focus:ring-3 focus:ring-blue-500/50">
                        {{ __('public.point_calculator.mode.points_to_time') }}
                    </label>
                </div>
            </fieldset>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="space-y-1">
                    <label for="course"
                           class="inline-block text-sm font-medium">{{ __('public.point_calculator.fields.course') }}</label>
                    <div class="relative">
                        <select id="course" name="course"
                                class="block w-full appearance-none rounded-lg border border-gray-300 py-2 pr-10 pl-3 text-sm leading-6 dark:border-gray-600 dark:bg-gray-800">
                            <option value="LCM" @selected($course === 'LCM')>LCM (50m)</option>
                            <option value="SCM" @selected($course === 'SCM')>SCM (25m)</option>
                        </select>
                        <x-select-chevron/>
                    </div>
                </div>

                <div class="space-y-1">
                    <label for="gender"
                           class="inline-block text-sm font-medium">{{ __('public.point_calculator.fields.gender') }}</label>
                    <div class="relative">
                        <select id="gender" name="gender"
                                class="block w-full appearance-none rounded-lg border border-gray-300 py-2 pr-10 pl-3 text-sm leading-6 dark:border-gray-600 dark:bg-gray-800">
                            <option
                                value="M" @selected($gender === 'M')>{{ __('public.point_calculator.gender.M') }}</option>
                            <option
                                value="F" @selected($gender === 'F')>{{ __('public.point_calculator.gender.F') }}</option>
                        </select>
                        <x-select-chevron/>
                    </div>
                </div>

                <div class="space-y-1">
                    <label for="discipline_id"
                           class="inline-block text-sm font-medium">{{ __('public.point_calculator.fields.discipline') }}</label>
                    <div class="relative">
                        <select id="discipline_id" name="discipline_id" required
                                class="block w-full appearance-none rounded-lg border border-gray-300 py-2 pr-10 pl-3 text-sm leading-6 dark:border-gray-600 dark:bg-gray-800">
                            <option
                                value="" @selected(! $selectedDisciplineId)>{{ __('public.point_calculator.fields.sport_class_select') }}</option>
                            @foreach ($disciplines as $discipline)
                                @php
                                    $strokeName = app()->getLocale() === 'de'
                                        ? $discipline->strokeType->name_de
                                        : ($discipline->strokeType->name_en ?? $discipline->strokeType->name_de);
                                @endphp
                                <option value="{{ $discipline->id }}" @selected($selectedDisciplineId == $discipline->id)>
                                    {{ $discipline->distance }}m {{ $strokeName }}
                                </option>
                            @endforeach
                        </select>
                        <x-select-chevron/>
                    </div>
                </div>

                <div class="space-y-1">
                    <label for="sport_class"
                           class="inline-block text-sm font-medium">{{ __('public.point_calculator.fields.sport_class') }}</label>
                    <div class="relative">
                        <select id="sport_class" name="sport_class" required
                                class="block w-full appearance-none rounded-lg border border-gray-300 py-2 pr-10 pl-3 text-sm leading-6 dark:border-gray-600 dark:bg-gray-800">
                            <option
                                value="" @selected(! $selectedSportClass)>{{ __('public.point_calculator.fields.sport_class_select') }}</option>
                            @foreach ($sportClasses as $sportClass)
                                <option
                                    value="{{ $sportClass->code }}" @selected($selectedSportClass === $sportClass->code)>
                                    {{ $sportClass->code }}
                                </option>
                            @endforeach
                        </select>
                        <x-select-chevron/>
                    </div>
                </div>

                <div class="space-y-1" x-show="showTime()">
                    <label for="time"
                           class="inline-block text-sm font-medium">{{ __('public.point_calculator.fields.time') }}</label>
                    <input id="time" name="time" type="text" placeholder="00:00.00"
                           value="{{ $time }}"
                           x-data x-init="IMask($el, { mask: '00:00.00', lazy: false, placeholderChar: '0' })"
                           class="block w-full rounded-lg border border-gray-300 py-2 px-3 text-sm leading-6 dark:border-gray-600 dark:bg-gray-800">
                </div>

                <div class="space-y-1" x-show="showPoints()">
                    <label for="points"
                           class="inline-block text-sm font-medium">{{ __('public.point_calculator.fields.points') }}</label>
                    <input id="points" name="points" type="number" min="1" step="1"
                           value="{{ $pointsInput }}"
                           class="block w-full rounded-lg border border-gray-300 py-2 px-3 text-sm leading-6 dark:border-gray-600 dark:bg-gray-800">
                </div>
            </div>

            <button type="submit"
                    class="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500">
                {{ __('public.point_calculator.fields.submit') }}
            </button>
        </form>

        @if ($error)
            <p class="mt-6 max-w-2xl text-sm text-red-600 dark:text-red-400" role="alert">{{ $error }}</p>
        @elseif ($result !== null)
            <p class="mt-6 max-w-2xl text-lg" role="status">
                <span class="font-semibold">
                    {{ $mode === 'time_to_points' ? __('public.point_calculator.result.points_heading') : __('public.point_calculator.result.time_heading') }}:
                </span>
                {{ $result }}
            </p>
        @endif
    @endif
@endsection
