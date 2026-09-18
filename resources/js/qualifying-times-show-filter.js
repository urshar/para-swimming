/**
 * Alpine.js Komponente für die Filterleiste auf qualifying-time-lists/show.blade.php.
 *
 * Rein deklarativ (wie resources/views/cups/overall-ranking.blade.php): Geschlecht und
 * Sportklassen-Nummer sind einfache Zustandswerte, gegen die jede Zeile bzw. jeder
 * Sportklassen-Abschnitt direkt per x-show im Blade-Template geprüft wird — kein
 * DOM-Querying, keine imperative Sichtbarkeits-Logik hier nötig.
 *
 * "ALL" statt "" als Sentinel für die Sportklassen-Auswahl, da flux:select.option mit
 * value="" nicht zuverlässig funktioniert (siehe CLAUDE.md, "<flux:select.option value=''>").
 *
 * Erik, 17.09.2026 (Design-Feedback): Akkordeon durch reines Ein-/Ausblenden ersetzt, da
 * ohnehin jeder Abschnitt bereits per `expanded` offen war — das Akkordeon lieferte keinen
 * Mehrwert mehr, nur zusätzlichen, mit der Sportklassen-Auswahl redundanten Zustand.
 *
 * Registrierung in resources/js/app.js:
 *   import qualifyingTimesShowFilter from './qualifying-times-show-filter'
 *   Alpine.data('qualifyingTimesShowFilter', qualifyingTimesShowFilter)
 *
 * Verwendung in Blade:
 *   x-data="qualifyingTimesShowFilter()"
 */
export default function qualifyingTimesShowFilter() {
    return {
        gender: '',
        selectedNumber: 'ALL',
    };
}
