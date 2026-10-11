{{--
    Gäste eines Bewerbs (Vereine außerhalb Österreichs, foreign_starts) — ohne Platz und Wertung, ohne Athletenseite.
    Gleiche Spaltenbreiten wie die Einzeltabelle der Ergebnisübersicht, damit alles untereinander ausgerichtet bleibt.
    Erwartet: $guests, $pointColumns.
--}}
<div class="px-4 pt-3 flex items-center gap-2">
    <h3 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Gäste</h3>
    <flux:badge size="sm" color="zinc">ohne Wertung</flux:badge>
</div>
<div class="p-4 [--flux-bleed:1rem]">
    <flux:table bleed class="w-full min-w-260">
        <flux:table.columns>
            <flux:table.column class="w-14">Platz</flux:table.column>
            <flux:table.column>Athlet</flux:table.column>
            <flux:table.column class="w-44">Verein</flux:table.column>
            <flux:table.column class="w-20">Klasse</flux:table.column>
            <flux:table.column class="w-24">Zeit</flux:table.column>
            @if($pointColumns['points'])
                <flux:table.column class="w-20">Punkte</flux:table.column>
            @endif
            @if($pointColumns['wps'])
                <flux:table.column class="w-20">WPS</flux:table.column>
            @endif
            <flux:table.column class="w-28">Rekorde</flux:table.column>
            <flux:table.column class="w-16">Status</flux:table.column>
            <flux:table.column class="w-20">Herkunft</flux:table.column>
            <flux:table.column class="w-28"></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach($guests as $guest)
                <flux:table.row>
                    <flux:table.cell class="text-sm text-zinc-500 dark:text-zinc-400">Gast</flux:table.cell>
                    <flux:table.cell class="font-medium text-zinc-900 dark:text-white truncate" title="{{ $guest->display_name }}">
                        {{ $guest->display_name }}
                        @if($guest->birth_date)
                            <span class="font-normal text-zinc-400">({{ $guest->birth_date->year }})</span>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="text-sm text-zinc-500 dark:text-zinc-400 truncate" title="{{ $guest->club_label }}">
                        {{ $guest->club_label }}
                    </flux:table.cell>
                    <flux:table.cell>
                        @if($guest->sport_class)
                            <flux:badge size="sm">{{ $guest->sport_class }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="font-mono text-sm font-semibold text-zinc-900 dark:text-white">
                        {{ $guest->formatted_swim_time ?? '—' }}
                    </flux:table.cell>
                    @if($pointColumns['points'])
                        <flux:table.cell class="text-sm text-zinc-500 dark:text-zinc-400">–</flux:table.cell>
                    @endif
                    @if($pointColumns['wps'])
                        <flux:table.cell class="text-sm text-zinc-500 dark:text-zinc-400">–</flux:table.cell>
                    @endif
                    <flux:table.cell></flux:table.cell>
                    <flux:table.cell>
                        @if($guest->status)
                            <flux:badge size="sm" color="{{ in_array($guest->status, ['DSQ', 'DNS', 'DNF']) ? 'red' : 'zinc' }}">
                                {{ $guest->status === 'EXH' ? 'AK' : $guest->status }}
                            </flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="text-xs text-zinc-500 dark:text-zinc-400">LENEX</flux:table.cell>
                    <flux:table.cell></flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</div>
