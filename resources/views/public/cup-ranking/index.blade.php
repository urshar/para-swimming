{{--
    public/cup-ranking/index — ÖBSV Cup-Wertung (Spec public-frontend §5.4, Phase 7).

    Read-only Ansicht des bereits berechneten Gesamtwertungs-Snapshots (siehe
    Public\CupRankingController) — kein Neu-berechnen-Button und keine Runden-Aufschlüsselung wie
    im internen Bereich (cups/overall-ranking.blade.php): Beides ist ein admin-/Nachvollziehbar-
    keits-Werkzeug, keine öffentliche Anforderung. Athletennamen sind unverlinkter Text (§2.3
    Regel 2 — die interne Ansicht verlinkt auf athletes.show, hier bewusst nicht).

    Jahr, Klasse, Geschlecht und Jugend-Checkbox stehen alle in einer Zeile, Suchen rechts daneben
    (Rückmeldung). Jahresauswahl als <select>, das die Seite mit dem gewählten Jahr neu lädt (kein
    eigenes Index/Show-Routenpaar wie intern). Das Suchfeld filtert ausschließlich die bereits
    geladene Tabelle (§2.3 Punkt 3, resources/js/table-search.js) — kein serverseitiger Request.

    Runden-Aufschlüsselung (Rückmeldung: "die Punkte der einzelnen Runden gehören dazu") wie im
    internen Bereich, hervorgehoben via OverallRankingService::attachRoundBreakdown() — dieselbe
    Grün/fett-Markierung für die tatsächlich gezählten besten Runden.

    Wertungskategorie-Auswahl über drei Filter statt einer Reiterleiste (Rückmeldung: "zu viele
    Tabellen untereinander … ich denke es ist besser ein Dropdown für die Behindertenklasse, eines
    für Geschlecht und eine Checkbox für Jugend"), Klasse und Geschlecht mit je einer
    Sammel-Option ("Alle Klassen"/"Damen & Herren", Rückmeldung: "beim Klasse Filter fehlt mir noch
    alle Klassengruppen zusammen und beim Geschlecht Filter Damen und Herren zusammen") —
    resources/js/ranking-filter.js (generisch, auch von public/annual-best/index genutzt), siehe
    dort für Details und die progressive-enhancement-Begründung.
--}}
@php use App\Models\AgeGroup;use App\Models\Cup;use App\Models\Meet;use App\Models\SportClassGroup;use Illuminate\Support\Collection; @endphp
@extends('layouts.public')

@php
    /**
     * @var Collection<int, int> $years
     * @var ?int $year
     * @var ?Cup $cup
     * @var \Illuminate\Database\Eloquent\Collection<int, Meet> $meets
     * @var Collection<int, array{gender: ?string, group: SportClassGroup, ageGroup: ?AgeGroup, results: Collection}> $brackets
     */
@endphp

@section('title', __('public.cup.title'))
@section('description', __('public.cup.intro'))
@section('robots', 'noindex, nofollow')

@section('content')
    <h1 class="text-2xl font-semibold">{{ __('public.cup.heading') }}</h1>
    <p class="mt-2 max-w-2xl text-sm text-gray-600 dark:text-gray-400">{{ __('public.cup.intro') }}</p>

    @if ($years->isEmpty())
        <p class="mt-8 text-sm text-gray-500 dark:text-gray-400">{{ __('public.cup.no_years') }}</p>
    @else
        @if ($brackets->isNotEmpty())
            @php
                $firstBracket = $brackets->first();
                $groupOptions = $brackets->pluck('group')->filter()->unique('id')->values();
                // "combined" steht unabhängig von den tatsächlichen Bracket-Daten immer zur Auswahl
                // (Rückmeldung: "beim Geschlecht Filter Damen und Herren zusammen") — auch für
                // Gruppen ohne echte gemeinsame Wertung filtert es dann einfach nicht nach
                // Geschlecht (ranking-filter.js), statt nur bei Gruppen mit Cup::isGenderCombined
                // angeboten zu werden.
                $genderOptions = $brackets->pluck('gender')->unique()->merge([null])->unique()->sortBy(fn (?string $g) => match ($g) {
                    'M' => 0,
                    'F' => 1,
                    default => 2,
                })->values();
                // Kompakte Liste der tatsächlich vorhandenen (Gruppe, Geschlecht, Jugend)-
                // Kombinationen, nur für die "keine Daten"-Leermeldung im JS gebraucht (siehe
                // ranking-filter.js) — nicht die vollen Bracket-Daten. groupId als String (nicht
                // Zahl): ranking-filter.js vergleicht typsicher gegen die ebenfalls string-wertigen
                // data-group-id-Attribute, siehe dort für die Begründung (Jahresbestleistungen
                // brauchen ein nicht-numerisches "none"-Kürzel für Buckets ohne Gruppe).
                $filterKeys = $brackets->map(fn (array $b) => [
                    'groupId' => (string) ($b['group']->id ?? 'all'),
                    'gender' => $b['gender'] ?? 'combined',
                    'jugend' => $b['ageGroup']?->code === 'JUGEND',
                ])->values();
            @endphp
        @endif

        <div x-data="tableSearch()" class="mt-6">
            {{-- x-data hier absichtlich mit einfachen statt doppelten Anführungszeichen: @json()
                 ist Laravels roher json_encode() mit HTML-sicheren HEX-Flags — die escapen nur
                 Sonderzeichen INNERHALB von JSON-Strings, nicht die strukturellen
                 Anführungszeichen von Objekten/Arrays selbst (Fund: mit doppelten
                 Anführungszeichen brach das HTML-Attribut mitten im JSON ab, x-data blieb
                 unvollständig/ungültig, Alpine initialisierte die Komponente nie — dadurch blieb
                 die Tabelle unsichtbar und die Dropdowns reagierten auf nichts). $filterKeys
                 enthält nur Zahlen/feste Kürzel (M/F/combined) und Booleans, also keine
                 Apostrophe — einfache Anführungszeichen sind hier sicher. Laravels eigene Doku
                 empfiehlt @json() explizit in Attributen mit einfachen Anführungszeichen.
                 Eigenes <div>, nicht dasselbe wie x-data="tableSearch()" oben: zwei x-data auf
                 demselben Element ist ungültiges HTML (nur das erste zählt). --}}
            <div @if ($brackets->isNotEmpty()) x-data='rankingFilter(@json($filterKeys))' @endif>
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div class="flex flex-wrap items-end gap-4">
                    {{-- Ohne JS: normales GET-Formular, "year" landet als Query-Parameter (der
                         Controller akzeptiert beides, siehe dort). Mit JS: direkte Navigation auf
                         die hübsche /cup/{jahr}-URL, kein Formular-Submit nötig. --}}
                    <form method="GET"
                          action="{{ route('public.cup-ranking.index', ['locale' => app()->getLocale()]) }}"
                          class="flex flex-wrap items-end gap-4" aria-label="{{ __('public.cup.year') }}">
                        <div class="space-y-1">
                            <label for="year"
                                   class="inline-block text-sm font-medium">{{ __('public.cup.year') }}</label>
                            <div class="relative">
                                <select id="year" name="year"
                                        onchange="location.href = this.options[this.selectedIndex].dataset.href"
                                        class="block w-32 appearance-none rounded-lg border border-gray-300 py-2 pr-10 pl-3 text-sm leading-6 dark:border-gray-600 dark:bg-gray-800">
                                    @foreach ($years as $availableYear)
                                        <option value="{{ $availableYear }}"
                                                data-href="{{ route('public.cup-ranking.index', ['locale' => app()->getLocale(), 'jahr' => $availableYear]) }}"
                                            @selected($availableYear === $year)>
                                            {{ $availableYear }}
                                        </option>
                                    @endforeach
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

                    @if ($brackets->isNotEmpty())
                        <div class="space-y-1">
                            <label for="cup-group"
                                   class="inline-block text-sm font-medium">{{ __('public.cup.filter.group') }}</label>
                            <div class="relative">
                                <select id="cup-group" x-model="groupId"
                                        class="block w-56 appearance-none rounded-lg border border-gray-300 py-2 pr-10 pl-3 text-sm leading-6 dark:border-gray-600 dark:bg-gray-800">
                                    <option value="all">{{ __('public.cup.filter.group_all') }}</option>
                                    @foreach ($groupOptions as $group)
                                        <option value="{{ $group->id }}"
                                            @selected($firstBracket['group']->id === $group->id)>
                                            {{ $group->name_de }}
                                        </option>
                                    @endforeach
                                </select>
                                <x-select-chevron/>
                            </div>
                        </div>

                        <div class="space-y-1">
                            <label for="cup-gender"
                                   class="inline-block text-sm font-medium">{{ __('public.cup.filter.gender') }}</label>
                            <div class="relative">
                                <select id="cup-gender" x-model="gender"
                                        class="block w-48 appearance-none rounded-lg border border-gray-300 py-2 pr-10 pl-3 text-sm leading-6 dark:border-gray-600 dark:bg-gray-800">
                                    @foreach ($genderOptions as $genderOption)
                                        <option value="{{ $genderOption ?? 'combined' }}"
                                            @selected($firstBracket['gender'] === $genderOption)>
                                            {{ $genderOption === null ? __('public.cup.gender.combined') : __('public.cup.gender.'.$genderOption) }}
                                        </option>
                                    @endforeach
                                </select>
                                <x-select-chevron/>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 pb-2">
                            <input type="checkbox" id="cup-jugend" x-model="jugend"
                                   class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800"
                                @checked($firstBracket['ageGroup']?->code === 'JUGEND')>
                            <label for="cup-jugend"
                                   class="text-sm font-medium">{{ __('public.cup.filter.youth_only') }}</label>
                        </div>
                    @endif
                </div>

                @if ($brackets->isNotEmpty())
                    <div class="w-full max-w-xs space-y-1">
                        <label for="cup-search"
                               class="inline-block text-sm font-medium">{{ __('public.cup.search') }}</label>
                        <input id="cup-search" type="search" x-model="query"
                               placeholder="{{ __('public.cup.search_placeholder') }}"
                               class="block w-full rounded-lg border border-gray-300 py-2 px-3 text-sm leading-6 dark:border-gray-600 dark:bg-gray-800">
                    </div>
                @endif
            </div>

            @if ($brackets->isEmpty())
                <p class="mt-8 text-sm text-gray-500 dark:text-gray-400">{{ __('public.cup.empty') }}</p>
            @else
                @if ($meets->isNotEmpty())
                    <p class="mt-6 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('public.cup.round_legend', ['count' => $cup->best_of_count]) }}
                    </p>
                @endif

                <p class="hidden mt-8 text-sm text-gray-500 dark:text-gray-400" x-show="! hasMatch">
                    {{ __('public.cup.filter.no_match') }}
                </p>

                {{-- aria-live: Screenreader bekommen mit, dass sich der Inhalt nach einer
                     Filteränderung geändert hat (es gibt keinen Fokuswechsel wie bei einem
                     Formular-Submit). --}}
                <div aria-live="polite">
                    @foreach ($brackets as $bracket)
                        @php
                            $isJugend = $bracket['ageGroup']?->code === 'JUGEND';
                            $groupLabel = $bracket['group']?->name_de ?? __('public.cup.filter.group_all');
                            $genderLabel = $bracket['gender'] === null
                                ? __('public.cup.gender.combined')
                                : __('public.cup.gender.'.$bracket['gender']);
                            $heading = $genderLabel.' — '.$groupLabel.($bracket['ageGroup'] ? ' — '.$bracket['ageGroup']->name_de : '');
                            // Zeilen mehrerer Sportklassengruppen bzw. beider Geschlechter zusammengelegt
                            // ("Alle Klassen"/"Damen & Herren") — dann braucht jede Zeile eine eigene
                            // Klasse-/Geschlecht-Spalte, weil das sonst nur aus der Überschrift hervorginge.
                            $showGroupColumn = $bracket['group'] === null;
                            $showGenderColumn = $bracket['gender'] === null;
                        @endphp
                        <section x-show="isVisible($el.dataset)"
                                 data-group-id="{{ $bracket['group']->id ?? 'all' }}"
                                 data-gender="{{ $bracket['gender'] ?? 'combined' }}"
                                 data-jugend="{{ $isJugend ? '1' : '0' }}"
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
                                            {{ __('public.cup.columns.rank') }}
                                        </th>
                                        <th scope="col"
                                            class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                            {{ __('public.cup.columns.athlete') }}
                                        </th>
                                        @if ($showGenderColumn)
                                            <th scope="col"
                                                class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                                {{ __('public.cup.columns.gender') }}
                                            </th>
                                        @endif
                                        @if ($showGroupColumn)
                                            <th scope="col"
                                                class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                                {{ __('public.cup.columns.sport_class') }}
                                            </th>
                                        @endif
                                        <th scope="col"
                                            class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                            {{ __('public.cup.columns.club') }}
                                        </th>
                                        @foreach ($meets as $meet)
                                            <th scope="col" abbr="R.{{ $loop->index + 1 }}"
                                                class="bg-gray-100/75 px-3 py-3 text-right font-semibold whitespace-nowrap text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                                <span class="sr-only">{{ $meet->name }} ({{ $meet->start_date->format('d.m.Y') }})</span>
                                                <span aria-hidden="true">R.{{ $loop->index + 1 }}</span>
                                            </th>
                                        @endforeach
                                        <th scope="col"
                                            class="bg-gray-100/75 px-3 py-3 text-right font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                            {{ __('public.cup.columns.total_points') }}
                                        </th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach ($bracket['results'] as $row)
                                        @php
                                            $searchText = strtolower(trim($row->athlete->last_name.' '.$row->athlete->first_name.' '.($row->club?->display_name ?? '')));
                                        @endphp
                                        <tr class="even:bg-gray-50 dark:even:bg-gray-900/50"
                                            x-show="matches($el.dataset.search)"
                                            data-search="{{ $searchText }}">
                                            <td class="p-3 font-medium whitespace-nowrap">{{ $row->rank }}</td>
                                            <td class="p-3">{{ $row->athlete->last_name }}, {{ $row->athlete->first_name }}</td>
                                            @if ($showGenderColumn)
                                                <td class="p-3 whitespace-nowrap">{{ __('public.cup.gender.'.$row->gender) }}</td>
                                            @endif
                                            @if ($showGroupColumn)
                                                <td class="p-3 whitespace-nowrap">{{ $row->sportClassGroup?->name_de ?? '—' }}</td>
                                            @endif
                                            <td class="p-3">{{ $row->club?->display_name ?? '—' }}</td>
                                            @foreach ($row->rounds as $round)
                                                <td @class([
                                                            'p-3 text-right font-mono text-xs whitespace-nowrap',
                                                            'font-semibold text-emerald-700 dark:text-emerald-400' => $round['counted'],
                                                            'text-gray-400 dark:text-gray-500' => ! $round['counted'],
                                                        ])>
                                                    {{ $round['points'] ?? '—' }}{{ $round['sport_class'] ? '/'.$round['sport_class'] : '' }}
                                                </td>
                                            @endforeach
                                            <td class="p-3 text-right font-mono font-semibold">{{ $row->total_points }}</td>
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
    @endif
@endsection
