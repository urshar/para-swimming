/**
 * Alpine-Komponente für das Einzelergebnis-Formular (resources/views/results/form.blade.php).
 *
 * - Anlegen: belegt beim Athleten-Wechsel den Verein vor (athleteClubMap, bleibt änderbar) und setzt für
 *   Meldungen außer Konkurrenz den Status EXH (exhibitionKeys, Schlüssel "Bewerb-Athlet").
 * - Splitzeiten: belegt die Distanzfelder mit den vorgeschlagenen Distanzen des gewählten Bewerbs vor
 *   (splitDistances, Bewerbs-ID → Distanzen). Beim Bewerbswechsel werden sie nur ausgetauscht, solange sie
 *   noch dem Vorschlag des bisherigen Bewerbs entsprechen (oder leer sind) — eigene Eingaben bleiben stehen.
 *
 * Registrierung in resources/js/app.js:
 *   import resultForm from './result-form'
 *   Alpine.data('resultForm', resultForm)
 */
export default function resultForm(config) {
    return {
        athleteId: config.athleteId,
        clubId: config.clubId,
        eventId: config.eventId,
        status: config.status,
        athleteClubMap: config.athleteClubMap,
        exhibitionKeys: config.exhibitionKeys,
        splitDistances: config.splitDistances,
        distances: config.distances,

        init() {
            this.$watch('eventId', (id, previousId) => {
                this.prefillExhibition();
                this.replaceSplitDistances(previousId, id);
            });
            if (config.isEdit) {
                return;
            }
            this.$watch('athleteId', (id) => {
                if (this.athleteClubMap[id]) {
                    this.clubId = String(this.athleteClubMap[id]);
                }
                this.prefillExhibition();
            });
        },

        prefillExhibition() {
            if (this.exhibitionKeys.includes(`${this.eventId}-${this.athleteId}`)) {
                this.status = 'EXH';
            }
        },

        suggestedDistances(id) {
            return (this.splitDistances[id] ?? []).map(String);
        },

        replaceSplitDistances(previousId, id) {
            const previous = this.suggestedDistances(previousId);
            const untouched = this.distances.every((value, index) => value === '' || value === (previous[index] ?? ''));
            if (!untouched) {
                return;
            }
            const next = this.suggestedDistances(id);
            this.distances = this.distances.map((value, index) => next[index] ?? '');
        },
    };
}
