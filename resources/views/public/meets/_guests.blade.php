{{--
    Gäste eines Bewerbs (Vereine außerhalb Österreichs, foreign_starts) — ohne Platz und ohne Wertung, unverlinkt.
    Gleiche Spalten wie die Ergebnistabellen der Seite, damit alles untereinander ausgerichtet bleibt.
    Erwartet: $group (Bewerb samt guests), $hasPoints, $hasWpsPoints.
--}}
<div class="mb-6 last:mb-0">
    <h4 class="mb-2 text-sm font-semibold text-gray-600 dark:text-gray-400">{{ __('public.meets.results.guests_heading') }}</h4>

    <div class="overflow-x-auto rounded-lg border border-gray-300 dark:border-gray-700"
         tabindex="0"
         aria-label="{{ $group->event->display_name }} – {{ __('public.meets.results.guests_heading') }}">
        <table class="w-full {{ $group->isRelay ? 'min-w-248' : 'min-w-280' }} table-fixed text-sm">
            <caption class="sr-only">{{ $group->event->display_name }} – {{ __('public.meets.results.guests_heading') }}</caption>
            <thead>
            <tr>
                <th scope="col"
                    class="w-16 bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">{{ __('public.meets.results.columns.place') }}</th>
                @if ($group->isRelay)
                    <th scope="col"
                        class="w-48 bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">{{ __('public.meets.results.columns.relay') }}</th>
                    <th scope="col"
                        class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">{{ __('public.meets.results.columns.swimmers') }}</th>
                    <th scope="col"
                        class="w-32 bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">{{ __('public.meets.results.columns.relay_class') }}</th>
                @else
                    <th scope="col"
                        class="bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">{{ __('public.meets.results.columns.name') }}</th>
                    <th scope="col"
                        class="w-48 bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">{{ __('public.meets.results.columns.club') }}</th>
                    <th scope="col"
                        class="w-24 bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">{{ __('public.meets.results.columns.birth_year') }}</th>
                    <th scope="col"
                        class="w-28 bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">{{ __('public.meets.results.columns.sport_class') }}</th>
                @endif
                <th scope="col"
                    class="w-32 bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">{{ __('public.meets.results.columns.time') }}</th>
                @if ($hasPoints)
                    <th scope="col"
                        class="w-20 bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">{{ __('public.meets.results.columns.points') }}</th>
                @endif
                @if ($hasWpsPoints)
                    <th scope="col"
                        class="w-28 bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">{{ __('public.meets.results.columns.wps_points') }}</th>
                @endif
                <th scope="col"
                    class="w-44 bg-gray-100/75 px-3 py-3 text-left font-semibold text-gray-900 dark:bg-gray-700/25 dark:text-gray-50">{{ __('public.meets.results.columns.record') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($group->guests as $guest)
                <tr class="even:bg-gray-50 dark:even:bg-gray-900/50">
                    <td class="p-3 whitespace-nowrap text-gray-500 dark:text-gray-400">{{ __('public.meets.results.guest') }}</td>
                    @if ($group->isRelay)
                        <td class="p-3">{{ $guest->club_label }}</td>
                        <td class="p-3">—</td>
                        <td class="p-3">{{ $guest->sport_class ?? '—' }}</td>
                    @else
                        <td class="p-3">{{ $guest->display_name }}</td>
                        <td class="p-3">{{ $guest->club_label }}</td>
                        <td class="p-3 whitespace-nowrap">{{ $guest->birth_date?->year }}</td>
                        <td class="p-3">{{ $guest->sport_class ?? '—' }}</td>
                    @endif
                    <td class="p-3 whitespace-nowrap">
                        @if ($guest->swim_time)
                            {{ $guest->formatted_swim_time }}
                        @elseif ($guest->status)
                            {{ __('public.meets.results.status.'.$guest->status) }}
                        @else
                            —
                        @endif
                    </td>
                    @if ($hasPoints)
                        <td class="p-3">—</td>
                    @endif
                    @if ($hasWpsPoints)
                        <td class="p-3">—</td>
                    @endif
                    <td class="p-3">—</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
