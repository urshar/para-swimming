@php use App\Models\BaseTimeVersion;use App\Support\BaseTimeStrokeGroup;use Illuminate\Support\Collection; @endphp
{{--
    public/base-times/index — Punktetabelle (Spec public-frontend §5.3, Phase 6).

    Zeigt die Basiszeiten der aktuell gültigen Version (BaseTimeVersion::validOn(heute)) — kein
    Versions-Filter, historische Versionen sind kein öffentliches Bedürfnis (Planungsentscheidung
    Phase 6). Version wird namentlich ausgewiesen (Rückmeldung: sonst unklar, welche Bezeichnung
    gilt). Je Lage eine Reiter-Tabelle (role=tablist, siehe resources/js/base-time-tabs.js und
    accessibility.md "Reiternavigation … mit role=tablist"), Zeilen = Sportklasse (aufsteigend,
    PointConversionService::buildTable() — Rückmeldung: die hinterlegte sort_order ist
    absteigend), Spalten zweistufig Geschlecht über Bewerb (headers/id, accessibility.md
    "zweistufige Kopfzeilen"). Eine deutliche Trennlinie zwischen Herren- und Damen-Spaltenblock
    (Rückmeldung: sonst schwer auseinanderzuhalten) statt zweier getrennter Tabellen — bleibt
    eine zusammenhängende, mit headers/id verdrahtete Tabelle. Nur Einzelbewerbe
    (relay_count = 1).

    Unter dem sm-Breakpoint eine Sportklassen-Auswahl-dann-Einzelansicht statt einer
    Miniaturtabelle (accessibility.md "Reflow"): dieselbe x-show-Fallback-Logik wie bei den Tabs
    — ohne JS stehen alle Sportklassen-Karten einfach untereinander.
--}}
@extends('layouts.public')

@php
    /**
     * @var ?BaseTimeVersion $version
     * @var string $course
     * @var Collection<int, BaseTimeStrokeGroup> $groups
     */
@endphp

@section('title', __('public.base_times.title'))
@section('description', __('public.base_times.intro'))

@section('content')
    <h1 class="text-2xl font-semibold">{{ __('public.base_times.heading') }}</h1>
    <p class="mt-2 max-w-2xl text-sm text-gray-600 dark:text-gray-400">{{ __('public.base_times.intro') }}</p>

    @if ($version)
        <p class="mt-1 text-sm font-medium text-gray-700 dark:text-gray-300">
            {{ __('public.base_times.version_label') }}: {{ $version->display_name }}
        </p>
    @endif

    <form method="GET" class="mt-6 flex flex-wrap items-end gap-4" aria-label="{{ __('public.base_times.course') }}">
        <div class="space-y-1">
            <label for="course" class="inline-block text-sm font-medium">{{ __('public.base_times.course') }}</label>
            <div class="relative">
                <select id="course" name="course" onchange="this.form.submit()"
                        class="block w-36 appearance-none rounded-lg border border-gray-300 py-2 pr-10 pl-3 text-sm leading-6 focus:border-blue-500 focus:ring-3 focus:ring-blue-500/50 dark:border-gray-600 dark:bg-gray-800">
                    <option value="LCM" @selected($course === 'LCM')>LCM (50m)</option>
                    <option value="SCM" @selected($course === 'SCM')>SCM (25m)</option>
                </select>
                <x-select-chevron/>
            </div>
        </div>
        {{-- ml-auto auf dem noscript-Element selbst (nicht dem Button): im Flex-Container ist
             <noscript> der tatsächliche Flex-Item, der Button nur dessen Kind — siehe
             public/qualifying-times/index für die Begründung des rechtsbündigen Buttons. --}}
        <noscript class="ml-auto">
            <button type="submit"
                    class="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500">
                {{ __('public.records.filter.submit') }}
            </button>
        </noscript>
    </form>

    @if (! $version || $groups->isEmpty())
        <p class="mt-8 text-sm text-gray-500 dark:text-gray-400">{{ __('public.base_times.empty') }}</p>
    @else
        @php $strokeCodes = $groups->map(fn (BaseTimeStrokeGroup $g) => $g->stroke->lenex_code)->all(); @endphp
        <div class="mt-8" x-data="baseTimeTabs()" data-initial-stroke="{{ $strokeCodes[0] }}">
            <div role="tablist" aria-label="{{ __('public.base_times.heading') }}"
                 class="flex flex-wrap gap-1 border-b border-gray-300 dark:border-gray-700">
                @foreach ($groups as $group)
                    @php
                        $code = $group->stroke->lenex_code;
                        $strokeName = app()->getLocale() === 'de' ? $group->stroke->name_de : ($group->stroke->name_en ?? $group->stroke->name_de);
                    @endphp
                    <a href="#panel-{{ $code }}"
                       role="tab"
                       id="tab-{{ $code }}"
                       aria-controls="panel-{{ $code }}"
                       x-ref="tab-{{ $code }}"
                       x-on:click.prevent="select('{{ $code }}')"
                       x-on:keydown="onKeydown($event, {{ json_encode($strokeCodes) }})"
                       x-bind:aria-selected="isActive('{{ $code }}').toString()"
                       x-bind:tabindex="isActive('{{ $code }}') ? 0 : -1"
                       class="rounded-t-lg border border-b-0 border-transparent px-4 py-2 text-sm font-semibold text-gray-600 hover:text-blue-600 aria-selected:border-gray-300 aria-selected:bg-white aria-selected:text-blue-600 dark:text-gray-400 dark:hover:text-blue-400 dark:aria-selected:border-gray-700 dark:aria-selected:bg-gray-900 dark:aria-selected:text-blue-400">
                        {{ $strokeName }}
                    </a>
                @endforeach
            </div>

            @foreach ($groups as $group)
                @php
                    $code = $group->stroke->lenex_code;
                    $strokeName = app()->getLocale() === 'de' ? $group->stroke->name_de : ($group->stroke->name_en ?? $group->stroke->name_de);
                @endphp
                <section id="panel-{{ $code }}" role="tabpanel" aria-labelledby="tab-{{ $code }}"
                         x-show="isActive('{{ $code }}')"
                         class="border border-t-0 border-gray-300 p-4 dark:border-gray-700">

                    {{-- Matrix ab dem sm-Breakpoint; darunter Sportklassen-Auswahl + Einzelansicht (Reflow, s.o.). --}}
                    <div class="hidden overflow-x-auto rounded-lg sm:block" tabindex="0" aria-label="{{ $strokeName }}">
                        <table class="min-w-full text-sm">
                            <caption class="sr-only">{{ $strokeName }}</caption>
                            <thead>
                            <tr>
                                <th rowspan="2" scope="col" id="corner-{{ $code }}"
                                    class="border-r-2 border-gray-400 bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:border-gray-500 dark:bg-gray-700/25 dark:text-gray-50">
                                    {{ __('public.base_times.sport_class') }}
                                </th>
                                @foreach (['M', 'F'] as $gender)
                                    <th colspan="{{ $group->disciplines->count() }}" scope="colgroup"
                                        id="gender-{{ $code }}-{{ $gender }}"
                                        @class([
                                            'bg-gray-100/75 px-3 py-2 text-center font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50',
                                            'border-l-2 border-gray-400 dark:border-gray-500' => $gender === 'F',
                                        ])>
                                        {{ __('public.base_times.gender.'.$gender) }}
                                    </th>
                                @endforeach
                            </tr>
                            <tr>
                                @foreach (['M', 'F'] as $gender)
                                    @foreach ($group->disciplines as $discipline)
                                        <th scope="col" id="col-{{ $code }}-{{ $gender }}-{{ $discipline->id }}"
                                            headers="gender-{{ $code }}-{{ $gender }}"
                                            @class([
                                                'bg-gray-100/75 px-3 py-2 text-right font-semibold whitespace-nowrap text-gray-900 dark:bg-gray-700/25 dark:text-gray-50',
                                                'border-l-2 border-gray-400 dark:border-gray-500' => $gender === 'F' && $loop->first,
                                            ])>
                                            {{ $discipline->distance }}m
                                        </th>
                                    @endforeach
                                @endforeach
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($group->rows as $row)
                                <tr class="even:bg-gray-50 dark:even:bg-gray-900/50">
                                    <th scope="row" id="row-{{ $code }}-{{ $row->sportClass->id }}"
                                        class="border-r-2 border-gray-400 p-3 text-left font-medium whitespace-nowrap dark:border-gray-500">
                                        {{ $row->sportClass->code }}
                                    </th>
                                    @foreach (['M', 'F'] as $gender)
                                        @foreach ($group->disciplines as $discipline)
                                            <td @class([
                                                        'p-3 text-right whitespace-nowrap',
                                                        'border-l-2 border-gray-400 dark:border-gray-500' => $gender === 'F' && $loop->first,
                                                    ])
                                                headers="row-{{ $code }}-{{ $row->sportClass->id }} col-{{ $code }}-{{ $gender }}-{{ $discipline->id }}">
                                                {{ $row->cells[$gender][$discipline->id] ?? __('public.base_times.not_applicable') }}
                                            </td>
                                        @endforeach
                                    @endforeach
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="sm:hidden" x-data="baseTimeMobileClass()"
                         data-initial-class="{{ $group->rows->first()?->sportClass->code }}">
                        <label for="mobile-class-{{ $code }}" class="inline-block text-sm font-medium">
                            {{ __('public.base_times.mobile.select_class') }}
                        </label>
                        <div class="relative mt-1">
                            <select id="mobile-class-{{ $code }}" x-model="mobileClass"
                                    class="block w-full appearance-none rounded-lg border border-gray-300 py-2 pr-10 pl-3 text-sm leading-6 dark:border-gray-600 dark:bg-gray-800">
                                @foreach ($group->rows as $row)
                                    <option value="{{ $row->sportClass->code }}">{{ $row->sportClass->code }}</option>
                                @endforeach
                            </select>
                            <x-select-chevron/>
                        </div>

                        @foreach ($group->rows as $row)
                            <div x-show="mobileClass === '{{ $row->sportClass->code }}'" class="mt-4">
                                <h3 class="font-semibold">{{ $row->sportClass->code }}</h3>
                                <dl class="mt-2 divide-y divide-gray-200 text-sm dark:divide-gray-700">
                                    @foreach ($group->disciplines as $discipline)
                                        <div class="flex items-center justify-between py-2">
                                            <dt>{{ $discipline->distance }}m</dt>
                                            <dd class="flex gap-4">
                                                <span>{{ __('public.base_times.gender.M') }}: {{ $row->cells['M'][$discipline->id] ?? __('public.base_times.not_applicable') }}</span>
                                                <span>{{ __('public.base_times.gender.F') }}: {{ $row->cells['F'][$discipline->id] ?? __('public.base_times.not_applicable') }}</span>
                                            </dd>
                                        </div>
                                    @endforeach
                                </dl>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    @endif
@endsection
