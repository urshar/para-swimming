{{--
    x-select-chevron — eigenes Pfeil-Icon für native <select>-Felder im öffentlichen Bereich.

    Tailkits Select-Snippet (a-c-form-elements-03) reserviert mit pr-10 Platz für ein Icon, liefert
    aber keins mit — der native Browser-Pfeil sitzt dadurch ungestylt direkt am Rand der Box
    (Rückmeldung: "das Icon ist sehr knapp am Rand"). appearance-none auf dem <select> blendet den
    nativen Pfeil aus, dieses Icon füllt den reservierten Platz stattdessen. Elternelement des
    <select> braucht `relative`. Derselbe Pfeil-Pfad wie das Untermenü-Chevron im Kopfbereich
    (layouts/public.blade.php), für ein einheitliches Erscheinungsbild.
--}}
<svg class="pointer-events-none absolute inset-e-3 top-1/2 h-3 w-3 -translate-y-1/2 text-gray-400 dark:text-gray-500"
     viewBox="0 0 12 12" fill="none" aria-hidden="true">
    <path d="M2.5 4.5L6 8l3.5-3.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
</svg>
