// noinspection JSUnresolvedReference, JSUnresolvedVariable
// Alpine-Magics ($refs) sind der Editor-Analyse unbekannt (kein Typstub für alpinejs).

/**
 * pointCalculator — blendet je nach gewählter Richtung (Zeit→Punkte / Punkte→Zeit) das
 * passende Eingabefeld ein, das jeweils andere aus. Reine Anzeigesache: Das Formular ist ein
 * normales GET-Formular (Spec public-frontend §5.3 — eigene Seite statt Dialog), funktioniert
 * also auch ohne JS, dann stehen beide Felder sichtbar untereinander. Liest den Anfangsmodus aus
 * data-initial-mode statt aus einem x-data-Argument (siehe base-time-tabs.js).
 */
export default function pointCalculator() {
    return {
        mode: null,

        init() {
            this.mode = this.$el.dataset.initialMode;
        },

        showTime() {
            return this.mode === 'time_to_points';
        },

        showPoints() {
            return this.mode === 'points_to_time';
        },
    };
}
