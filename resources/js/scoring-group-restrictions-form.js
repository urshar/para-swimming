/**
 * Alpine-Komponente für die Seite "Wertungsgruppen einschränken" einer Veranstaltung
 * (resources/views/meets/scoring-group-restrictions.blade.php).
 *
 * Hält Namensanfang, Modus, Landesverbände und Level des Formulars (Listen für die Flux-Checkbox-Gruppen) und zeigt
 * als Vorschau, welche Gruppennamen der Namensanfang trifft (wie ScoringGroupService::groupsByNamePrefix():
 * Groß-/Kleinschreibung egal).
 *
 * Registrierung in resources/js/app.js:
 *   import scoringGroupRestrictionsForm from './scoring-group-restrictions-form'
 *   Alpine.data('scoringGroupRestrictionsForm', scoringGroupRestrictionsForm)
 */
export default function scoringGroupRestrictionsForm(config) {
    return {
        prefix: config.prefix,
        mode: config.mode,
        associations: config.associations,
        levels: config.levels,
        names: config.names,
        // Sammelaktion "Medaillenwertung": eigener Namensanfang, getrennt von den Einschränkungen.
        medalPrefix: config.medalPrefix,
        awardsMedals: config.awardsMedals,

        matches(name) {
            return this.startsWith(name, this.prefix);
        },

        medalMatches(name) {
            return this.startsWith(name, this.medalPrefix);
        },

        startsWith(name, prefix) {
            const needle = prefix.trim().toLowerCase();

            return needle !== '' && name.toLowerCase().startsWith(needle);
        },

        /** Anzahl der Wertungsgruppen (über alle Bewerbe), die der Namensanfang trifft. */
        matchingCount() {
            return this.countWhere((name) => this.matches(name));
        },

        medalMatchingCount() {
            return this.countWhere((name) => this.medalMatches(name));
        },

        countWhere(predicate) {
            return this.names.filter((row) => predicate(row.name)).reduce((sum, row) => sum + row.count, 0);
        },
    };
}
