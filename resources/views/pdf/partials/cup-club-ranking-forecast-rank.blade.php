{{-- Zelle "Rang jetzt" im PDF der Vereinswertungs-Prognose: aktueller Rang und in Klammern die
     Rangänderung als Kurztext ohne Pfeilsymbole (Helvetica in dompdf kennt sie nicht).
     Erwartet $comparison (?App\Support\ClubForecastComparison). --}}
<td class="change">
    {{ $comparison?->currentRank ?? '–' }}
    @if($comparison)
        ({{ $comparison->rankChangeShort() }})
    @endif
</td>
