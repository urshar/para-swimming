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
 * Abgeschickt wird ohne Neuladen über refreshFilterResults() (filter-refresh.js), damit das Suchfeld
 * beim Tippen den Fokus behält; die View markiert die betroffenen Bereiche mit data-filter-region.
 *
 * Registrierung in resources/js/app.js:
 *   import entriesCockpitFilters from './entries-cockpit-filters'
 *   Alpine.data('entriesCockpitFilters', entriesCockpitFilters)
 *
 * Verwendung in Blade:
 *   x-data="entriesCockpitFilters(@js($filterConfig))"
 */
import refreshFilterResults from './filter-refresh';

export default function entriesCockpitFilters(config) {
    return {
        meetFilter: config.meet ?? '',
        searchFilter: config.search ?? '',
        statusFilter: config.status ?? '',
        problemFilter: config.problem ?? '',

        init() {
            this.$watch('meetFilter', () => this.submitFilter());
            this.$watch('statusFilter', () => this.submitFilter());
            this.$watch('problemFilter', () => this.submitFilter());
            this.$watch('searchFilter', () => this.submitFilter());

            this.$el.addEventListener('submit', (event) => {
                event.preventDefault();
                this.submitFilter();
            });
        },

        submitFilter() {
            refreshFilterResults(this.$el);
        },
    };
}
