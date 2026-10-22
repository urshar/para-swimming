/**
 * Alpine-Komponente für das Veranstaltungsformular (resources/views/meets/form.blade.php).
 *
 * Beim Anlegen wird "Plätze nicht neu berechnen" (keep_file_places) nach der Nation vorbelegt: Bei einer
 * Veranstaltung im Ausland (z. B. WM, EM) sind meist nur die österreichischen Schwimmer erfasst — ein aus ihnen
 * berechneter Platz wäre falsch. Der Schalter bleibt änderbar; beim Bearbeiten wird nichts vorbelegt.
 *
 * Registrierung in resources/js/app.js:
 *   import meetForm from './meet-form'
 *   Alpine.data('meetForm', meetForm)
 */
export default function meetForm(config) {
    return {
        nationId: config.nationId,
        keepFilePlaces: config.keepFilePlaces,

        init() {
            if (config.isEdit) {
                return;
            }
            this.$watch('nationId', (id) => {
                this.keepFilePlaces = String(id) !== String(config.autId);
            });
        },
    };
}
