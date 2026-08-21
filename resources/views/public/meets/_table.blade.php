{{--
    Geteilte Veranstaltungstabelle für index und archive (Spec public-frontend §5.1, Spalten:
    Datum, Name, Ort, Meldeschluss, Dokumente). Angelehnt an Tailkit a-c-tables-08 "With Heading
    and Search" (docs/snippets/data-table-with-filter.html), ohne die Suchleiste — die braucht
    es hier nicht, siehe §5.1-Planung: kommend/vergangen sind gezählte Kurzlisten, das Archiv
    ist nach Jahr gruppiert statt durchsucht.

    Scrollbarer Container mit tabindex="0" nach accessibility.md, für den Fall, dass die Tabelle
    auf schmalen Bildschirmen breiter als der Viewport wird.
--}}
@php use App\Support\MeetDocumentGroup; @endphp
<div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700" tabindex="0"
     aria-label="{{ __('public.meets.index.title') }}">
    <table class="min-w-full text-sm">
        <caption class="sr-only">{{ __('public.meets.index.title') }}</caption>
        <thead>
            <tr>
                <th scope="col" class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                    {{ __('public.meets.columns.date') }}
                </th>
                <th scope="col" class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                    {{ __('public.meets.columns.name') }}
                </th>
                <th scope="col" class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                    {{ __('public.meets.columns.city') }}
                </th>
                <th scope="col" class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                    {{ __('public.meets.columns.entries_deadline') }}
                </th>
                <th scope="col" class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">
                    {{ __('public.meets.columns.documents') }}
                </th>
            </tr>
        </thead>
        <tbody>
            @foreach ($meets as $meet)
                <tr class="even:bg-gray-50 dark:even:bg-gray-900/50">
                    <td class="p-3 whitespace-nowrap">{{ $meet->date_range }}</td>
                    <td class="p-3">
                        <a href="{{ route('public.meets.show', ['locale' => app()->getLocale(), 'meet' => $meet]) }}"
                           class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400">
                            {{ $meet->name }}
                        </a>
                    </td>
                    <td class="p-3">
                        <span class="inline-flex items-center gap-2">
                            <x-flag code="{{ $meet->nation?->code }}"
                                    label="{{ app()->getLocale() === 'de' ? $meet->nation?->name_de : $meet->nation?->name_en }}"/>
                            {{ $meet->city }}
                        </span>
                    </td>
                    <td class="p-3 whitespace-nowrap">
                        @if ($meet->hasDeadline())
                            {{ $meet->entries_deadline->format('d.m.Y') }}
                        @else
                            <span class="text-gray-400 dark:text-gray-500">—</span>
                        @endif
                    </td>
                    <td class="p-3">
                        @php($rowDocuments = MeetDocumentGroup::forMeet($meet, app()->getLocale()))
                        @if ($rowDocuments->isEmpty())
                            <span class="text-gray-400 dark:text-gray-500">—</span>
                        @else
                            <ul class="flex flex-col gap-1">
                                @foreach ($rowDocuments as $group)
                                    <li>
                                        <a href="{{ route('public.documents.download', ['locale' => app()->getLocale(), 'document' => $group->document]) }}"
                                           class="text-blue-600 hover:text-blue-500 dark:text-blue-400">
                                            {{ __('public.documents.category.'.$group->document->category) }}
                                            @if ($group->document->formatLabel() || $group->document->sizeLabel())
                                                ({{ collect([$group->document->formatLabel(), $group->document->sizeLabel()])->filter()->implode(', ') }})
                                            @endif
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
