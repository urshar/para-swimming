/**
 * Alpine.js Komponente für die Filterleiste in
 * qualifying-time-lists/qualifications.blade.php.
 *
 * Ausgelagert aus einem inline x-data — PhpStorm erkennt Alpines magische
 * $refs-Eigenschaft innerhalb eines Blade-Attribut-Strings nicht ("Unresolved
 * variable $refs"), in einer echten .js-Datei dagegen problemlos (siehe
 * CLAUDE.md: Alpine-Logik in separate .js-Dateien auslagern reduziert
 * IDE-Warnungen).
 *
 * Registrierung in resources/js/app.js:
 *   import qualificationFilters from './qualification-filters'
 *   Alpine.data('qualificationFilters', qualificationFilters)
 *
 * Verwendung in Blade:
 *   x-data="qualificationFilters(@js($filterConfig))"
 */
export default function qualificationFilters(config) {
    return {
        strokeDistance: config.strokeDistance ?? '',
        genderFilter: config.gender ?? '',
        sportClassFilter: config.sportClass ?? '',
        clubFilter: config.club ?? '',
        searchFilter: config.search ?? '',

        init() {
            this.$watch('strokeDistance', () => this.submitFilter());
            this.$watch('genderFilter', () => this.submitFilter());
            this.$watch('sportClassFilter', () => this.submitFilter());
            this.$watch('clubFilter', () => this.submitFilter());
            this.$watch('searchFilter', () => this.submitFilter());
        },

        submitFilter() {
            const [s, d] = (this.strokeDistance || '').split('|');
            this.$refs.strokeTypeIdField.value = s ?? '';
            this.$refs.distanceField.value = d ?? '';
            this.$el.submit();
        },
    };
}
