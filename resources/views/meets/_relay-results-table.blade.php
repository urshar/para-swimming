{{--
    Staffelergebnisse eines Staffelbewerbs in der Ergebnis-Sammelansicht (meets/results-overview).
    Erwartet: $relayResults (RelayResult mit club und members.athlete), $pointColumns.
--}}
<div class="p-4 [--flux-bleed:1rem]">
    <flux:table bleed>
        <flux:table.columns>
            <flux:table.column>Platz</flux:table.column>
            <flux:table.column>Staffel</flux:table.column>
            <flux:table.column>Wertung</flux:table.column>
            <flux:table.column>Schwimmer</flux:table.column>
            <flux:table.column>Zeit</flux:table.column>
            @if($pointColumns['points'])
                <flux:table.column>Punkte</flux:table.column>
            @endif
            <flux:table.column>Rekorde</flux:table.column>
            <flux:table.column>Status</flux:table.column>
            <flux:table.column>Herkunft</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach($relayResults as $relayResult)
                <flux:table.row>
                    <flux:table.cell class="text-sm text-zinc-500 dark:text-zinc-400 tabular-nums">
                        {{ $relayResult->place ?: '–' }}
                    </flux:table.cell>
                    <flux:table.cell class="font-medium text-zinc-900 dark:text-white">
                        {{ $relayResult->display_name }}
                    </flux:table.cell>
                    <flux:table.cell class="whitespace-nowrap">
                        <flux:badge size="sm" color="{{ $relayResult->gender === 'X' ? 'violet' : 'zinc' }}">{{ $relayResult->gender_label }}</flux:badge>
                        @if($relayResult->relay_class)
                            <flux:badge size="sm" color="blue" class="font-mono">{{ $relayResult->relay_class }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="text-sm text-zinc-600 dark:text-zinc-300">
                        @forelse($relayResult->members as $member)
                            <span class="whitespace-nowrap">
                                <span class="text-zinc-400 font-mono text-xs">{{ $member->position }}.</span>
                                {{ $member->display_name }}@if(! $loop->last),@endif
                            </span>
                        @empty
                            <span class="italic text-zinc-400">keine Schwimmer erfasst</span>
                        @endforelse
                    </flux:table.cell>
                    <flux:table.cell class="font-mono text-sm font-semibold text-zinc-900 dark:text-white">
                        {{ $relayResult->formatted_swim_time }}
                    </flux:table.cell>
                    @if($pointColumns['points'])
                        <flux:table.cell class="text-sm text-zinc-500 dark:text-zinc-400 tabular-nums">
                            {{ $relayResult->points ?? '–' }}
                        </flux:table.cell>
                    @endif
                    <flux:table.cell>
                        <div class="flex gap-1">
                            @if($relayResult->is_national_record)
                                <flux:badge size="sm" color="green">NR</flux:badge>
                            @endif
                            @if($relayResult->is_junior_record)
                                <flux:badge size="sm" color="violet">JR</flux:badge>
                            @endif
                            @if($relayResult->is_regional_record || $relayResult->is_regional_junior_record)
                                <flux:badge size="sm" color="teal">LR</flux:badge>
                            @endif
                        </div>
                    </flux:table.cell>
                    <flux:table.cell>
                        @if($relayResult->status === 'EXH')
                            <flux:badge size="sm" color="violet" title="Außer Konkurrenz">AK</flux:badge>
                        @elseif($relayResult->status)
                            <flux:badge size="sm"
                                        color="{{ in_array($relayResult->status, ['DSQ', 'DNS', 'DNF']) ? 'red' : 'zinc' }}">
                                {{ $relayResult->status }}
                            </flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="text-xs text-zinc-500 dark:text-zinc-400">
                        {{ $relayResult->lenex_result_id ? 'LENEX' : 'manuell' }}
                    </flux:table.cell>
                    <flux:table.cell class="text-right whitespace-nowrap">
                        <flux:button href="{{ route('relay-results.edit', $relayResult) }}" size="xs" variant="ghost"
                                     icon="pencil" class="text-amber-500!" title="Bearbeiten" aria-label="Bearbeiten"/>
                        <form method="POST" action="{{ route('relay-results.destroy', $relayResult) }}" class="inline"
                              x-data="{ submit() { if (confirm('Staffelergebnis löschen?')) this.$el.submit() } }"
                              @submit.prevent="submit()">
                            @csrf @method('DELETE')
                            <flux:button type="submit" size="xs" variant="ghost" icon="trash"
                                         class="text-red-500!" title="Löschen" aria-label="Löschen"/>
                        </form>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</div>
