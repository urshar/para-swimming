{{-- Verlauf aus dem Änderungsprotokoll (Spec club-permissions §4.7), nur für Admins eingebunden.
     Erwartet $history aus App\Support\AuditHistory::build(). --}}
<div id="verlauf" class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 p-5 mb-6">
    <h2 class="font-semibold text-zinc-900 dark:text-zinc-100 mb-4">Verlauf</h2>

    @if($history['entries'] === [])
        <p class="text-sm text-zinc-400">Noch keine protokollierten Änderungen.</p>
    @else
        <ol class="space-y-4">
            @foreach($history['entries'] as $entry)
                <li class="border-s-2 border-zinc-200 dark:border-zinc-600 ps-3">
                    <p class="text-sm text-zinc-900 dark:text-zinc-100">
                        <span class="font-medium">{{ $entry['subject'] }}</span> {{ $entry['event'] }}
                    </p>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                        {{ $entry['at'] }} · {{ $entry['user'] }}@if($entry['source']) · {{ $entry['source'] }}@endif
                    </p>
                    @if($entry['changes'] !== [])
                        <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                            @foreach($entry['changes'] as $change)
                                <dt class="text-zinc-500 dark:text-zinc-400">{{ $change['label'] }}</dt>
                                <dd class="text-zinc-800 dark:text-zinc-200 break-words">
                                    <span class="text-zinc-500 dark:text-zinc-400">{{ $change['old'] }}</span>
                                    <span aria-label="geändert zu">→</span>
                                    {{ $change['new'] }}
                                </dd>
                            @endforeach
                        </dl>
                    @endif
                </li>
            @endforeach
        </ol>

        @if($history['has_more'])
            <div class="mt-4">
                <flux:button size="sm" variant="ghost" href="{{ request()->fullUrlWithQuery(['verlauf' => 'alle']) }}#verlauf">
                    Mehr anzeigen
                </flux:button>
            </div>
        @endif
    @endif
</div>
