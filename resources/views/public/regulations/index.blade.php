{{--
    public/regulations/index — Reglemente & Formulare (Spec public-frontend §5.5, Phase 8).

    Dokumente ganz ohne Veranstaltungsbezug (RegulationController), gruppiert nach Kategorie
    (REGULATION/FORM) — kein Filter, keine Suche: eine Handvoll Dokumente ist als schlichte,
    zweigeteilte Liste übersichtlicher als eine Tabelle mit Steuerelementen (Planungsentscheidung
    Phase 8, ähnlich der Punktetabelle in Phase 6). Sprachauflösung/Linktext-Aufbau (Kategorie:
    Titel (Format, Größe), Verlinkung der anderen Sprachfassung) exakt wie schon bei den
    Veranstaltungsdokumenten (public/meets/show), da beide dasselbe App\Support\DocumentLocaleGroup
    nutzen.

    Als Tabelle statt Liste (Rückmeldung: "eventuell einen Rahmen herum wie bei den Tabellen" +
    "fehlt, ob das Dokument in Englisch oder Deutsch ist") — dieselbe Rahmen-/Kopfzeilen-Klasse
    wie z. B. public/qualifying-times/index, mit eigener Sprache-Spalte statt der Sprachfassung
    nur indirekt über den "auch verfügbar auf"-Link neben dem Titel erkennbar zu machen.

    Reglemente und Formulare stehen in zwei getrennten <table>-Elementen (je Kategorie eine
    Tabelle) — ohne feste Spaltenbreiten berechnet jede Tabelle ihre Spaltenbreiten unabhängig
    aus dem eigenen Zeileninhalt, wodurch "Sprache" in beiden Tabellen an unterschiedlicher
    Position landet, sobald sich die Titellängen unterscheiden (Rückmeldung mit Screenshot:
    "Sprache"-Spalte verspringt zwischen den beiden Tabellen). table-fixed mit denselben festen
    Breiten für Sprache/Veröffentlicht-am in beiden Tabellen behebt das, ohne die beiden
    Kategorien in eine einzige, mit Kategorie-Spalte versehene Tabelle zusammenlegen zu müssen.
--}}
@php use App\Support\DocumentLocaleGroup;use Illuminate\Support\Collection; @endphp
@extends('layouts.public')

@php
    /**
     * @var Collection<int, array{category: string, groups: Collection<int, DocumentLocaleGroup>}> $sections
     */
@endphp

@section('title', __('public.regulations.title'))

@section('content')
    <h1 class="text-2xl font-semibold">{{ __('public.regulations.heading') }}</h1>
    <p class="mt-2 max-w-2xl text-sm text-gray-600 dark:text-gray-400">{{ __('public.regulations.intro') }}</p>

    @if ($sections->isEmpty())
        <p class="mt-8 text-sm text-gray-500 dark:text-gray-400">{{ __('public.regulations.empty') }}</p>
    @else
        <div class="mt-8 flex flex-col gap-10">
            @foreach ($sections as $section)
                <section>
                    <h2 class="mb-4 text-lg font-semibold">{{ __('public.documents.category.'.$section['category']) }}</h2>

                    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700" tabindex="0"
                         aria-label="{{ __('public.documents.category.'.$section['category']) }}">
                        <table class="min-w-full table-fixed text-sm">
                            <caption class="sr-only">{{ __('public.documents.category.'.$section['category']) }}</caption>
                            <thead>
                            <tr>
                                <th scope="col"
                                    class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                    {{ __('public.regulations.columns.title') }}
                                </th>
                                <th scope="col"
                                    class="w-32 bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                    {{ __('public.regulations.columns.language') }}
                                </th>
                                <th scope="col"
                                    class="w-40 bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                                    {{ __('public.regulations.columns.published_at') }}
                                </th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($section['groups'] as $group)
                                <tr class="even:bg-gray-50 dark:even:bg-gray-900/50">
                                    <td class="p-3">
                                        <a href="{{ route('public.documents.download', ['locale' => app()->getLocale(), 'document' => $group->document]) }}"
                                           class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400">
                                            {{ $group->document->title }}
                                            @if ($group->document->formatLabel() || $group->document->sizeLabel())
                                                ({{ collect([$group->document->formatLabel(), $group->document->sizeLabel()])->filter()->implode(', ') }})
                                            @endif
                                        </a>
                                        @if ($group->alternate?->locale)
                                            <span class="ml-2 text-sm text-gray-500 dark:text-gray-400">
                                                {{ __('public.regulations.also_available_in') }}
                                                <a href="{{ route('public.documents.download', ['locale' => app()->getLocale(), 'document' => $group->alternate]) }}"
                                                   class="text-blue-600 hover:text-blue-500 dark:text-blue-400">
                                                    {{ __('public.languages.'.$group->alternate->locale) }}
                                                </a>
                                            </span>
                                        @endif
                                    </td>
                                    <td class="p-3 whitespace-nowrap">
                                        {{ $group->document->locale ? __('public.languages.'.$group->document->locale) : __('public.regulations.language_neutral') }}
                                    </td>
                                    <td class="p-3 whitespace-nowrap">
                                        {{ $group->document->published_at?->format('d.m.Y') ?? '—' }}
                                    </td>
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
