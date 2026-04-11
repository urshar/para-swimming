{{--
    Partial: records/check-result.blade.php
    Einbinden in meets/show.blade.php:

        @if(session('record_check_result'))
            @include('records.check-result', ['checkResult' => session('record_check_result')])
        @endif

    Erwartet $checkResult (serialisierte IDs aus dem Controller):
        [
            'checked'            => 42,
            'new_record_ids'     => [['id' => 1, 'types' => ['AUT', 'AUT.WBSV']], ...],
            'pending_record_ids' => [['id' => 5, 'athlete_name' => '...'], ...],
        ]
--}}

@php
    use App\Models\SwimRecord;

    $newRecordIds     = $checkResult['new_record_ids'] ?? [];
    $pendingRecordIds = $checkResult['pending_record_ids'] ?? [];
    $checked          = $checkResult['checked'] ?? 0;

    // Alle benötigten Rekorde in einer Query laden
    $allIds     = array_merge(
        array_column($newRecordIds, 'id'),
        array_column($pendingRecordIds, 'id'),
    );
    $recordsMap = SwimRecord::with(['strokeType', 'athlete'])
        ->whereIn('id', $allIds)
        ->get()
        ->keyBy('id');

    $totalNew     = count($newRecordIds);
    $totalPending = count($pendingRecordIds);
@endphp

<div class="mt-6 space-y-4">

    {{-- ── Zusammenfassung ─────────────────────────────────────────────────── --}}
    <div
        class="flex flex-wrap items-center gap-3 p-4 bg-zinc-50 dark:bg-zinc-900/50 rounded-xl border border-zinc-200 dark:border-zinc-700">
        <flux:icon.check-circle class="size-5 text-green-500 shrink-0"/>
        <span class="text-sm text-zinc-700 dark:text-zinc-300">
            <strong>{{ $checked }}</strong> Ergebnisse geprüft
        </span>
        @if($totalNew > 0)
            <flux:badge color="green" size="sm">{{ $totalNew }} neue {{ Str::plural('Rekord', $totalNew) }}</flux:badge>
        @else
            <flux:badge color="zinc" size="sm">Keine neuen Rekorde</flux:badge>
        @endif
        @if($totalPending > 0)
            <flux:badge color="amber" size="sm">{{ $totalPending }} ausstehend</flux:badge>
        @endif
    </div>

    {{-- ── Neue Rekorde ─────────────────────────────────────────────────────── --}}
    @if($totalNew > 0)
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-green-200 dark:border-green-800 overflow-hidden">
            <div
                class="px-4 py-3 bg-green-50 dark:bg-green-950/30 border-b border-green-200 dark:border-green-800 flex items-center gap-2">
                <flux:icon.trophy class="size-4 text-green-600"/>
                <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Neue Rekorde</h3>
            </div>

            <div class="divide-y divide-zinc-100 dark:divide-zinc-700">
                @foreach($newRecordIds as $item)
                    @php
                        $record = $recordsMap[$item['id']] ?? null;
                        $types  = $item['types'];
                    @endphp
                    @if($record)
                        <div class="flex items-center justify-between px-4 py-3 text-sm">
                            <div class="flex items-center gap-2 flex-wrap">
                                @foreach($types as $type)
                                    <flux:badge size="sm"
                                                color="{{ str_contains($type, '.JR') ? 'violet' : (str_contains($type, 'AUT.') && strlen($type) > 5 ? 'teal' : 'blue') }}">
                                        {{ $type }}
                                    </flux:badge>
                                @endforeach
                                <flux:badge size="sm" color="blue">{{ $record->sport_class }}</flux:badge>
                                <span class="text-zinc-500">{{ $record->gender === 'F' ? '♀' : '♂' }}</span>
                                <span class="text-zinc-700 dark:text-zinc-300">
                                    {{ $record->distance }}m
                                    {{ $record->strokeType?->name_de }}
                                    <span class="text-zinc-400">· {{ $record->course }}</span>
                                </span>
                            </div>
                            <div class="flex items-center gap-3 shrink-0 ml-4">
                                <span class="font-mono font-bold text-zinc-900 dark:text-zinc-100">
                                    {{ $record->formatted_swim_time }}
                                </span>
                                <span class="text-zinc-500 text-xs">
                                    {{ $record->athlete?->display_name ?? 'Staffel' }}
                                </span>
                                <flux:button href="{{ route('records.show', $record) }}"
                                             size="sm" variant="ghost" icon="eye"/>
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    @endif

    {{-- ── Ausstehende Rekorde (Nationalität nicht hinterlegt) ────────────── --}}
    @if($totalPending > 0)
        <div class="bg-white dark:bg-zinc-800 rounded-xl border border-amber-300 dark:border-amber-700 overflow-hidden">
            <div
                class="px-4 py-3 bg-amber-50 dark:bg-amber-950/30 border-b border-amber-300 dark:border-amber-700 flex items-center gap-2">
                <flux:icon.question-mark-circle class="size-4 text-amber-600"/>
                <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Ausstehende Rekorde</h3>
                <span class="text-xs text-zinc-500">— Nationalität des Athleten nicht hinterlegt</span>
            </div>

            <div class="divide-y divide-zinc-100 dark:divide-zinc-700">
                @foreach($pendingRecordIds as $item)
                    @php $record = $recordsMap[$item['id']] ?? null; @endphp
                    @if($record)
                        <div class="flex items-center justify-between px-4 py-3 text-sm">
                            <div class="flex items-center gap-2 flex-wrap">
                                <flux:badge size="sm" color="amber">PENDING</flux:badge>
                                <flux:badge size="sm" color="blue">{{ $record->sport_class }}</flux:badge>
                                <span class="text-zinc-500">{{ $record->gender === 'F' ? '♀' : '♂' }}</span>
                                <span class="text-zinc-700 dark:text-zinc-300">
                                    {{ $record->distance }}m
                                    {{ $record->strokeType?->name_de }}
                                    <span class="text-zinc-400">· {{ $record->course }}</span>
                                </span>
                            </div>
                            <div class="flex items-center gap-3 shrink-0 ml-4">
                                <span class="font-mono font-bold text-zinc-900 dark:text-zinc-100">
                                    {{ $record->formatted_swim_time }}
                                </span>
                                <span class="text-zinc-500 text-xs">{{ $item['athlete_name'] }}</span>
                                <flux:button href="{{ route('records.edit', $record) }}"
                                             size="sm" variant="ghost" icon="pencil"
                                             title="Athleten-Nationalität hinterlegen"/>
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>

            <div class="px-4 py-3 bg-amber-50 dark:bg-amber-950/20 border-t border-amber-200 dark:border-amber-800">
                <p class="text-xs text-amber-700 dark:text-amber-400">
                    Bitte Nationalität der Athleten hinterlegen und Rekorde danach manuell bestätigen (Status → APPROVED
                    setzen).
                </p>
            </div>
        </div>
    @endif

</div>
