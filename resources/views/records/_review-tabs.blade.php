{{-- Reiter der Prüfliste Rekorde als Links (wie auf der Athletenseite): Import-Befunde und ausstehende Rekorde, je mit
     Anzahl der offenen Fälle. Bei schmaler Breite scrollt die Leiste waagrecht statt umzubrechen. --}}
<div class="mb-6 border-b border-zinc-200 dark:border-zinc-700 overflow-x-auto overflow-y-hidden">
    <flux:navbar class="min-w-max" aria-label="Bereiche der Prüfliste">
        <flux:navbar.item :href="route('records.import-review.index')"
                          :current="request()->routeIs('records.import-review.index')"
                          :badge="$openCount > 0 ? $openCount : null">
            Import-Befunde
        </flux:navbar.item>
        <flux:navbar.item :href="route('records.import-review.pending')"
                          :current="request()->routeIs('records.import-review.pending')"
                          :badge="$pendingCount > 0 ? $pendingCount : null">
            Ausstehende Rekorde
        </flux:navbar.item>
    </flux:navbar>
</div>
