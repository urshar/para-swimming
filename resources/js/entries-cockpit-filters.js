/**
 * Alpine.js Komponente für die Filterleiste im Meldungen-Admin-Cockpit
 * (entries/index.blade.php).
 *
 * Jedes Feld löst bei Änderung sofort eine neue Suche aus (kein Filtern-Button).
 * Die Selects laufen über x-model + $watch statt onchange="this.form.submit()":
 * das interne "change"-Event von flux:select (Custom Element <ui-select>) feuert
 * mit bubbles:false, ein @change kam im Test nicht zuverlässig an (siehe CLAUDE.md).
 * Die Suche ist ein natives <input> und läuft über x-model.debounce, damit nicht
 * bei jedem Tastendruck abgesendet wird.
 *
 * Registrierung in resources/js/app.js:
 *   import entriesCockpitFilters from './entries-cockpit-filters'
 *   Alpine.data('entriesCockpitFilters', entriesCockpitFilters)
 *
 * Verwendung in Blade:
 *   x-data="entriesCockpitFilters(@js($filterConfig))"
 */
export default function entriesCockpitFilters(config) {
    return {
        meetFilter: config.meet ?? '',
        searchFilter: config.search ?? '',
        statusFilter: config.status ?? '',
        problemFilter: config.problem ?? '',

        init() {
            this.$watch('meetFilter', () => this.$el.submit());
            this.$watch('statusFilter', () => this.$el.submit());
            this.$watch('problemFilter', () => this.$el.submit());
            this.$watch('searchFilter', () => this.$el.submit());
        },
    };
}
