/**
 * Alpine.js Komponente für den Filter der Top-Gruppen-Einstufungsliste
 * (cups/top-group/index.blade.php). Rein client-seitig: alle Zeilen sind
 * serverseitig gerendert, der Filter blendet sie nur per x-show ein/aus.
 *
 * Registrierung in resources/js/app.js:
 *   import topGroupFilter from './top-group-filter'
 *   Alpine.data('topGroupFilter', topGroupFilter)
 *
 * Verwendung in Blade:
 *   x-data="topGroupFilter"  ·  x-show="matches(true, false)" (Top-Gruppe?, von Hand?)
 */
export default function topGroupFilter() {
    return {
        filter: 'ALL',

        matches(isTopGroup, isManual) {
            switch (this.filter) {
                case 'TOP':
                    return isTopGroup;
                case 'NOT_TOP':
                    return !isTopGroup;
                case 'MANUAL':
                    return isManual;
                default:
                    return true;
            }
        },
    };
}
