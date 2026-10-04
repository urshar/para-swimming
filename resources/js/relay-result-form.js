/**
 * Alpine-Komponente für das Staffelergebnis-Formular (resources/views/relay-results/form.blade.php).
 *
 * - Zeigt so viele Positionsfelder, wie der gewählte Staffelbewerb Schwimmer hat (relayCounts).
 * - Belegt beim Anlegen die Mitglieder aus der Staffelmeldung desselben Bewerbs und Vereins vor
 *   (entryMembers, Schlüssel "Bewerb-Verein"). Beim Bearbeiten bleibt die gespeicherte Besetzung stehen.
 *
 * Registrierung in resources/js/app.js:
 *   import relayResultForm from './relay-result-form'
 *   Alpine.data('relayResultForm', relayResultForm)
 */
export default function relayResultForm(config) {
    return {
        eventId: config.eventId,
        clubId: config.clubId,
        members: config.members,
        relayCounts: config.relayCounts,
        entryMembers: config.entryMembers,

        init() {
            if (config.isEdit) {
                return;
            }
            this.$watch('eventId', () => this.prefillFromEntry());
            this.$watch('clubId', () => this.prefillFromEntry());
            this.prefillFromEntry();
        },

        positionCount() {
            return this.relayCounts[this.eventId] ?? 4;
        },

        prefillFromEntry() {
            const ids = this.entryMembers[`${this.eventId}-${this.clubId}`];
            if (ids) {
                this.members = this.members.map((current, index) => ids[index] ?? '');
            }
        },
    };
}
