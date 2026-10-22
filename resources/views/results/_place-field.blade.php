{{--
    Platz im Einzel- und Staffelergebnis-Formular. Erwartet: $meet, $placements (ScoringGroupService::placementsOf()),
    $place (gespeicherter Platz oder null).

    Mit "Plätze nicht neu berechnen" an der Veranstaltung (keep_file_places, z. B. WM/EM, bei denen nur die
    österreichischen Schwimmer erfasst sind) wird der Platz eingegeben und von ScoringGroupService::syncPlaces() nicht
    überschrieben. Sonst wird er je Wertungsgruppe berechnet und hier nur angezeigt.
--}}
@if($meet->keep_file_places)
    <flux:field>
        <flux:label>Platz</flux:label>
        <flux:input name="place" type="number" min="1" value="{{ old('place', $place ?? '') }}"/>
        <flux:description class="mt-1!">Wird nicht neu berechnet (Einstellung der Veranstaltung).</flux:description>
        <flux:error name="place"/>
    </flux:field>
@else
    <div>
        <div class="text-sm font-medium text-zinc-800 dark:text-white mb-2">Platz</div>
        @forelse($placements as $placement)
            <div class="text-sm text-zinc-700 dark:text-zinc-300">
                <span class="font-semibold">{{ $placement['place'] ? $placement['place'] . '.' : '–' }}</span>
                <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $placement['label'] }}</span>
            </div>
        @empty
            <div class="text-xs text-zinc-500 dark:text-zinc-400">Wird beim Speichern aus der Wertung berechnet.</div>
        @endforelse
        <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">
            Eigenen Platz eintragen: bei der Veranstaltung "Plätze nicht neu berechnen" einschalten.
        </div>
    </div>
@endif
