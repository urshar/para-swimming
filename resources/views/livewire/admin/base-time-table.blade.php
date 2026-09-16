@php
    use App\Models\BaseTime;
@endphp

<div>
    <div class="flex items-center justify-between mb-4">
        <div class="flex gap-2 text-xs">
            <flux:badge color="zinc">schwarz = manuell (editierbar)</flux:badge>
            <flux:badge color="orange">orange = automatisch berechnet</flux:badge>
        </div>
        <div class="flex items-center gap-2">
            <flux:button href="{{ route('base-times.export', $version) }}" variant="filled"
                         icon="arrow-down-tray" size="sm" class="text-emerald-500!">
                Exportieren
            </flux:button>
            <flux:button wire:click="recalculate" variant="primary" icon="arrow-path" size="sm"
                         wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="recalculate">Neu berechnen</span>
                <span wire:loading wire:target="recalculate">Berechne…</span>
            </flux:button>
        </div>
    </div>

    @if($recalcMessage)
        <div
            class="mb-4 p-3 bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800 rounded-xl text-sm text-blue-700 dark:text-blue-400">
            {{ $recalcMessage }}
        </div>
    @endif

    @php
        // Bei vielen Sportklassen-Spalten wird die Tabelle breiter als der Bildschirm (Erik,
        // 2026-09-03). Ab mehr als 10 Spalten deshalb in 10er-Blöcken auf Tabs aufteilen
        // ("1–10", "11–20", …), statt alles in eine horizontal scrollende Tabelle zu zwängen.
        $sportClassChunks = $sportClasses->chunk(10)->values();
    @endphp

    @if($sportClassChunks->count() > 1)
        <flux:tab.group>
            <flux:tabs>
                @foreach($sportClassChunks as $i => $chunk)
                    <flux:tab name="cols-{{ $i }}">{{ $i * 10 + 1 }}–{{ $i * 10 + $chunk->count() }}</flux:tab>
                @endforeach
            </flux:tabs>

            @foreach($sportClassChunks as $i => $chunk)
                <flux:tab.panel name="cols-{{ $i }}">
                    @include('livewire.admin._base-time-table-grid', ['sportClasses' => $chunk, 'chunkIndex' => $i])
                </flux:tab.panel>
            @endforeach
        </flux:tab.group>
    @else
        @include('livewire.admin._base-time-table-grid', ['sportClasses' => $sportClasses, 'chunkIndex' => 0])
    @endif
</div>
