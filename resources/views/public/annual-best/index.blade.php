{{--
    public/annual-best/index — Jahresbestleistungen (Spec public-frontend §5.4, Phase 7).

    Pro Person das punktbeste Einzelergebnis eines Kalenderjahrs (AnnualBestService), getrennt
    nach Geschlecht und Behinderungsgruppe (PI/VI/MI/HI/T21). Keine Staffeln, kein EXH (siehe
    Service-Klassenkommentar). Athletennamen sind unverlinkter Text (§2.3 Regel 2).

    Jahr, Klasse und Geschlecht stehen alle in einer Zeile, Suchen rechts daneben (Rückmeldung:
    "Passe die Jahresbestleistungen Filter so an wie die ÖBSV Cup Wertung") — dieselbe Struktur wie
    public/cup-ranking/index, nur ohne die dortige Jugend-Checkbox (Jahresbestleistungen kennen
    keine Altersgruppen), dafür mit denselben zwei Sammel-Optionen ("Alle Klassen"/"Damen & Herren",
    Rückmeldung: "beim Klasse Filter fehlt mir noch alle Klassengruppen zusammen und beim
    Geschlecht Filter Damen und Herren zusammen"). Wertungskategorie-Auswahl über
    resources/js/ranking-filter.js (generisch, auch von der Cup-Wertung genutzt) statt einer
    Reiterleiste — siehe dort für Details, die progressive-enhancement-Begründung und die
    x-data-Anführungszeichen-Falle bei @json().
--}}
@php use App\Models\SportClassGroup;use Illuminate\Support\Collection; @endphp
@extends('layouts.public')

@php
    /**
     * @var Collection<int, int> $years
     * @var int $year
     * @var Collection<int, array{gender: ?string, group: ?SportClassGroup, results: Collection}> $buckets
     */
@endphp

@section('title', __('public.annual_best.title'))
@section('description', __('public.annual_best.intro'))
@section('robots', 'noindex, nofollow')

@section('content')
    <h1 class="text-2xl font-semibold">{{ __('public.annual_best.heading') }}</h1>
    <p class="mt-2 max-w-2xl text-sm text-gray-600 dark:text-gray-400">{{ __('public.annual_best.intro') }}</p>

    @if ($buckets->isNotEmpty())
        @php
            $firstBucket = $buckets->first();
            $groupOptions = $buckets
                ->pluck('group')
                ->filter()
                ->unique('id')
                ->map(fn (SportClassGroup $group) => ['value' => (string) $group->id, 'label' => $group->name_de])
                ->values();
            // "combined" steht unabhängig von den Bucket-Daten immer zur Auswahl (Rückmeldung:
            // "beim Geschlecht Filter Damen und Herren zusammen") — Jahresbestleistungen kennen
            // gar keine echte gemeinsame Wertung, die Option filtert hier also immer nur "nicht
            // nach Geschlecht eingrenzen" (ranking-filter.js).
            $genderOptions = $buckets->pluck('gender')->unique()->merge([null])->unique()->sortBy(fn (?string $g) => match ($g) {
                'M' => 0,
                'F' => 1,
                default => 2,
            })->values();
            // Kompakte Liste der tatsächlich vorhandenen (Gruppe, Geschlecht)-Kombinationen, nur
            // für die "keine Daten"-Leermeldung im JS gebraucht (siehe ranking-filter.js) — nicht
            // die vollen Bucket-Daten.
            $filterKeys = $buckets->map(fn (array $b) => [
                'groupId' => (string) ($b['group']?->id ?? 'all'),
                'gender' => $b['gender'] ?? 'combined',
            ])->values();
        @endphp
    @endif

    <div x-data="tableSearch()" class="mt-6">
        {{-- x-data hier absichtlich mit einfachen statt doppelten Anführungszeichen — siehe
             public/cup-ranking/index für die ausführliche Begründung (@json() escapt die
             strukturellen JSON-Anführungszeichen nicht, ein doppelt angeführtes Attribut würde
             mitten im JSON abbrechen und die Komponente bliebe unsichtbar/wirkungslos). Eigenes
             <div>, nicht dasselbe wie x-data="tableSearch()" oben: zwei x-data auf demselben
             Element ist ungültiges HTML (nur das erste zählt). --}}
        <div @if ($buckets->isNotEmpty()) x-data='rankingFilter(@json($filterKeys))' @endif>
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div class="flex flex-wrap items-end gap-4">
                    <form method="GET" action="{{ route('public.annual-best.index', ['locale' => app()->getLocale()]) }}"
                          class="flex flex-wrap items-end gap-4" aria-label="{{ __('public.annual_best.year') }}">
                        <div class="space-y-1">
                            <label for="year"
                                   class="inline-block text-sm font-medium">{{ __('public.annual_best.year') }}</label>
                            <div class="relative">
                                <select id="year" name="year"
                                        onchange="location.href = this.options[this.selectedIndex].dataset.href"
                                        class="block w-32 appearance-none rounded-lg border border-gray-300 py-2 pr-10 pl-3 text-sm leading-6 dark:border-gray-600 dark:bg-gray-800">
                                    @foreach ($years as $availableYear)
                                        <option value="{{ $availableYear }}"
                                                data-href="{{ route('public.annual-best.index', ['locale' => app()->getLocale(), 'jahr' => $availableYear]) }}"
                                            @selected($availableYear === $year)>
                                            {{ $availableYear }}
                                        </option>
                                    @endforeach
                                    @unless ($years->contains($year))
                                        {{-- Das laufende Jahr steht auch dann zur Auswahl, wenn es noch keine Veranstaltung dafür gibt. --}}
                                        <option value="{{ $year }}"
                                                data-href="{{ route('public.annual-best.index', ['locale' => app()->getLocale(), 'jahr' => $year]) }}"
                                                selected>
                                            {{ $year }}
                                        </option>
                                    @endunless
                                </select>
                                <x-select-chevron/>
                            </div>
                        </div>
                        {{-- ml-auto auf dem noscript-Element selbst (nicht dem Button): im
                             Flex-Container ist <noscript> der tatsächliche Flex-Item, der Button
                             nur dessen Kind — siehe public/qualifying-times/index für die
                             Begründung des rechtsbündigen Buttons. --}}
                        <noscript class="ml-auto">
                            <button type="submit"
                                    class="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500">
                                {{ __('public.records.filter.submit') }}
                            </button>
                        </noscript>
                    </form>

                    @if ($buckets->isNotEmpty())
                        <div class="space-y-1">
                            <label for="annual-best-group"
                                   class="inline-block text-sm font-medium">{{ __('public.annual_best.filter.group') }}</label>
                            <div class="relative">
                                <select id="annual-best-group" x-model="groupId"
                                        class="block w-56 appearance-none rounded-lg border border-gray-300 py-2 pr-10 pl-3 text-sm leading-6 dark:border-gray-600 dark:bg-gray-800">
                                    <option value="all">{{ __('public.annual_best.filter.group_all') }}</option>
                                    @foreach ($groupOptions as $groupOption)
                                        <option value="{{ $groupOption['value'] }}"
                                            @selected((string) $firstBucket['group']?->id === $groupOption['value'])>
                                            {{ $groupOption['label'] }}
                                        </option>
                                    @endforeach
                                </select>
                                <x-select-chevron/>
                            </div>
                        </div>

                        <div class="space-y-1">
                            <label for="annual-best-gender"
                                   class="inline-block text-sm font-medium">{{ __('public.annual_best.filter.gender') }}</label>
                            <div class="relative">
                                <select id="annual-best-gender" x-model="gender"
                                        class="block w-40 appearance-none rounded-lg border border-gray-300 py-2 pr-10 pl-3 text-sm leading-6 dark:border-gray-600 dark:bg-gray-800">
                                    @foreach ($genderOptions as $genderOption)
                                        <option value="{{ $genderOption ?? 'combined' }}"
                                            @selected($firstBucket['gender'] === $genderOption)>
                                            {{ $genderOption === null ? __('public.annual_best.gender.combined') : __('public.annual_best.gender.'.$genderOption) }}
                                        </option>
                                    @endforeach
                                </select>
                                <x-select-chevron/>
                            </div>
                        </div>
                    @endif
                </div>

                @if ($buckets->isNotEmpty())
                    <div class="w-full max-w-xs space-y-1">
                        <label for="annual-best-search"
                               class="inline-block text-sm font-medium">{{ __('public.annual_best.search') }}</label>
                        <input id="annual-best-search" type="search" x-model="query"
                               placeholder="{{ __('public.annual_best.search_placeholder') }}"
                               class="block w-full rounded-lg border border-gray-300 py-2 px-3 text-sm leading-6 dark:border-gray-600 dark:bg-gray-800">
                    </div>
                @endif
            </div>

            @if ($buckets->isEmpty())
                <p class="mt-8 text-sm text-gray-500 dark:text-gray-400">{{ __('public.annual_best.empty') }}</p>
            @else
                <p class="hidden mt-8 text-sm text-gray-500 dark:text-gray-400" x-show="! hasMatch">
                    {{ __('public.annual_best.filter.no_match') }}
                </p>

                {{-- aria-live: Screenreader bekommen mit, dass sich der Inhalt nach einer
                     Filteränderung geändert hat (es gibt keinen Fokuswechsel wie bei einem
                     Formular-Submit). --}}
                <div aria-live="polite">
                    @foreach ($buckets as $bucket)
                        @php
                            $genderLabel = $bucket['gender'] === null
                                ? __('public.annual_best.gender.combined')
                                : __('public.annual_best.gender.'.$bucket['gender']);
                            $groupLabel = $bucket['group']?->name_de ?? __('public.annual_best.filter.group_all');
                            $heading = $genderLabel.' — '.$groupLabel;
                            // Zusammengelegte Geschlechter ("Damen & Herren") brauchen eine eigene
                            // Spalte je Zeile, weil das sonst nur aus der Überschrift hervorginge —
                            // die Sportklasse steht ohnehin schon immer als eigene Spalte da.
                            $showGenderColumn = $bucket['gender'] === null;
                        @endphp
                        <section x-show="isVisible($el.dataset)"
                                 data-group-id="{{ $bucket['group']?->id ?? 'all' }}"
                                 data-gender="{{ $bucket['gender'] ?? 'combined' }}"
                                 class="mt-6">
                            <h2 class="mb-3 text-lg font-semibold">{{ $heading }}</h2>

                            <div class="overflow-x-auto rounded-lg border border-gray-300 dark:border-gray-700"
                                 tabindex="0" aria-label="{{ $heading }}">
                                <table class="min-w-full text-sm">
                                    <caption class="sr-only">{{ $heading }}</caption>
                                    <thead>
                                    <tr>
                                        <th scope="col"
                                            class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                            {{ __('public.annual_best.columns.rank') }}
                                        </th>
                                        <th scope="col"
                                            class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                            {{ __('public.annual_best.columns.athlete') }}
                                        </th>
                                        @if ($showGenderColumn)
                                            <th scope="col"
                                                class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                                {{ __('public.annual_best.columns.gender') }}
                                            </th>
                                        @endif
                                        <th scope="col"
                                            class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                            {{ __('public.annual_best.columns.club') }}
                                        </th>
                                        <th scope="col"
                                            class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                            {{ __('public.annual_best.columns.discipline') }}
                                        </th>
                                        <th scope="col"
                                            class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                            {{ __('public.annual_best.columns.sport_class') }}
                                        </th>
                                        <th scope="col"
                                            class="bg-gray-100/75 px-3 py-3 text-right font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                            {{ __('public.annual_best.columns.points') }}
                                        </th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach ($bucket['results'] as $result)
                                        @php
                                            $strokeName = app()->getLocale() === 'de'
                                                ? $result->swimEvent->strokeType?->name_de
                                                : ($result->swimEvent->strokeType?->name_en ?? $result->swimEvent->strokeType?->name_de);
                                            $searchText = strtolower(trim($result->athlete->last_name.' '.$result->athlete->first_name.' '.($result->club?->display_name ?? '')));
                                        @endphp
                                        <tr class="even:bg-gray-50 dark:even:bg-gray-900/50"
                                            x-show="matches($el.dataset.search)"
                                            data-search="{{ $searchText }}">
                                            <td class="p-3 font-medium whitespace-nowrap">{{ $result->rank }}</td>
                                            <td class="p-3">{{ $result->athlete->last_name }}, {{ $result->athlete->first_name }}</td>
                                            @if ($showGenderColumn)
                                                <td class="p-3 whitespace-nowrap">{{ __('public.annual_best.gender.'.strtoupper((string) $result->athlete->gender)) }}</td>
                                            @endif
                                            <td class="p-3">{{ $result->club?->display_name ?? '—' }}</td>
                                            <td class="p-3 whitespace-nowrap">{{ $result->swimEvent->distance }}
                                                m {{ $strokeName }}</td>
                                            <td class="p-3 whitespace-nowrap">{{ $result->sport_class }}</td>
                                            <td class="p-3 text-right font-mono font-semibold">{{ $result->points }}</td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
