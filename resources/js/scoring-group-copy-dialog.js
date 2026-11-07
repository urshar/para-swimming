/**
 * Alpine-Komponente für den Dialog "Wertungsgruppe kopieren" im Bewerbsformular
 * (resources/views/swim-events/form.blade.php). Die Zeilen des Editors (scoringGroupsEditor) öffnen ihn über das
 * Fenster-Event "copy-scoring-group" mit Ziel-URL und Namen der gespeicherten Gruppe.
 *
 * Registrierung in resources/js/app.js:
 *   import scoringGroupCopyDialog from './scoring-group-copy-dialog'
 *   Alpine.data('scoringGroupCopyDialog', scoringGroupCopyDialog)
 */
export default function scoringGroupCopyDialog(config) {
    return {
        targets: config.targets,
        url: '',
        name: '',
        limitsClasses: false,
        selected: [],

        open(detail) {
            this.url = detail.url;
            this.name = detail.name;
            this.limitsClasses = detail.limitsClasses;
            this.selected = [];
            this.$flux.modal('copy-scoring-group').show();
        },

        // Hakt alle Bewerbe mit derselben Klassenkategorie (S/SB/SM) wie der aktuelle Bewerb an.
        selectSameCategory() {
            this.selected = this.targets.filter((target) => target.same_category).map((target) => String(target.id));
        },

        // Ein Bewerb ohne Gruppen ist für alle Klassen offen; die erste Gruppe beschränkt ihn auf deren Klassen.
        restrictsEligibility() {
            return this.limitsClasses && this.targets.some(
                (target) => target.without_groups && this.selected.includes(String(target.id)),
            );
        },
    };
}
