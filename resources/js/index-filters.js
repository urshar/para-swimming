/**
 * Generische Alpine.js Komponente für die Auto-Submit-Filterleisten der Admin-Index-Seiten
 * (athletes, clubs, classifiers, results, meets).
 *
 * Jedes Feld löst bei Änderung sofort eine neue Suche aus (kein "Filtern"-Button). Die Selects
 * laufen über x-model + $watch statt onchange="this.form.submit()": das interne "change"-Event
 * von flux:select (Custom Element <ui-select>) feuert mit bubbles:false, ein @change kam im Test
 * nicht zuverlässig an (siehe CLAUDE.md). Text-/Suchfelder sind native <input> und werden in der
 * View über x-model.debounce gebunden, damit nicht bei jedem Tastendruck abgesendet wird.
 *
 * Anders als das feste entriesCockpitFilters ist diese Komponente feldunabhängig: die Keys der
 * übergebenen Config werden als reaktive Zustandsfelder gespreizt und je Key ein $watch registriert.
 * So teilen sich alle fünf Index-Seiten dieselbe Komponente, obwohl sie unterschiedliche Filter haben.
 *
 * Abgeschickt wird nicht per $el.submit() (Seite lädt neu, Suchfeld verliert den Fokus), sondern über
 * refreshFilterResults(): die Liste wird ohne Neuladen ausgetauscht, siehe filter-refresh.js. Die View
 * markiert dafür die vom Filter abhängigen Bereiche mit data-filter-region. Enter im Suchfeld läuft
 * über denselben Weg.
 *
 * Registrierung in resources/js/app.js:
 *   import indexFilters from './index-filters'
 *   Alpine.data('indexFilters', indexFilters)
 *
 * Verwendung in Blade (einfach anführen + @json, siehe CLAUDE.md):
 *   x-data='indexFilters(@json($filterConfig))'
 *   <flux:select name="gender" x-model="gender" ...>
 *   <flux:input name="search" x-model.debounce.500ms="search" ...>
 */
import refreshFilterResults from './filter-refresh';

export default function indexFilters(config) {
    return {
        // Config-Keys als reaktive Felder übernehmen (z.B. search, gender, nation_id, ...).
        ...config,

        init() {
            // Je übergebenem Filterfeld ein $watch → Auto-Submit. 'init' selbst steckt nie in der
            // Config (das sind reine Request-Feldnamen), also keine Kollision mit dieser Methode.
            Object.keys(config).forEach((key) => {
                this.$watch(key, () => refreshFilterResults(this.$el));
            });

            this.$el.addEventListener('submit', (event) => {
                event.preventDefault();
                refreshFilterResults(this.$el);
            });
        },
    };
}
