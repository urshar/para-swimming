/**
 * Alpine-Komponente für die Wertungsgruppen im Bewerbsformular (resources/views/swim-events/form.blade.php).
 *
 * Zeilen hinzufügen (Geschlecht vorbelegt aus dem Bewerb), verschieben und entfernen; jede Zeile wird in ihrer
 * aktuellen Reihenfolge als scoring_groups[i][feld] abgeschickt, daraus entsteht beim Speichern sort_order. Die
 * LENEX-ID einer importierten Gruppe läuft als verstecktes Feld mit, damit ein erneuter Import die Gruppe wiedererkennt.
 * Landesverbände und Level sind Listen (Flux-Checkbox-Gruppen), abgeschickt kommagetrennt über versteckte Felder;
 * Geschlecht und Titel sind Flux-Selects (Titel "ohne" = Sentinel NONE, abgeschickt als leerer Wert).
 *
 * uid ist ein fester Schlüssel für x-for: Ohne ihn blieben beim Verschieben die Eingaben an der Position statt an der
 * Gruppe hängen. Er wird nicht abgeschickt.
 *
 * Registrierung in resources/js/app.js:
 *   import scoringGroupsEditor from './scoring-groups-editor'
 *   Alpine.data('scoringGroupsEditor', scoringGroupsEditor)
 */
export default function scoringGroupsEditor(config) {
    return {
        groups: config.groups.map((group, index) => ({ ...group, uid: index + 1 })),
        nextUid: config.groups.length + 1,
        // Ansage für Screenreader nach dem Verschieben (aria-live).
        moveStatus: '',

        addGroup() {
            this.groups.push({
                uid: this.nextUid++,
                name: '',
                gender: this.eventGender(),
                sport_classes: '',
                age_min: '',
                age_max: '',
                regional_associations: [],
                regional_mode: 'only',
                levels: [],
                title: 'NONE',
                lenex_agegroup_id: '',
            });
        },

        // Geschlecht einer neuen Gruppe: das gerade gewählte Geschlecht des Bewerbs (Damen/Herren), sonst "Alle".
        eventGender() {
            const form = this.$root.closest('form');
            const gender = form
                ? (new FormData(form).get('gender') ?? form.querySelector('[name="gender"]')?.value)
                : null;

            return gender === 'M' || gender === 'F' ? gender : 'A';
        },

        removeGroup(index) {
            this.groups.splice(index, 1);
        },

        /**
         * Verschiebt eine Gruppe um eine Position. Der Fokus bleibt am Knopf; ist er danach deaktiviert (Gruppe steht
         * ganz oben oder unten), wandert er zum Gegenstück, damit Tastaturnutzer nicht am Seitenanfang landen.
         */
        moveGroup(index, delta, event) {
            const target = index + delta;
            if (target < 0 || target >= this.groups.length) {
                return;
            }

            const button = event.currentTarget;
            const [group] = this.groups.splice(index, 1);
            this.groups.splice(target, 0, group);
            this.moveStatus = `${group.name || 'Neue Gruppe'}: Position ${target + 1} von ${this.groups.length}`;

            this.$nextTick(() => {
                const focusable = button.disabled
                    ? button.closest('[data-group-row]')?.querySelector('[data-move-button]:not([disabled])')
                    : button;
                focusable?.focus();
            });
        },
    };
}
