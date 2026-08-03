/**
 * Alpine.js Komponente für den Abschnitt "Punkteberechnung" im Wettkampf-Formular.
 *
 * Registrierung in resources/js/app.js:
 *   import meetPointSystems from './meet-point-systems'
 *   Alpine.data('meetPointSystems', meetPointSystems)
 *
 * Verwendung in Blade:
 *   x-data="meetPointSystems({ wpsSelected: true, course: 'LCM' })"
 *
 * Aufgaben:
 *   1. Versionsauswahl nur einblenden, wenn WPS angehakt ist.
 *   2. Kurzbahn-Hinweis einblenden, sobald eine andere Bahnlänge als LCM gewählt ist.
 *
 * Das Kursfeld liegt außerhalb dieses Bereichs im Formular. Statt die bestehende
 * Feldgruppe umzubauen, wird es hier beobachtet — so erscheint der Hinweis sofort beim
 * Umschalten und nicht erst nach dem Speichern.
 */
export default function meetPointSystems(config) {
    return {
        // ── Von PHP initialisiert ─────────────────────────────────────────────
        wps: config.wpsSelected ?? false,
        course: config.course ?? 'LCM',

        init() {
            const courseField = this.$el.closest('form')?.querySelector('[name=course]');

            if (!courseField) {
                return;
            }

            this.course = courseField.value;
            courseField.addEventListener('change', (event) => {
                this.course = event.target.value;
            });
        },

        /** Für Kurzbahn liegen keine offiziellen WPS-Parameter vor. */
        get showEstimatedWarning() {
            return this.wps && this.course !== 'LCM';
        },
    };
}
