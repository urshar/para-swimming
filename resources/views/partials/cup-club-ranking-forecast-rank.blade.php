{{-- Zelle "Rang jetzt" der Vereinswertungs-Prognose: aktueller Rang plus Rangänderung. Die
     Richtung steht als Pfeil + Zahl im Text und ausgeschrieben für Screenreader, nicht nur in der
     Farbe (docs/accessibility.md).
     Erwartet $currentRank (?int) und $change (array{text, label, tone}, siehe
     CupClubRanking::rankChangeDisplay()). --}}
<td class="px-4 py-3 whitespace-nowrap">
    <span class="text-zinc-500 dark:text-zinc-400">{{ $currentRank ?? '–' }}</span>
    <span @class([
        'ms-1 text-xs font-medium',
        'text-green-700 dark:text-green-400' => $change['tone'] === 'up',
        'text-red-700 dark:text-red-400' => $change['tone'] === 'down',
        'text-zinc-500 dark:text-zinc-400' => $change['tone'] === 'neutral',
    ])>
        <span aria-hidden="true">{{ $change['text'] }}</span>
        <span class="sr-only">{{ $change['label'] }}</span>
    </span>
</td>
