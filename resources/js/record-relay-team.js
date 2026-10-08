/**
 * Alpine-Komponente für das Staffelteam im Rekordformular (resources/views/records/form.blade.php).
 *
 * Je Position eine Flux-Listbox (wie Athlet und Verein im Formular). Die Optionen entstehen per x-for im Browser aus
 * der Athletenliste — der Server rendert die Option nur einmal als Vorlage; vier Listboxen mit je allen Athleten als
 * Blade-Komponenten verdreifachten Renderzeit und Seitengröße. Ohne Auswahl bleiben Name und Geburtsdatum zum freien
 * Eintragen.
 *
 * Die Liste zeigt die Athleten des im Formular gewählten Vereins (clubId) oder, mit memberAllClubs, alle — beides aus
 * dem umgebenden x-data des Formulars. Der gewählte Athlet der Position bleibt immer in der Liste, sonst verlöre die
 * Listbox ihre Anzeige.
 *
 * Registrierung in resources/js/app.js:
 *   import recordRelayTeam from './record-relay-team'
 *   Alpine.data('recordRelayTeam', recordRelayTeam)
 */
export default function recordRelayTeam(config) {
    return {
        athletes: config.athletes,
        athleteIds: config.athleteIds,

        options(index) {
            const selected = String(this.athleteIds[index] ?? '');

            return this.athletes.filter((a) => this.memberAllClubs || !this.clubId || a.club === this.clubId
                || String(a.id) === selected);
        },
    };
}
