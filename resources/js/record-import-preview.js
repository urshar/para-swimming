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
 * Zusätzlich: Suchfeld "Anderer Name" je unbekanntem Athleten (gemeinsame `<datalist>` aller bestehenden
 * Athleten). Die gewählte ID landet in einem versteckten Feld athlete_override[key] und ersetzt serverseitig die
 * Auswahl im Select.
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

        /** Anzeigetext der gemeinsamen Athleten-Suchliste → Athleten-ID. */
        athleteIdsByLabel: {},

        /** Athleten-key → per Suchfeld gewählte Athleten-ID (ersetzt beim Import die Auswahl im Select). */
        athleteOverrides: {},

        init() {
            const config = JSON.parse(this.$el.dataset.config ?? '{}');
            this.clubsById = config.clubsById ?? {};
            this.clubSelections = config.initialSelections ?? {};
            this.athleteIdsByLabel = config.athleteIdsByLabel ?? {};
        },

        /**
         * Suchfeld "Anderer Name": Passt der Text genau auf einen Eintrag der Suchliste, wird dessen ID für diesen
         * unbekannten Athleten gemerkt; sonst (geleert oder nur angetippt) gilt wieder die Auswahl im Select.
         */
        pickAthlete(input) {
            const id = this.athleteIdsByLabel[input.value.trim()];
            this.athleteOverrides[input.dataset.athleteKey] = id !== undefined ? String(id) : '';
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
