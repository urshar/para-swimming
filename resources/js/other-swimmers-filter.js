/**
 * Alpine.js Komponente für den Abschnitt "Weitere Schwimmer" auf lenex/select-athletes.blade.php
 * (docs/specs/lenex-athlete-selection-other-swimmers.md).
 *
 * Rein deklarativ wie qualifyingTimesShowFilter: Nation und Verein sind Zustandswerte, gegen die jede Zeile im
 * Blade-Template per x-show geprüft wird. Solange kein Verein gewählt ist, bleibt die Liste leer — bei einem
 * OSV-Meeting wären es sonst hunderte Zeilen. Beide Selects sind `clearable` (leer = alle Nationen bzw. kein Verein);
 * Flux liefert dabei '' oder null, deshalb `nationKey` statt eines direkten Vergleichs. Wechselt die Nation, wird der
 * Verein zurückgesetzt, weil die Vereinsauswahl je Nation eine eigene ist.
 *
 * Ausgefilterte, aber angehakte Schwimmer bleiben angehakt und werden mitgeschickt; `selected` zählt sie für die
 * Überschrift.
 *
 * Registrierung in resources/js/app.js:
 *   import otherSwimmersFilter from './other-swimmers-filter'
 *   Alpine.data('otherSwimmersFilter', otherSwimmersFilter)
 *
 * Verwendung in Blade:
 *   x-data="otherSwimmersFilter()"
 */
export default function otherSwimmersFilter() {
    return {
        nation: '',
        club: '',
        selected: [],

        init() {
            this.$watch('nation', () => {
                this.club = '';
            });
        },

        /** Schlüssel der gewählten Nation wie in otherFilters.clubs ("ALL" = alle Nationen). */
        get nationKey() {
            return this.nation || 'ALL';
        },

        /** Zeile sichtbar: ihr Verein ist gewählt und (bei gewählter Nation) ihre Nation passt. */
        shows(clubKey, nationKey) {
            return !!this.club && this.club === clubKey && (this.nationKey === 'ALL' || this.nationKey === nationKey);
        },
    };
}
