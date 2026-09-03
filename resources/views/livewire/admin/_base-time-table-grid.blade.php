@php use App\Models\BaseTime; @endphp

{{-- Eine Tabellen-Spalten-Gruppe (Chunk von max. 10 Sportklassen). Ausgelagert aus
     base-time-table.blade.php, damit dieselbe Tabelle sowohl direkt (wenige Sportklassen) als auch
     je Tab-Panel (viele Sportklassen, siehe dort) gerendert werden kann, ohne Markup zu duplizieren.
     $chunkIndex macht wire:key je Zeile über alle Panels hinweg eindeutig (dieselbe Discipline-ID
     kommt sonst in mehreren Panels vor — Livewire-Morph würde die Keys sonst verwechseln). --}}
<div class="bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-x-auto">
    <table class="text-sm border-collapse">
        <thead>
        <tr class="border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900/40">
            <th class="sticky left-0 bg-zinc-50 dark:bg-zinc-900/40 text-left px-3 py-2 font-medium text-zinc-600 dark:text-zinc-400 min-w-25">
                Bewerb
            </th>
            @foreach($sportClasses as $sportClass)
                <th class="px-2 py-2 font-medium text-zinc-600 dark:text-zinc-400 text-center min-w-22.5">
                    {{ $sportClass->code }}
                </th>
            @endforeach
        </tr>
        </thead>
        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700/50">
        @foreach($disciplines as $discipline)
            <tr wire:key="discipline-{{ $discipline->id }}-{{ $chunkIndex }}">
                <td class="sticky left-0 bg-white dark:bg-zinc-800 px-3 py-1.5 font-mono text-xs text-zinc-500 whitespace-nowrap">
                    {{ $discipline->code }}
                </td>
                @foreach($sportClasses as $sportClass)
                    @php
                        $type = $cellTypes[$discipline->id][$sportClass->id] ?? null;
                        $errorKey = "cells.$discipline->id.$sportClass->id";
                    @endphp
                    <td class="px-1.5 py-1 text-center">
                        @if($type === null)
                            {{-- Kombination existiert nicht in dieser Kategorie --}}
                        @elseif($type === BaseTime::TYPE_NOT_APPLICABLE)
                            <span class="text-zinc-300 dark:text-zinc-600">–</span>
                        @elseif($type === BaseTime::TYPE_CALCULATED)
                            <span class="font-mono text-xs text-orange-600 dark:text-orange-400 italic"
                                  title="Automatisch berechnet — nicht editierbar">
                                    {{ $cells[$discipline->id][$sportClass->id] ?? '' }}
                                </span>
                        @else
                            <flux:input
                                wire:key="input-{{ $discipline->id }}-{{ $sportClass->id }}"
                                wire:model.blur="cells.{{ $discipline->id }}.{{ $sportClass->id }}"
                                size="sm"
                                class="w-24 font-mono text-xs text-center"
                            />
                            @error($errorKey)
                            <span class="block text-red-500 text-xs mt-0.5">{{ $message }}</span>
                            @enderror
                        @endif
                    </td>
                @endforeach
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
