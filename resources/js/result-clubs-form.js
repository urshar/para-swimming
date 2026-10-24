/**
 * Alpine-Komponente für die Seite "Vereine korrigieren" eines Wettkampfs (resources/views/meets/result-clubs.blade.php).
 *
 * Hält Athlet und Verein des Formulars "Verein für alle Ergebnisse eines Athleten setzen". Der Knopf "Anderer
 * Verein" einer Tabellenzeile übernimmt deren Athleten (pick()) und springt zum Formular.
 *
 * Registrierung in resources/js/app.js:
 *   import resultClubsForm from './result-clubs-form'
 *   Alpine.data('resultClubsForm', resultClubsForm)
 */
export default function resultClubsForm(config) {
    return {
        athleteId: config.athleteId,
        clubId: config.clubId,

        pick(athleteId, clubId) {
            this.athleteId = String(athleteId);
            this.clubId = clubId ? String(clubId) : '';
            this.$refs.form.scrollIntoView({behavior: 'smooth', block: 'start'});
        },
    };
}
