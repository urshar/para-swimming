/**
 * Alpine.js Komponente für die Filterleiste über der Richtzeiten-Tabelle in
 * qualifying-time-lists/form.blade.php (Tab "Richtzeiten").
 *
 * Filtert rein im DOM (kein Request/Reload) über data-rzt-*-Attribute an den
 * einzelnen Tabellenzeilen — die komplette Liste steht dort ohnehin
 * unpaginiert im Markup. Zeilen ohne Treffer werden ausgeblendet; Stroke-
 * Gruppen bzw. Sportklassen-Sektionen, deren Zeilen dadurch alle unsichtbar
 * werden, werden ebenfalls ausgeblendet (data-rzt-group/data-rzt-section).
 *
 * Registrierung in resources/js/app.js:
 *   import qualifyingTimesFilter from './qualifying-times-filter'
 *   Alpine.data('qualifyingTimesFilter', qualifyingTimesFilter)
 *
 * Verwendung in Blade:
 *   x-data="qualifyingTimesFilter()"
 */
export default function qualifyingTimesFilter() {
    return {
        gender: '',
        sportClass: '',
        strokeTypeId: '',
        distance: '',

        init() {
            this.$watch('gender', () => this.apply());
            this.$watch('sportClass', () => this.apply());
            this.$watch('strokeTypeId', () => this.apply());
            this.$watch('distance', () => this.apply());
        },

        apply() {
            this.$root.querySelectorAll('[data-rzt-row]').forEach((row) => {
                const visible = (!this.gender || row.dataset.rztGender === this.gender)
                    && (!this.sportClass || row.dataset.rztSportClass === this.sportClass)
                    && (!this.strokeTypeId || row.dataset.rztStrokeId === this.strokeTypeId)
                    && (!this.distance || row.dataset.rztDistance === this.distance);
                row.style.display = visible ? '' : 'none';
            });

            this.$root.querySelectorAll('[data-rzt-group]').forEach((group) => {
                const anyVisible = [...group.querySelectorAll('[data-rzt-row]')]
                    .some((row) => row.style.display !== 'none');
                group.style.display = anyVisible ? '' : 'none';
            });

            this.$root.querySelectorAll('[data-rzt-section]').forEach((section) => {
                const anyVisible = [...section.querySelectorAll('[data-rzt-row]')]
                    .some((row) => row.style.display !== 'none');
                section.style.display = anyVisible ? '' : 'none';
            });
        },
    };
}
