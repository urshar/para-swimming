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
        // Einzelbewerbe und Staffeln getrennt: Staffeln haben nur für Staffel-Sportklassen (S14, S15,
        // S20, S21, S34, S49) Werte und erschienen in den Einzel-Tabs sonst als leere, schmälere
        // Zeilen (Erik, 2026-09-18). Einzel-Spalten weiter in 10er-Blöcken (Bildschirmbreite),
        // Staffeln in einem eigenen Tab (nur ~6 Spalten, kein Chunking).
        $individualChunks = $individualSportClasses->chunk(10)->values();
        $hasRelay = $relayDisciplines->isNotEmpty() && $relaySportClasses->isNotEmpty();
        $panelCount = $individualChunks->count() + ($hasRelay ? 1 : 0);
    @endphp

    @if($panelCount > 1)
        <flux:tab.group>
            <flux:tabs>
                {{-- Einzel-Tabs: Label aus den echten Sportklassen-Codes (erster…letzter), "…" statt
                     "–", da nicht lückenlos (z.B. OeBSV 2021 ohne S16–S19). --}}
                @foreach($individualChunks as $i => $chunk)
                    <flux:tab name="cols-{{ $i }}">{{ $chunk->first()->code === $chunk->last()->code ? $chunk->first()->code : $chunk->first()->code.'…'.$chunk->last()->code }}</flux:tab>
                @endforeach
                @if($hasRelay)
                    <flux:tab name="relay">Staffeln</flux:tab>
                @endif
            </flux:tabs>

            @foreach($individualChunks as $i => $chunk)
                <flux:tab.panel name="cols-{{ $i }}">
                    @include('livewire.admin._base-time-table-grid', ['disciplines' => $individualDisciplines, 'sportClasses' => $chunk, 'chunkIndex' => $i])
                </flux:tab.panel>
            @endforeach
            @if($hasRelay)
                <flux:tab.panel name="relay">
                    @include('livewire.admin._base-time-table-grid', ['disciplines' => $relayDisciplines, 'sportClasses' => $relaySportClasses, 'chunkIndex' => 'relay'])
                </flux:tab.panel>
            @endif
        </flux:tab.group>
    @elseif($hasRelay && $individualChunks->isEmpty())
        {{-- Nur Staffeln vorhanden --}}
        @include('livewire.admin._base-time-table-grid', ['disciplines' => $relayDisciplines, 'sportClasses' => $relaySportClasses, 'chunkIndex' => 'relay'])
    @else
        {{-- Nur Einzelbewerbe (≤10 Klassen): eine Tabelle ohne Tabs --}}
        @include('livewire.admin._base-time-table-grid', ['disciplines' => $individualDisciplines, 'sportClasses' => $individualChunks->first() ?? collect(), 'chunkIndex' => 0])
    @endif
</div>
