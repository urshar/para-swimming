/**
 * recordImportPreview — Rekord-Import-Vorschau (records/import-preview.blade.php).
 *
 * Verknüpft die Vereins-Zuordnung im Abschnitt "Unbekannte Vereine" mit der Namensanzeige
 * im (getrennten) Abschnitt "Unbekannte Athleten": Wird ein unbekannter Verein einem
 * bestehenden zugeordnet, springt der bei den zugehörigen Athleten angezeigte Vereinsname
 * sofort auf den Namen des zugeordneten Vereins um (statt weiter den LENEX-Text zu zeigen).
 *
 * Zustand
 * -------
 * `clubSelections` bildet `club_key → gewählter Select-Wert` ab (`'new'`/`'skip'` oder die ID
 * eines bestehenden Vereins). Jeder Vereins-Select schreibt seinen Wert per x-model in diese
 * Map; jede Athletenzeile liest über athleteClubName() reaktiv daraus ab.
 *
 * Konfiguration über data-config
 * ------------------------------
 * clubsById (ID → Anzeigename der bestehenden Vereine) und initialSelections (Vorbelegung je
 * Vereins-key) stammen aus PHP und kommen über ein data-Attribut — damit x-data reines
 * JavaScript bleibt (siehe standard-cell.js).
 */
export default function recordImportPreview() {
    return {
        /** club_key → gewählter Select-Wert ('new' | 'skip' | Vereins-ID als String). */
        clubSelections: {},

        /** Vereins-ID (String) → Anzeigename der bestehenden Vereine. */
        clubsById: {},

        init() {
            const config = JSON.parse(this.$el.dataset.config ?? '{}');
            this.clubsById = config.clubsById ?? {};
            this.clubSelections = config.initialSelections ?? {};
        },

        /**
         * Anzuzeigender Vereinsname eines unbekannten Athleten: ist der zugehörige Verein
         * einem bestehenden zugeordnet, dessen Name — sonst (Neu anlegen / Überspringen /
         * bereits bekannter Verein) der unveränderte LENEX-Text.
         */
        athleteClubName(clubKey, lenexName) {
            const selected = this.clubSelections[clubKey];

            if (selected !== undefined && Object.prototype.hasOwnProperty.call(this.clubsById, selected)) {
                return this.clubsById[selected];
            }

            return lenexName;
        },
    };
}
