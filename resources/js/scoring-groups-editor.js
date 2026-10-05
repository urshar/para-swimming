/**
 * Alpine-Komponente für die Wertungsgruppen im Bewerbsformular (resources/views/swim-events/form.blade.php).
 *
 * Zeilen hinzufügen und entfernen; jede Zeile wird als scoring_groups[i][feld] abgeschickt. Die LENEX-ID einer
 * importierten Gruppe läuft als verstecktes Feld mit, damit ein erneuter Import die Gruppe wiedererkennt.
 *
 * Registrierung in resources/js/app.js:
 *   import scoringGroupsEditor from './scoring-groups-editor'
 *   Alpine.data('scoringGroupsEditor', scoringGroupsEditor)
 */
export default function scoringGroupsEditor(config) {
    return {
        groups: config.groups,

        addGroup() {
            this.groups.push({
                name: '',
                gender: 'A',
                sport_classes: '',
                age_min: '',
                age_max: '',
                title: '',
                lenex_agegroup_id: '',
            });
        },

        removeGroup(index) {
            this.groups.splice(index, 1);
        },
    };
}
