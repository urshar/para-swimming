{{--
    Rekordbezeichnungen eines Einzel- oder Staffelergebnisses ($result). Ausgeschriebene Bezeichnung statt
    Kürzel-Badge mit title=: title= wird von Screenreadern nicht zuverlässig vorgelesen (wie beim Flaggen-Fix,
    siehe components/flag.blade.php).
--}}
@if ($result->hasRecords())
    <ul class="flex flex-col gap-0.5 text-xs font-semibold text-gray-700 dark:text-gray-300">
        @if ($result->is_world_record)
            <li>{{ __('public.meets.results.records.world') }}</li>
        @endif
        @if ($result->is_european_record)
            <li>{{ __('public.meets.results.records.european') }}</li>
        @endif
        @if ($result->is_national_record)
            <li>{{ __('public.meets.results.records.national') }}</li>
        @endif
        @if ($result->is_junior_record)
            <li>{{ __('public.meets.results.records.junior') }}</li>
        @endif
        @if ($result->is_regional_record)
            <li>{{ __('public.meets.results.records.regional') }}</li>
        @endif
        @if ($result->is_regional_junior_record)
            <li>{{ __('public.meets.results.records.regional_junior') }}</li>
        @endif
    </ul>
@else
    <span class="text-gray-400 dark:text-gray-500">—</span>
@endif
