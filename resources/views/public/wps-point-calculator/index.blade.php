@php use App\Models\WpsPointParameter;use App\Models\WpsPointVersion;use Illuminate\Support\Collection; @endphp
{{--
    public/wps-point-calculator/index — WPS-Punkterechner (Rückmeldung zu Phase 6, siehe
    WpsPointCalculatorController). Struktur bewusst identisch zu point-calculator/index
    (derselbe pointCalculator()-Alpine-Baustein für die Feld-Umschaltung, dasselbe GET-Formular
    ohne AJAX/Dialog) — nur Datengrundlage und Bahn unterscheiden sich: WPS/Gompertz statt
    ÖBSV-Basiswerte/WA-Formel, nur LCM (keine WPS-Kurzbahnwerte).
--}}
@extends('layouts.public')

@php
    /**
     * @var ?WpsPointVersion $version
     * @var string $mode
     * @var string $gender
     * @var Collection<int, WpsPointParameter> $disciplines
     * @var list<int> $sportClassNumbers
     */
@endphp

@section('title', __('public.wps_point_calculator.title'))

@section('content')
    <h1 class="text-2xl font-semibold">{{ __('public.wps_point_calculator.heading') }}</h1>
    <p class="mt-2 max-w-2xl text-sm text-gray-600 dark:text-gray-400">{{ __('public.wps_point_calculator.intro') }}</p>

    @if (! $version)
        <p class="mt-8 text-sm text-gray-500 dark:text-gray-400">{{ __('public.wps_point_calculator.empty') }}</p>
    @else
        <p class="mt-1 text-sm font-medium text-gray-700 dark:text-gray-300">
            {{ __('public.wps_point_calculator.version_label') }}: {{ $version->display_name }}
        </p>

        <form method="GET" class="mt-6 max-w-2xl space-y-6" x-data="pointCalculator()" data-initial-mode="{{ $mode }}">
            <fieldset class="space-y-2">
                <legend class="text-sm font-semibold">{{ __('public.wps_point_calculator.mode.heading') }}</legend>
                <div class="flex flex-wrap gap-4">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="radio" name="mode" value="time_to_points" x-model="mode"
                               class="text-blue-600 focus:ring-3 focus:ring-blue-500/50">
                        {{ __('public.wps_point_calculator.mode.time_to_points') }}
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="radio" name="mode" value="points_to_time" x-model="mode"
                               class="text-blue-600 focus:ring-3 focus:ring-blue-500/50">
                        {{ __('public.wps_point_calculator.mode.points_to_time') }}
                    </label>
                </div>
            </fieldset>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="space-y-1">
                    <label for="gender"
                           class="inline-block text-sm font-medium">{{ __('public.wps_point_calculator.fields.gender') }}</label>
                    <select id="gender" name="gender"
                            class="block w-full rounded-lg border border-gray-200 py-2 pr-10 pl-3 text-sm leading-6 dark:border-gray-600 dark:bg-gray-800">
                        <option
                            value="M" @selected($gender === 'M')>{{ __('public.wps_point_calculator.gender.M') }}</option>
                        <option
                            value="F" @selected($gender === 'F')>{{ __('public.wps_point_calculator.gender.F') }}</option>
                    </select>
                </div>

                <div class="space-y-1">
                    <label for="discipline_id"
                           class="inline-block text-sm font-medium">{{ __('public.wps_point_calculator.fields.discipline') }}</label>
                    <select id="discipline_id" name="discipline_id" required
                            class="block w-full rounded-lg border border-gray-200 py-2 pr-10 pl-3 text-sm leading-6 dark:border-gray-600 dark:bg-gray-800">
                        <option
                            value="" @selected(! $selectedDisciplineId)>{{ __('public.wps_point_calculator.fields.sport_class_select') }}</option>
                        @foreach ($disciplines as $discipline)
                            @php
                                $strokeName = app()->getLocale() === 'de'
                                    ? $discipline->strokeType->name_de
                                    : ($discipline->strokeType->name_en ?? $discipline->strokeType->name_de);
                                $value = $discipline->stroke_type_id.':'.$discipline->distance;
                            @endphp
                            <option value="{{ $value }}" @selected($selectedDisciplineId === $value)>
                                {{ $discipline->distance }}m {{ $strokeName }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="space-y-1">
                    <label for="sport_class"
                           class="inline-block text-sm font-medium">{{ __('public.wps_point_calculator.fields.sport_class') }}</label>
                    <select id="sport_class" name="sport_class" required
                            class="block w-full rounded-lg border border-gray-200 py-2 pr-10 pl-3 text-sm leading-6 dark:border-gray-600 dark:bg-gray-800">
                        <option
                            value="" @selected(! $selectedSportClass)>{{ __('public.wps_point_calculator.fields.sport_class_select') }}</option>
                        @foreach ($sportClassNumbers as $number)
                            <option value="{{ $number }}" @selected((string) $selectedSportClass === (string) $number)>
                                S{{ $number }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="space-y-1" x-show="showTime()">
                    <label for="time"
                           class="inline-block text-sm font-medium">{{ __('public.wps_point_calculator.fields.time') }}</label>
                    <input id="time" name="time" type="text" placeholder="00:00.00"
                           value="{{ $time }}"
                           x-data x-init="IMask($el, { mask: '00:00.00', lazy: false, placeholderChar: '0' })"
                           class="block w-full rounded-lg border border-gray-200 py-2 px-3 text-sm leading-6 dark:border-gray-600 dark:bg-gray-800">
                </div>

                <div class="space-y-1" x-show="showPoints()">
                    <label for="points"
                           class="inline-block text-sm font-medium">{{ __('public.wps_point_calculator.fields.points') }}</label>
                    <input id="points" name="points" type="number" min="1" step="1"
                           value="{{ $pointsInput }}"
                           class="block w-full rounded-lg border border-gray-200 py-2 px-3 text-sm leading-6 dark:border-gray-600 dark:bg-gray-800">
                </div>
            </div>

            <button type="submit"
                    class="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500">
                {{ __('public.wps_point_calculator.fields.submit') }}
            </button>
        </form>

        @if ($error)
            <p class="mt-6 max-w-2xl text-sm text-red-600 dark:text-red-400" role="alert">{{ $error }}</p>
        @elseif ($result !== null)
            <p class="mt-6 max-w-2xl text-lg" role="status">
                <span class="font-semibold">
                    {{ $mode === 'time_to_points' ? __('public.wps_point_calculator.result.points_heading') : __('public.wps_point_calculator.result.time_heading') }}:
                </span>
                {{ $result }}
            </p>
        @endif
    @endif
@endsection
