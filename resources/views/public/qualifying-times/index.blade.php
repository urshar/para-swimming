{{--
    public/qualifying-times/index — Startberechtigung ÖSTM & ÖM (Spec public-frontend §5, Phase 7).

    Zeigt die Qualifikationen (Snapshot, siehe Qualification-Model) der aktuell aktiven
    Richtzeitenliste (QualifyingTimeList::is_active) — siehe Public\QualifyingTimeController.
    Server-seitiges Filterformular wie bei den Rekorden (Phase 5), bewusst ohne Namenssuche (§2.3
    Punkt 3 — siehe PublicQualificationFilter). Athletennamen sind unverlinkter Text (§2.3 Regel 2).

    Gliederung wie im internen Bereich: Behinderungsgruppe (PI/VI/MI/HI/T21) → Bewerb
    (DisabilityGroupGrouper, aus QualifyingTimeListController extrahiert). Zusätzlicher
    Behinderungsgruppe-Filter (Rückmeldung: "wenn alle Sportklassen gewählt ist, dass man sich nur
    die Sportklassengruppen ebenfalls ansehen kann, so wie bei der Jahresbestleistung die Klasse")
    wirkt unabhängig von der einzelnen Sportklasse — beide zusammen ergäben schlicht keine Treffer,
    wenn sie sich widersprechen.

    "Filtern"-Button wirkte verrutscht (Rückmeldung, mehrfach): items-end auf dem Filter-<form>
    richtet jede Spalte an ihrer eigenen Unterkante aus, aber der Button hat — anders als die
    Label+Auswahlfeld-Spalten daneben — kein Label über sich, seine Spalte ist also insgesamt
    niedriger. items-center (erster Versuch) zentriert zwar alle Spalten zueinander, aber bezogen
    auf die GESAMTE Spaltenhöhe inklusive des für den Button unsichtbaren Platzes, den ein Label
    einnehmen würde — der Button landet dadurch optisch oberhalb der Auswahlfelder statt auf
    gleicher Höhe. Zurück auf items-end, dafür eine unsichtbare Label-Attrappe (aria-hidden,
    .invisible) über dem Button, exakt wie ein echtes Label groß — seine Spalte hat dadurch
    dieselbe Struktur/Höhe wie die übrigen. Blieb trotzdem unterschiedlich groß (kein border, kein
    leading-6 auf dem Button) — border border-transparent + leading-6 ergänzt, jetzt exakt
    dieselbe Box-Höhe wie die Auswahlfelder. Bleibt am rechten Rand der Zeile, nach Verein (kurz an
    den Anfang verschoben, dann per Rückmeldung "ich habe mich mit dem Button vertan" wieder ans
    Ende — ursprüngliche Position). "Rechte Seite" hieß zunächst nur "letztes Element in der
    Flex-Reihe" — bei viel Platz in der Zeile (z. B. breiter Viewport, wenige Filteroptionen) blieb
    dadurch sichtbarer Leerraum bis zum tatsächlichen rechten Rand (Rückmeldung mit Pfeil auf genau
    diese Lücke). ml-auto auf der Button-Spalte schiebt sie stattdessen bis an den rechten Rand des
    Formulars, unabhängig davon, wie viel Platz die übrigen Felder einnehmen. Die beiden versteckten
    Felder (stroke_type_id/distance) stehen
    als direkte Formular-Kinder statt verschachtelt im Bewerb-Block, damit dessen Struktur exakt
    den übrigen Feld-Blöcken entspricht.
--}}
@php use App\Models\Club;use App\Models\Qualification;use App\Models\QualifyingTime;use App\Models\QualifyingTimeList;use App\Models\SportClassGroup;use App\Support\PublicQualificationFilter;use Illuminate\Support\Collection; @endphp
@extends('layouts.public')

@php
    /**
     * @var ?QualifyingTimeList $list
     * @var PublicQualificationFilter $filter
     * @var Collection<int, array{stroke_type_id: int, distance: int, label: string}> $events
     * @var Collection<int, string> $genders
     * @var Collection<int, string> $sportClasses
     * @var Collection<int, SportClassGroup> $sportClassGroups
     * @var Collection<int, Club> $clubs
     * @var Collection<int, array{group: ?SportClassGroup, strokes: Collection}> $sections
     * @var Collection<string, Collection<int, QualifyingTime>> $referenceTimes
     */
@endphp

@section('title', __('public.qualifying_times.title'))
@section('robots', 'noindex, nofollow')

@section('content')
    <h1 class="text-2xl font-semibold">{{ __('public.qualifying_times.heading') }}</h1>
    <p class="mt-2 max-w-2xl text-sm text-gray-600 dark:text-gray-400">{{ __('public.qualifying_times.intro') }}</p>

    @if (! $list)
        <p class="mt-8 text-sm text-gray-500 dark:text-gray-400">{{ __('public.qualifying_times.empty') }}</p>
    @else
        <p class="mt-1 text-sm font-medium text-gray-700 dark:text-gray-300">
            {{ __('public.qualifying_times.list_label') }}: {{ $list->year }}
        </p>

        <form method="GET" class="mt-6 flex flex-wrap items-end gap-4"
              aria-label="{{ __('public.qualifying_times.filter.heading') }}">
            <div class="space-y-1">
                <label for="stroke_type_id_distance"
                       class="inline-block text-sm font-medium">{{ __('public.qualifying_times.filter.discipline') }}</label>
                {{-- Zwei tatsächlich versendete Felder (stroke_type_id/distance, als hidden-Inputs
                     am Formularende) hinter dieser einzigen sichtbaren Auswahl, wie im internen
                     Pendant — die Kombination braucht JS zum Aufsplitten vor dem Absenden; ohne JS
                     bleibt dieses eine Feld wirkungslos, die übrigen Filter funktionieren weiterhin
                     über den Submit-Button. --}}
                <div class="relative">
                    <select id="stroke_type_id_distance" name="stroke_type_id_distance" onchange="
                        const [s, d] = this.value.split('|');
                        this.form.stroke_type_id.value = s ?? '';
                        this.form.distance.value = d ?? '';
                        this.form.submit();
                    "
                            class="block w-48 appearance-none rounded-lg border border-gray-200 py-2 pr-10 pl-3 text-sm leading-6 dark:border-gray-600 dark:bg-gray-800">
                        <option
                            value="" @selected(! $filter->strokeTypeId)>{{ __('public.qualifying_times.filter.discipline_all') }}</option>
                        @foreach ($events as $event)
                            <option value="{{ $event['stroke_type_id'] }}|{{ $event['distance'] }}"
                                @selected($filter->strokeTypeId === $event['stroke_type_id'] && $filter->distance === $event['distance'])>
                                {{ $event['label'] }}
                            </option>
                        @endforeach
                    </select>
                    <x-select-chevron/>
                </div>
            </div>

            <div class="space-y-1">
                <label for="gender"
                       class="inline-block text-sm font-medium">{{ __('public.qualifying_times.filter.gender') }}</label>
                <div class="relative">
                    <select id="gender" name="gender"
                            class="block w-32 appearance-none rounded-lg border border-gray-200 py-2 pr-10 pl-3 text-sm leading-6 dark:border-gray-600 dark:bg-gray-800">
                        <option
                            value="" @selected($filter->gender === '')>{{ __('public.qualifying_times.filter.gender_all') }}</option>
                        @foreach ($genders as $gender)
                            <option
                                value="{{ $gender }}" @selected($filter->gender === $gender)>{{ __('public.qualifying_times.gender.'.$gender) }}</option>
                        @endforeach
                    </select>
                    <x-select-chevron/>
                </div>
            </div>

            <div class="space-y-1">
                <label for="sport_class"
                       class="inline-block text-sm font-medium">{{ __('public.qualifying_times.filter.sport_class') }}</label>
                <div class="relative">
                    <select id="sport_class" name="sport_class"
                            class="block w-32 appearance-none rounded-lg border border-gray-200 py-2 pr-10 pl-3 text-sm leading-6 dark:border-gray-600 dark:bg-gray-800">
                        <option
                            value="" @selected($filter->sportClass === '')>{{ __('public.qualifying_times.filter.sport_class_all') }}</option>
                        @foreach ($sportClasses as $sportClass)
                            <option
                                value="{{ $sportClass }}" @selected($filter->sportClass === $sportClass)>{{ $sportClass }}</option>
                        @endforeach
                    </select>
                    <x-select-chevron/>
                </div>
            </div>

            {{-- Zusätzlich zur einzelnen Sportklasse: nach Behinderungsgruppe eingrenzen, ohne
                 jede einzelne Klasse der Gruppe durchklicken zu müssen (Rückmeldung). --}}
            <div class="space-y-1">
                <label for="sport_class_group_id"
                       class="inline-block text-sm font-medium">{{ __('public.qualifying_times.filter.sport_class_group') }}</label>
                <div class="relative">
                    <select id="sport_class_group_id" name="sport_class_group_id"
                            class="block w-40 appearance-none rounded-lg border border-gray-200 py-2 pr-10 pl-3 text-sm leading-6 dark:border-gray-600 dark:bg-gray-800">
                        <option
                            value="" @selected(! $filter->sportClassGroupId)>{{ __('public.qualifying_times.filter.sport_class_group_all') }}</option>
                        @foreach ($sportClassGroups as $group)
                            <option
                                value="{{ $group->id }}" @selected($filter->sportClassGroupId === $group->id)>{{ $group->name_de }}</option>
                        @endforeach
                    </select>
                    <x-select-chevron/>
                </div>
            </div>

            <div class="space-y-1">
                <label for="club_id"
                       class="inline-block text-sm font-medium">{{ __('public.qualifying_times.filter.club') }}</label>
                <div class="relative">
                    <select id="club_id" name="club_id"
                            class="block w-56 appearance-none rounded-lg border border-gray-200 py-2 pr-10 pl-3 text-sm leading-6 dark:border-gray-600 dark:bg-gray-800">
                        <option
                            value="" @selected(! $filter->clubId)>{{ __('public.qualifying_times.filter.club_all') }}</option>
                        @foreach ($clubs as $club)
                            <option
                                value="{{ $club->id }}" @selected($filter->clubId === $club->id)>{{ $club->display_name }}</option>
                        @endforeach
                    </select>
                    <x-select-chevron/>
                </div>
            </div>

            {{-- Button an den rechten Rand der Zeile (Rückmeldung, mehrfach) — ml-auto statt
                 einfach nur "letztes Element", da flex-wrap die Felder sonst links bündig direkt
                 nach Verein packt, mit sichtbarem Leerraum bis zum rechten Rand. --}}
            <div class="ml-auto space-y-1">
                {{-- Unsichtbare Label-Attrappe in derselben Größe wie die echten Labels daneben —
                     ohne sie ist diese Spalte niedriger als die Auswahlfeld-Spalten (kein Label
                     über dem Button) und items-end richtet den Button dadurch sichtbar zu hoch aus
                     (Rückmeldung: "schaut aus, als wäre er verrutscht"). --}}
                <span class="invisible inline-block text-sm font-medium" aria-hidden="true">&nbsp;</span>
                {{-- border border-transparent + leading-6: exakt dieselbe Box-Höhe wie die
                     Auswahlfelder (die einen sichtbaren border + leading-6 haben) — ohne das war
                     der Button spürbar größer/kleiner als die Auswahlfelder daneben (Rückmeldung:
                     "vertikale Zentrierung mit dem Input Feld, oder mach den Button gleich groß"). --}}
                <button type="submit"
                        class="inline-flex items-center rounded-lg border border-transparent bg-blue-600 px-4 py-2 text-sm leading-6 font-semibold text-white hover:bg-blue-500">
                    {{ __('public.qualifying_times.filter.submit') }}
                </button>
            </div>

            <input type="hidden" name="stroke_type_id" value="{{ $filter->strokeTypeId }}">
            <input type="hidden" name="distance" value="{{ $filter->distance }}">
        </form>

        @if ($sections->isEmpty())
            <p class="mt-8 text-sm text-gray-500 dark:text-gray-400">{{ __('public.qualifying_times.empty_results') }}</p>
        @else
            <div class="mt-8 flex flex-col gap-10">
                @foreach ($sections as $section)
                    <section>
                        <h2 class="mb-3 text-lg font-semibold">{{ $section['group']?->name_de ?? '—' }}</h2>

                        <div class="flex flex-col gap-6">
                            @foreach ($section['strokes'] as $strokeGroup)
                                @php
                                    $strokeName = app()->getLocale() === 'de'
                                        ? $strokeGroup['stroke']?->name_de
                                        : ($strokeGroup['stroke']?->name_en ?? $strokeGroup['stroke']?->name_de);
                                    $heading = $strokeGroup['distance'].'m '.($strokeName ?? '—');
                                    // Richtzeit(en) der gefilterten Sportklasse für genau diesen Bewerb
                                    // (Rückmeldung: "beim Filtern der Sportklassen müsste hier die
                                    // Richtzeit der Sportklasse hin") — nur vorhanden, wenn oben nach
                                    // Sportklasse gefiltert wurde, siehe QualifyingTimeController.
                                    $referenceTimesHere = $referenceTimes->get("{$strokeGroup['stroke']?->id}-{$strokeGroup['distance']}", collect());
                                @endphp
                                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700"
                                     tabindex="0"
                                     aria-label="{{ $heading }}">
                                    <table class="min-w-full text-sm">
                                        <caption class="p-3 text-left text-sm">
                                            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                                                <span class="font-semibold">{{ $heading }}</span>
                                                @if ($referenceTimesHere->isNotEmpty())
                                                    <span class="text-xs font-normal text-gray-500 dark:text-gray-400">
                                                        {{ __('public.qualifying_times.reference_time') }}:
                                                        @foreach ($referenceTimesHere as $referenceTime)
                                                            {{ __('public.qualifying_times.gender.'.$referenceTime->gender) }} {{ $referenceTime->formatted_value }}@if (! $loop->last), @endif
                                                        @endforeach
                                                    </span>
                                                @endif
                                            </div>
                                        </caption>
                                        <thead>
                                        <tr>
                                            <th scope="col"
                                                class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                                {{ __('public.qualifying_times.columns.athlete') }}
                                            </th>
                                            <th scope="col"
                                                class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                                {{ __('public.qualifying_times.columns.club') }}
                                            </th>
                                            <th scope="col"
                                                class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                                {{ __('public.qualifying_times.columns.sport_class') }}
                                            </th>
                                            <th scope="col"
                                                class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                                {{ __('public.qualifying_times.columns.time') }}
                                            </th>
                                            <th scope="col"
                                                class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                                {{ __('public.qualifying_times.columns.points') }}
                                            </th>
                                            <th scope="col"
                                                class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                                {{ __('public.qualifying_times.columns.date') }}
                                            </th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @foreach ($strokeGroup['items'] as $qualification)
                                            <tr class="even:bg-gray-50 dark:even:bg-gray-900/50">
                                                <td class="p-3">{{ $qualification->athlete?->last_name }}, {{ $qualification->athlete?->first_name }}</td>
                                                <td class="p-3">{{ $qualification->club?->display_name ?? '—' }}</td>
                                                <td class="p-3 font-medium whitespace-nowrap">{{ $qualification->sport_class }}</td>
                                                <td class="p-3 whitespace-nowrap">{{ $qualification->formatted_swim_time }}</td>
                                                <td class="p-3 whitespace-nowrap">{{ $qualification->points ?? '—' }}</td>
                                                <td class="p-3 whitespace-nowrap">{{ $qualification->qualified_at?->format('d.m.Y') }}</td>
                                            </tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
        @endif
    @endif
@endsection
