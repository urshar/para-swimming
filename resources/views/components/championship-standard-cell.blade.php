@props([
    'standard',
    'field',
    'value' => '',
    'masked' => false,
    'editable' => false,
    'display' => null,
    'width' => 'w-32',
    'placeholder' => '__:__.__',
])

{{--
    Eine bearbeitbare Zelle der Normtabelle.

    Als eigene Komponente, weil dieselbe Zelle viermal je Zeile vorkommt (MQS, MET,
    ÖBSV-Prozentsatz, ÖBSV-Zeit) und sich nur in Feldname, Breite und Maske unterscheidet.
    Als Kopie im Tabellenmarkup wäre jede Korrektur an der Bindung ein Vierfach-Eingriff.

    wire:ignore schützt die IMask-Instanz davor, bei jedem Livewire-Rendern zerstört zu
    werden. Der wire:key enthält deshalb den Wert selbst: Ändert er sich serverseitig — etwa
    durch die Massenaktion —, ändert sich der Schlüssel, und Livewire tauscht das Element als
    Ganzes aus. Ohne das bliebe die Zelle auf dem alten Wert stehen.
--}}
@if($editable)
    <div wire:key="cell-{{ $standard->getKey() }}-{{ $field }}-{{ substr(md5($value), 0, 8) }}"
         x-data="standardCell()"
         data-cell="{{ json_encode([
             'value' => $value,
             'standardId' => $standard->getKey(),
             'field' => $field,
             'masked' => $masked,
         ], JSON_THROW_ON_ERROR) }}"
         wire:ignore>
        <flux:input size="sm" class="{{ $width }} font-mono text-xs"
                    placeholder="{{ $placeholder }}"
                    autocomplete="off"/>
    </div>

    <flux:error name="rows.{{ $standard->getKey() }}.{{ $field }}"/>
@else
    <span class="font-mono text-xs">{{ $display ?? '–' }}</span>
@endif
