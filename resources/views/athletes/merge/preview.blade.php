@extends('layouts.app')

@section('title', 'Athleten zusammenführen')

@section('content')
    @php
        $keepName = "#$keep->id $keep->display_name";
        $dropName = "#$drop->id $drop->display_name";
        $optionLabels = ['keep' => 'bleibt', 'drop' => 'aufgelöst', 'both' => 'beide'];
        $confirmMessage = "$dropName wirklich in $keepName übernehmen? $dropName wird danach endgültig gelöscht.";
        $isBlocked = $blocking !== [];
    @endphp

    <div class="max-w-5xl">
        <div class="mb-6">
            <div class="flex items-center gap-2">
                <flux:button href="{{ route('athletes.show', $keep) }}" variant="primary" icon="arrow-left"
                             size="sm" title="Zurück" aria-label="Zurück"/>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">Athleten zusammenführen</h1>
            </div>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                Alles, was am aufgelösten Athleten hängt, wird übernommen. Danach wird er endgültig gelöscht.
            </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-5">
                <p class="text-sm text-zinc-500 dark:text-zinc-400">Bleibt</p>
                <a href="{{ route('athletes.show', $keep) }}"
                   class="font-semibold text-zinc-900 dark:text-zinc-100 hover:text-blue-600">{{ $keepName }}</a>
            </div>
            <div class="bg-white dark:bg-zinc-800 rounded-xl border border-red-200 dark:border-red-900 p-5">
                <p class="text-sm text-red-600 dark:text-red-400">Wird aufgelöst und endgültig gelöscht</p>
                <a href="{{ route('athletes.show', $drop) }}"
                   class="font-semibold text-zinc-900 dark:text-zinc-100 hover:text-blue-600">{{ $dropName }}</a>
            </div>
        </div>
        <div class="flex justify-end mb-6">
            <flux:button href="{{ route('athletes.merge.preview', [$drop, $keep]) }}" variant="filled"
                         icon="arrows-right-left" size="sm">
                Andersherum: {{ $dropName }} behalten
            </flux:button>
        </div>

        @if($isBlocked)
            <flux:callout variant="danger" icon="x-circle" class="mb-6" heading="Zusammenführen nicht möglich">
                <flux:callout.text>
                    Erst von Hand bereinigen, dann diese Seite neu laden:
                    <ul class="list-disc ps-5 mt-1">
                        @foreach($blocking as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </flux:callout.text>
            </flux:callout>
        @endif

        @if($hints !== [])
            <flux:callout variant="warning" icon="exclamation-triangle" class="mb-6" heading="Hinweise">
                <flux:callout.text>
                    <ul class="list-disc ps-5">
                        @foreach($hints as $hint)
                            <li>{{ $hint }}</li>
                        @endforeach
                    </ul>
                </flux:callout.text>
            </flux:callout>
        @endif

        <form method="POST" action="{{ route('athletes.merge.store', [$keep, $drop]) }}"
              data-confirm="{{ $confirmMessage }}" onsubmit="return confirm(this.dataset.confirm)">
            @csrf

            {{-- Stammdaten --}}
            <section class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-5 mb-6"
                     aria-labelledby="merge-fields-heading">
                <h2 id="merge-fields-heading" class="font-semibold text-zinc-900 dark:text-zinc-100 mb-1">Stammdaten</h2>
                <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-4">
                    {{ $identicalFieldCount }} Felder stimmen überein.
                    @if($fields !== [])
                        Für die abweichenden Felder den Wert wählen, der bleiben soll.
                    @endif
                    @if($clubFromHistory)
                        Der Verein ergibt sich aus der Vereins-History (jüngster offener Eintrag).
                    @endif
                </p>
                @if($fields !== [])
                    <table class="w-full text-sm">
                        <thead>
                        <tr class="text-left text-zinc-500 dark:text-zinc-400 border-b border-zinc-200 dark:border-zinc-700">
                            <th scope="col" class="py-2 pe-4 font-medium">Feld</th>
                            <th scope="col" class="py-2 pe-4 font-medium">Bleibt (#{{ $keep->id }})</th>
                            <th scope="col" class="py-2 pe-4 font-medium">Aufgelöst (#{{ $drop->id }})</th>
                            <th scope="col" class="py-2 font-medium"><span class="sr-only">Weitere Auswahl</span></th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($fields as $field => $row)
                            @php
                                // Kein einzeiliges @php(...): Das Komma in old() verschluckt sonst Folgezeilen (CLAUDE.md).
                                $selected = old("fields.$field", $row['suggestion']);
                            @endphp
                            <tr class="border-b border-zinc-100 dark:border-zinc-700/50 align-top">
                                <th scope="row" class="py-2 pe-4 text-left font-medium text-zinc-700 dark:text-zinc-300">
                                    {{ $row['label'] }}
                                </th>
                                @foreach(['keep', 'drop', 'both'] as $option)
                                    <td class="py-2 pe-4 text-slate-700 dark:text-slate-300">
                                        @if(in_array($option, $row['options'], true))
                                            <label class="inline-flex items-start gap-2 cursor-pointer">
                                                <input type="radio" name="fields[{{ $field }}]" value="{{ $option }}"
                                                       class="accent-blue-600 mt-1" @checked($selected === $option)>
                                                <span>
                                                    <span class="sr-only">{{ $row['label'] }}, {{ $optionLabels[$option] }}:</span>
                                                    @if($option === 'both')
                                                        beide übernehmen
                                                    @else
                                                        <span class="whitespace-pre-line">{{ $row[$option] }}</span>
                                                    @endif
                                                </span>
                                            </label>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    <flux:error name="fields"/>
                @endif
            </section>

            {{-- Sportklassen --}}
            @if($sportClasses !== [])
                <section class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-5 mb-6"
                         aria-labelledby="merge-classes-heading">
                    <h2 id="merge-classes-heading" class="font-semibold text-zinc-900 dark:text-zinc-100 mb-1">Sportklassen</h2>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-4">
                        Beide haben in diesen Kategorien eine abweichende Sportklasse. Bitte jeweils wählen.
                    </p>
                    @foreach($sportClasses as $category => $classes)
                        <fieldset class="mb-3">
                            <legend class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                                Kategorie {{ $category }}
                            </legend>
                            <div class="flex flex-wrap gap-6 text-sm text-slate-700 dark:text-slate-300">
                                @foreach(['keep', 'drop'] as $option)
                                    <label class="inline-flex items-center gap-2 cursor-pointer">
                                        <input type="radio" name="sport_classes[{{ $category }}]" value="{{ $option }}"
                                               class="accent-blue-600"
                                               @checked(old("sport_classes.$category") === $option)>
                                        {{ $classes[$option] }} ({{ $optionLabels[$option] }})
                                    </label>
                                @endforeach
                            </div>
                            <flux:error name="sport_classes.{{ $category }}"/>
                        </fieldset>
                    @endforeach
                </section>
            @endif

            {{-- Daten je Tabelle --}}
            <section class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-5 mb-6"
                     aria-labelledby="merge-counts-heading">
                <h2 id="merge-counts-heading" class="font-semibold text-zinc-900 dark:text-zinc-100 mb-4">Daten</h2>
                @if($counts->isEmpty())
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">An keinem der beiden Athleten hängen Daten.</p>
                @else
                    <table class="w-full text-sm">
                        <thead>
                        <tr class="text-left text-zinc-500 dark:text-zinc-400 border-b border-zinc-200 dark:border-zinc-700">
                            <th scope="col" class="py-2 pe-4 font-medium">Daten</th>
                            <th scope="col" class="py-2 pe-4 font-medium text-right">Beim behaltenen</th>
                            <th scope="col" class="py-2 font-medium text-right">Wird übernommen</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($counts as $count)
                            <tr class="border-b border-zinc-100 dark:border-zinc-700/50 text-slate-700 dark:text-slate-300">
                                <th scope="row" class="py-2 pe-4 text-left font-normal">{{ $count['label'] }}</th>
                                <td class="py-2 pe-4 text-right tabular-nums">{{ $count['keep'] }}</td>
                                <td class="py-2 text-right tabular-nums">{{ $count['drop'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-2">
                        Exakte Duplikate (z. B. gleiche Vereins- oder Klassifikationseinträge) entfallen beim Übernehmen.
                    </p>
                @endif
            </section>

            {{-- Neuberechnung --}}
            <section class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-5 mb-6"
                     aria-labelledby="merge-recalc-heading">
                <h2 id="merge-recalc-heading" class="font-semibold text-zinc-900 dark:text-zinc-100 mb-2">Neuberechnung</h2>
                @if($cups->isEmpty() && $qualifyingTimeLists->isEmpty())
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Keine Cup-Wertung und keine Qualifikationen betroffen.</p>
                @else
                    <ul class="list-disc ps-5 text-sm text-slate-700 dark:text-slate-300 mb-3">
                        @foreach($cups as $cup)
                            <li>{{ $cup->name }} (bereits berechnete Stufen)</li>
                        @endforeach
                        @foreach($qualifyingTimeLists as $list)
                            <li>
                                Qualifikationen der Richtzeitenliste {{ $list->year }}
                                @if(! $list->qualification_period_end)
                                    <span class="text-amber-600 dark:text-amber-400">(Zeitraum offen – wird bis heute gerechnet)</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    <flux:checkbox name="recalculate" value="1" :checked="(bool) old('recalculate', true)"
                                   label="Nach dem Zusammenführen neu berechnen"/>
                @endif
            </section>

            <div class="flex gap-3">
                <flux:button type="submit" variant="danger" icon="arrows-pointing-in" :disabled="$isBlocked">
                    Zusammenführen
                </flux:button>
                <flux:button href="{{ route('athletes.show', $keep) }}" variant="ghost">Abbrechen</flux:button>
            </div>
        </form>
    </div>
@endsection
