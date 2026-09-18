/**
 * Alpine.js Komponente für die Filterleiste über der Richtzeiten-Tabelle in
 * qualifying-time-lists/form.blade.php (Tab "Richtzeiten").
 *
 * Filtert rein im DOM (kein Request/Reload) über data-rzt-*-Attribute an den
 * einzelnen Tabellenzeilen — die komplette Liste steht dort ohnehin
 * unpaginiert im Markup. Zeilen ohne Treffer werden ausgeblendet.
 *
 * Stil/Distanz-Gruppen sind seit der Umstellung auf eine durchgehende Tabelle
 * je Sportklassengruppe (statt einer eigenen Mini-Tabelle je Gruppe) keine
 * umschließenden Container mehr, sondern eine Trennzeile
 * ([data-rzt-group-row]) und ihre Datenzeilen als Geschwister in derselben
 * flux:table.rows — verknüpft über einen gemeinsamen data-rzt-group-Wert
 * ("Sektionsindex-Stilindex", über beide Schleifenebenen hinweg eindeutig,
 * siehe Blade — ein bloßer Stil/Distanz-Laufindex würde sich zwischen
 * Sektionen wiederholen und Zeilen aus verschiedenen Sektionen verknüpfen).
 * Eine Trennzeile wird ausgeblendet, wenn keine ihrer Datenzeilen mehr
 * sichtbar ist. Sektionen
 * (data-rzt-section, eine je Sportklassengruppe) bleiben Container wie
 * bisher.
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

            this.$root.querySelectorAll('[data-rzt-group-row]').forEach((groupRow) => {
                const groupValue = groupRow.dataset.rztGroup;
                const rows = this.$root.querySelectorAll(
                    `[data-rzt-row][data-rzt-group="${groupValue}"]`
                );
                const anyVisible = [...rows].some((row) => row.style.display !== 'none');
                groupRow.style.display = anyVisible ? '' : 'none';
            });

            this.$root.querySelectorAll('[data-rzt-section]').forEach((section) => {
                const anyVisible = [...section.querySelectorAll('[data-rzt-row]')]
                    .some((row) => row.style.display !== 'none');
                section.style.display = anyVisible ? '' : 'none';
            });
        },
    };
}
