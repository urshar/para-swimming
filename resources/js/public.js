import Alpine from 'alpinejs';
import IMask from 'imask';
import theme from './theme';
import mobileNav from './mobile-nav';
import baseTimeTabs from './base-time-tabs';
import baseTimeMobileClass from './base-time-mobile-class';
import pointCalculator from './point-calculator';
import navDropdown from './nav-dropdown';
import tableSearch from './table-search';
import rankingFilter from './ranking-filter';

// Eigener Vite-Entry ohne Livewire (Spec public-frontend §3.1): Anders als in app.js gibt es
// hier keine bereits gestartete Alpine-Instanz, an die angedockt werden könnte — Alpine muss
// also selbst gestartet werden. Das steht im Gegensatz zu CLAUDE.md's Hinweis auf app.js, der
// genau diesen Fall betrifft: eine zweite Instanz neben der von Livewire mitgebrachten. Da der
// öffentliche Bereich kein Livewire lädt, entfällt der Konflikt.
window.Alpine = Alpine;

// x-init="IMask($el, …)" im Punkterechner-Formular greift auf einen globalen Bezeichner zu (die
// Inline-Ausdrücke von Alpine sind Strings, sehen also keine Modul-Imports) — ohne diese Zeile
// bleibt IMask im öffentlichen Bundle undefined und das Zeitfeld unmaskiert (Fund aus der
// Rückmeldung: "wenn die Trennzeichen nicht eingegeben wurden, macht er gar nichts"). app.js
// macht dasselbe für den internen Bereich, siehe dort.
window.IMask = IMask;

document.addEventListener('alpine:init', () => {
    Alpine.data('theme', theme);
    Alpine.data('mobileNav', mobileNav);
    Alpine.data('baseTimeTabs', baseTimeTabs);
    Alpine.data('baseTimeMobileClass', baseTimeMobileClass);
    Alpine.data('pointCalculator', pointCalculator);
    Alpine.data('navDropdown', navDropdown);
    Alpine.data('tableSearch', tableSearch);
    Alpine.data('rankingFilter', rankingFilter);
});

Alpine.start();
