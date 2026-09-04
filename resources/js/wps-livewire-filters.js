/**
 * Wiederverwendbare Alpine.js-Komponente für die Live-Filterzeilen der WPS-Auswertungen
 * (wps-rankings.blade.php, wps-club-ranking.blade.php, wps-talent-report.blade.php,
 * wps-athlete-analysis.blade.php).
 *
 * Ursache/Fix wie bei records/index.blade.php und
 * qualifying-time-lists/qualifications.blade.php (siehe CLAUDE.md): flux:select (Custom
 * Element <ui-select>) feuert sein internes "change"-Event mit bubbles:false — ein
 * x-on:change/@change direkt am flux:select kommt nicht zuverlässig an. Das betraf hier auch
 * die direkte Variante x-model="$wire.property": Livewire eigenes $wire-Binding hört
 * letztlich auf dieselben DOM-Events und stößt an dieselbe Custom-Element-Grenze.
 *
 * Fix: x-model auf eine lokale Alpine-Variable, per $watch(...) wird dann die eigentliche
 * Livewire-Methode dynamisch aufgerufen (übernimmt dabei auch die Vorbelegung — ein
 * :selected() auf den flux:select.option-Einträgen wird dadurch überflüssig).
 *
 * Registrierung in resources/js/app.js:
 *   import wpsLivewireFilters from './wps-livewire-filters'
 *   Alpine.data('wpsLivewireFilters', wpsLivewireFilters)
 *
 * Verwendung in Blade (innerhalb einer Livewire-Komponente):
 *   <div x-data="wpsLivewireFilters(@js($initial), 'setFilter')">
 *       <flux:select variant="listbox" x-model="year">...</flux:select>
 *   </div>
 *
 * $initial ist ein assoziatives Array Feldname → aktueller Wert (alle Felder, die diese
 * Filterzeile beobachten soll — auch bedingt gerenderte, siehe jeweilige Blade-Datei). Jedes
 * Feld wird per $watch beobachtet und bei Änderung als $wire.<methode>(feld, wert) aufgerufen
 * — der Name der Whitelist-Methode auf der Komponente (z.B. setFilter()/setInput()).
 */
export default function wpsLivewireFilters(initial, method = 'setFilter') {
    return {
        ...initial,

        init() {
            Object.keys(initial).forEach((feld) => {
                this.$watch(feld, (wert) => this.$wire.call(method, feld, String(wert ?? '')));
            });
        },
    };
}
